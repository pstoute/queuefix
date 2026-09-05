<?php

namespace App\Services\Email;

use App\Exceptions\AttachmentRejected;
use App\Models\Mailbox;
use App\Services\Attachments\InboundAttachmentPolicy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

class MicrosoftGraphConnector implements InboundEmailConnector
{
    private Mailbox $mailbox;

    private string $baseUrl = 'https://graph.microsoft.com/v1.0';

    private ?string $accessToken = null;

    private InboundAttachmentPolicy $attachmentPolicy;

    private InboundBodyPolicy $bodyPolicy;

    private InboundEmailPollingPolicy $pollingPolicy;

    public function __construct(
        ?InboundAttachmentPolicy $attachmentPolicy = null,
        ?InboundBodyPolicy $bodyPolicy = null,
        ?InboundEmailPollingPolicy $pollingPolicy = null,
    ) {
        $this->attachmentPolicy = $attachmentPolicy ?? new InboundAttachmentPolicy;
        $this->bodyPolicy = $bodyPolicy ?? new InboundBodyPolicy;
        $this->pollingPolicy = $pollingPolicy ?? new InboundEmailPollingPolicy;
    }

    public function connect(Mailbox $mailbox): bool
    {
        $this->mailbox = $mailbox;

        try {
            $this->accessToken = $this->getAccessToken();

            return $this->accessToken !== null;
        } catch (\Throwable $e) {
            Log::error('Microsoft Graph connection error', [
                'mailbox_id' => $mailbox->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function getAccessToken(): ?string
    {
        $token = $this->mailbox->getDecryptedCredential('access_token');
        $refreshToken = $this->mailbox->getDecryptedCredential('refresh_token');
        $expiresAt = $this->mailbox->getDecryptedCredential('token_expires_at');

        if ($token && $expiresAt && now()->timestamp < (int) $expiresAt) {
            return $token;
        }

        if (! $refreshToken) {
            return null;
        }

        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/'.config('services.microsoft_graph.tenant_id', 'common').'/oauth2/v2.0/token',
            [
                'client_id' => config('services.microsoft_graph.client_id'),
                'client_secret' => config('services.microsoft_graph.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'scope' => 'https://graph.microsoft.com/.default offline_access',
            ]
        );

        if ($response->successful()) {
            $data = $response->json();
            $this->mailbox->setEncryptedCredentials([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                'token_expires_at' => (string) (now()->timestamp + $data['expires_in']),
            ]);

            return $data['access_token'];
        }

        Log::error('Microsoft Graph token refresh failed', [
            'mailbox_id' => $this->mailbox->id,
            'response' => $response->body(),
        ]);

        return null;
    }

    public function fetchNewEmailReferences(?\DateTimeInterface $since = null): iterable
    {
        if (! $this->accessToken) {
            return;
        }

        try {
            // Unread state is the retry cursor. A timestamp filter could permanently
            // skip a message whose dispatch or acknowledgement previously failed.
            $filter = 'isRead eq false';

            $response = $this->client()
                ->get("{$this->baseUrl}/me/messages", [
                    '$filter' => $filter,
                    '$top' => $this->pollingPolicy->scanSize(),
                    '$orderby' => 'receivedDateTime desc',
                    '$select' => 'id',
                ]);

            if (! $response->successful()) {
                Log::error('Microsoft Graph fetch error', [
                    'mailbox_id' => $this->mailbox->id,
                    'response' => $response->body(),
                ]);

                return [];
            }

            foreach ($response->json('value', []) as $msg) {
                $messageId = trim((string) ($msg['id'] ?? ''));

                if ($messageId !== '') {
                    yield [
                        'provider_message_id' => 'microsoft:'.$messageId,
                        'provider_remote_id' => $messageId,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error('Microsoft Graph fetch error', [
                'mailbox_id' => $this->mailbox->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function fetchEmail(array $providerReference): array
    {
        if (! $this->accessToken) {
            throw new \RuntimeException('Microsoft Graph is not connected.');
        }

        $messageId = trim((string) ($providerReference['provider_remote_id'] ?? ''));
        $expectedIdentity = trim((string) ($providerReference['provider_message_id'] ?? ''));

        if ($messageId === '' || ! hash_equals('microsoft:'.$messageId, $expectedIdentity)) {
            throw new UnexpectedValueException('Microsoft Graph provider reference is invalid.');
        }

        $encodedMessageId = rawurlencode($messageId);
        $response = $this->client()->get("{$this->baseUrl}/me/messages/{$encodedMessageId}", [
            '$select' => 'id,subject,from,toRecipients,receivedDateTime,internetMessageHeaders,hasAttachments,internetMessageId',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Microsoft Graph message fetch failed.');
        }

        $body = $this->fetchBody($encodedMessageId);

        return $this->parseMessage($response->json(), $body);
    }

    /** @param array{text: ?string, html: ?string}|null $body */
    private function parseMessage(array $msg, ?array $body = null): array
    {
        $providerMessageId = trim((string) ($msg['id'] ?? ''));

        if ($providerMessageId === '') {
            throw new UnexpectedValueException('Graph message is missing an immutable provider identity.');
        }

        $headers = $this->extractInternetHeaders($msg['internetMessageHeaders'] ?? []);
        $attachmentResult = ['attachments' => [], 'rejection' => null];

        if ($msg['hasAttachments'] ?? false) {
            $attachmentResult = $this->fetchAttachments($msg['id']);
        }

        $body ??= $this->bodyPolicy->fromProviderContent(
            $msg['body']['contentType'] ?? null,
            $msg['body']['content'] ?? null,
        );

        $emailData = [
            'provider_message_id' => 'microsoft:'.$providerMessageId,
            'provider_remote_id' => $providerMessageId,
            'from_email' => strtolower($msg['from']['emailAddress']['address'] ?? ''),
            'from_name' => $msg['from']['emailAddress']['name'] ?? null,
            'to_email' => strtolower($msg['toRecipients'][0]['emailAddress']['address'] ?? ''),
            'subject' => $msg['subject'] ?? null,
            'body_text' => $body['text'],
            'body_html' => $body['html'],
            'message_id' => $msg['internetMessageId'] ?? $headers['Message-ID'] ?? null,
            'in_reply_to' => $headers['In-Reply-To'] ?? null,
            'references' => $headers['References'] ?? null,
            'date' => $msg['receivedDateTime'] ?? null,
            'attachments' => $attachmentResult['attachments'],
        ];

        if ($attachmentResult['rejection'] !== null) {
            $emailData['attachment_rejection'] = $attachmentResult['rejection'];
        }

        return $emailData;
    }

    /** @return array{text: ?string, html: ?string} */
    private function fetchBody(string $encodedMessageId): array
    {
        $response = $this->client()
            ->withOptions(['stream' => true])
            ->get("{$this->baseUrl}/me/messages/{$encodedMessageId}", ['$select' => 'body']);

        if (! $response->successful()) {
            throw new \RuntimeException('Microsoft Graph message body fetch failed.');
        }

        $stream = $response->toPsrResponse()->getBody();
        $maxEnvelopeBytes = $this->bodyPolicy->maxGraphEnvelopeBytes();
        $json = '';

        while (! $stream->eof() && strlen($json) <= $maxEnvelopeBytes) {
            $bytesToRead = min(8192, $maxEnvelopeBytes + 1 - strlen($json));
            $chunk = $stream->read($bytesToRead);

            if ($chunk === '') {
                throw new \RuntimeException('Microsoft Graph message body stream stopped before completion.');
            }

            $json .= $chunk;
        }

        if (strlen($json) > $maxEnvelopeBytes) {
            return $this->bodyPolicy->omitted();
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Microsoft Graph message body response was invalid.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new \RuntimeException('Microsoft Graph message body response was invalid.');
        }

        return $this->bodyPolicy->fromProviderContent(
            $payload['body']['contentType'] ?? null,
            $payload['body']['content'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $emailData
     */
    public function acknowledge(array $emailData): bool
    {
        $messageId = trim((string) ($emailData['provider_remote_id'] ?? ''));

        if (! $this->accessToken || $messageId === '') {
            return false;
        }

        try {
            $encodedMessageId = rawurlencode($messageId);

            return $this->client()
                ->patch("{$this->baseUrl}/me/messages/{$encodedMessageId}", ['isRead' => true])
                ->successful();
        } catch (\Throwable $e) {
            Log::warning('Failed to mark Graph message as read', [
                'mailbox_id' => $this->mailbox->id,
                'provider_message_id' => $emailData['provider_message_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function extractInternetHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $header) {
            $result[$header['name']] = $header['value'];
        }

        return $result;
    }

    /**
     * @return array{attachments: list<array{filename: string, content: string, mime_type: string, size: int}>, rejection: ?array{reason_code: string, reported_count: int, reported_bytes: int}}
     */
    private function fetchAttachments(string $messageId): array
    {
        $encodedMessageId = rawurlencode($messageId);
        $response = $this->client()->get("{$this->baseUrl}/me/messages/{$encodedMessageId}/attachments", [
            '$top' => (int) config('attachments.max_files_per_message') + 1,
            '$select' => 'id,name,contentType,size,isInline',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Microsoft Graph attachment metadata fetch failed.');
        }

        $descriptors = [];

        foreach ($response->json('value', []) as $att) {
            if (($att['@odata.type'] ?? '') === '#microsoft.graph.fileAttachment') {
                $descriptors[] = [
                    'id' => (string) ($att['id'] ?? ''),
                    'filename' => $att['name'],
                    'mime_type' => $att['contentType'] ?? 'application/octet-stream',
                    'size' => $att['size'] ?? null,
                ];
            }
        }

        try {
            $responseData = $response->json();
            if (is_array($responseData) && isset($responseData['@odata.nextLink'])) {
                throw new AttachmentRejected('too_many_files', 'The provider returned more attachments than one message may contain.');
            }

            $this->attachmentPolicy->assertMetadata($descriptors, $this->mailbox);
        } catch (AttachmentRejected $exception) {
            return [
                'attachments' => [],
                'rejection' => $this->attachmentPolicy->rejection($exception, $descriptors),
            ];
        }

        $attachments = [];
        $actualBytes = 0;

        try {
            foreach ($descriptors as $descriptor) {
                $attachmentId = trim($descriptor['id']);
                if ($attachmentId === '') {
                    throw new AttachmentRejected('invalid_metadata', 'An attachment did not include a stable provider identity.');
                }

                $encodedAttachmentId = rawurlencode($attachmentId);
                $contentResponse = $this->client()->withOptions(['stream' => true])->get(
                    "{$this->baseUrl}/me/messages/{$encodedMessageId}/attachments/{$encodedAttachmentId}/\$value",
                );

                if (! $contentResponse->successful()) {
                    throw new \RuntimeException('Microsoft Graph attachment content fetch failed.');
                }

                $content = $this->readBoundedContent($contentResponse, $actualBytes);
                $attachments[] = [
                    'filename' => (string) $descriptor['filename'],
                    'content' => $content,
                    'mime_type' => (string) $descriptor['mime_type'],
                    'size' => strlen($content),
                ];
            }
        } catch (AttachmentRejected $exception) {
            return [
                'attachments' => [],
                'rejection' => $this->attachmentPolicy->rejection($exception, $descriptors),
            ];
        }

        return ['attachments' => $attachments, 'rejection' => null];
    }

    private function readBoundedContent(Response $response, int &$actualBytes): string
    {
        $remainingMessageBytes = max(0, (int) config('attachments.max_message_bytes') - $actualBytes);
        $readLimit = min((int) config('attachments.max_file_bytes'), $remainingMessageBytes);
        $stream = $response->toPsrResponse()->getBody();
        $content = '';

        while (! $stream->eof() && strlen($content) <= $readLimit) {
            $bytesToRead = min(8192, $readLimit + 1 - strlen($content));
            $chunk = $stream->read($bytesToRead);

            if ($chunk === '') {
                throw new \RuntimeException('Microsoft Graph attachment stream stopped before completion.');
            }

            $content .= $chunk;
        }

        $this->attachmentPolicy->assertContent($content, $actualBytes);

        return $content;
    }

    public function sendEmail(array $data): bool
    {
        if (! $this->accessToken) {
            return false;
        }

        try {
            $body = [
                'message' => [
                    'subject' => $data['subject'],
                    'body' => [
                        'contentType' => ! empty($data['html']) ? 'HTML' : 'Text',
                        'content' => $data['html'] ?? $data['text'] ?? '',
                    ],
                    'toRecipients' => [
                        [
                            'emailAddress' => [
                                'address' => $data['to'],
                            ],
                        ],
                    ],
                ],
                'saveToSentItems' => true,
            ];

            if (! empty($data['reply_to'])) {
                $body['message']['replyTo'] = [
                    [
                        'emailAddress' => [
                            'address' => $data['reply_to'],
                        ],
                    ],
                ];
            }

            if (! empty($data['headers'])) {
                $internetHeaders = [];
                foreach ($data['headers'] as $name => $value) {
                    $internetHeaders[] = [
                        'name' => $name,
                        'value' => $value,
                    ];
                }
                $body['message']['internetMessageHeaders'] = $internetHeaders;
            }

            $response = $this->client()
                ->post("{$this->baseUrl}/me/sendMail", $body);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Microsoft Graph send error', [
                'mailbox_id' => $this->mailbox->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function testConnection(Mailbox $mailbox): array
    {
        $connected = $this->connect($mailbox);

        if ($connected) {
            try {
                $response = $this->client()
                    ->get("{$this->baseUrl}/me");

                if ($response->successful()) {
                    return ['success' => true, 'message' => 'Microsoft Graph connection successful'];
                }

                return ['success' => false, 'message' => 'API call failed: '.$response->body()];
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        return ['success' => false, 'message' => 'Failed to connect to Microsoft Graph'];
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) $this->accessToken)
            ->withHeaders(['Prefer' => 'IdType="ImmutableId"']);
    }
}
