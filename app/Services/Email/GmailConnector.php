<?php

namespace App\Services\Email;

use App\Exceptions\AttachmentRejected;
use App\Models\Mailbox;
use App\Services\Attachments\InboundAttachmentPolicy;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\ModifyMessageRequest;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;

class GmailConnector implements InboundEmailConnector
{
    private ?Gmail $service = null;

    private Mailbox $mailbox;

    private InboundAttachmentPolicy $attachmentPolicy;

    public function __construct(?InboundAttachmentPolicy $attachmentPolicy = null)
    {
        $this->attachmentPolicy = $attachmentPolicy ?? new InboundAttachmentPolicy;
    }

    public function connect(Mailbox $mailbox): bool
    {
        $this->mailbox = $mailbox;

        try {
            $client = new GoogleClient;
            $client->setClientId(config('services.google_gmail.client_id'));
            $client->setClientSecret(config('services.google_gmail.client_secret'));
            $client->setAccessToken($mailbox->getDecryptedCredential('access_token'));

            if ($client->isAccessTokenExpired()) {
                $refreshToken = $mailbox->getDecryptedCredential('refresh_token');
                if ($refreshToken) {
                    $client->fetchAccessTokenWithRefreshToken($refreshToken);
                    $mailbox->setEncryptedCredential('access_token', $client->getAccessToken());
                } else {
                    Log::error('Gmail refresh token missing', ['mailbox_id' => $mailbox->id]);

                    return false;
                }
            }

            $this->service = new Gmail($client);

            return true;
        } catch (\Throwable $e) {
            Log::error('Gmail connection error', [
                'mailbox_id' => $mailbox->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function fetchNewEmailReferences(?\DateTimeInterface $since = null): iterable
    {
        if (! $this->service) {
            return;
        }

        try {
            // Unread state is the retry cursor. A timestamp filter could permanently
            // skip a message whose dispatch or acknowledgement previously failed.
            $query = 'is:unread';

            $results = $this->service->users_messages->listUsersMessages('me', [
                'q' => $query,
                'maxResults' => 50,
            ]);

            foreach ($results->getMessages() as $msgRef) {
                $messageId = trim((string) $msgRef->getId());

                if ($messageId !== '') {
                    yield [
                        'provider_message_id' => 'gmail:'.$messageId,
                        'provider_remote_id' => $messageId,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error('Gmail fetch error', [
                'mailbox_id' => $this->mailbox->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function fetchEmail(array $providerReference): array
    {
        if (! $this->service) {
            throw new \RuntimeException('Gmail is not connected.');
        }

        $messageId = trim((string) ($providerReference['provider_remote_id'] ?? ''));
        $expectedIdentity = trim((string) ($providerReference['provider_message_id'] ?? ''));

        if ($messageId === '' || ! hash_equals('gmail:'.$messageId, $expectedIdentity)) {
            throw new \UnexpectedValueException('Gmail provider reference is invalid.');
        }

        return $this->parseMessage($messageId);
    }

    private function parseMessage(string $messageId): array
    {
        if (trim($messageId) === '') {
            throw new \UnexpectedValueException('Gmail message is missing a stable provider identity.');
        }

        $message = $this->service->users_messages->get('me', $messageId, ['format' => 'full']);
        $headers = $this->extractHeaders($message->getPayload()->getHeaders());

        $body = $this->extractBody($message->getPayload());
        $attachmentResult = $this->extractAttachments($message->getPayload(), $messageId);

        $emailData = [
            'provider_message_id' => 'gmail:'.$messageId,
            'provider_remote_id' => $messageId,
            'from_email' => $this->parseEmailAddress($headers['From'] ?? ''),
            'from_name' => $this->parseEmailName($headers['From'] ?? ''),
            'to_email' => $this->parseEmailAddress($headers['To'] ?? $headers['Delivered-To'] ?? ''),
            'subject' => $headers['Subject'] ?? null,
            'body_text' => $body['text'] ?? null,
            'body_html' => $body['html'] ?? null,
            'message_id' => $headers['Message-ID'] ?? $headers['Message-Id'] ?? null,
            'in_reply_to' => $headers['In-Reply-To'] ?? null,
            'references' => $headers['References'] ?? null,
            'date' => $headers['Date'] ?? null,
            'attachments' => $attachmentResult['attachments'],
        ];

        if ($attachmentResult['rejection'] !== null) {
            $emailData['attachment_rejection'] = $attachmentResult['rejection'];
        }

        return $emailData;
    }

    /**
     * @param  array<string, mixed>  $emailData
     */
    public function acknowledge(array $emailData): bool
    {
        $messageId = trim((string) ($emailData['provider_remote_id'] ?? ''));

        if (! $this->service || $messageId === '') {
            return false;
        }

        try {
            $modify = new ModifyMessageRequest;
            $modify->setRemoveLabelIds(['UNREAD']);
            $this->service->users_messages->modify('me', $messageId, $modify);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Failed to mark Gmail message as read', [
                'mailbox_id' => $this->mailbox->id,
                'provider_message_id' => $emailData['provider_message_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function extractHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $header) {
            $result[$header->getName()] = $header->getValue();
        }

        return $result;
    }

    private function extractBody($payload): array
    {
        $body = ['text' => null, 'html' => null];

        if ($payload->getMimeType() === 'text/plain' && $payload->getBody()->getData()) {
            $body['text'] = $this->decodeBase64Url($payload->getBody()->getData());
        } elseif ($payload->getMimeType() === 'text/html' && $payload->getBody()->getData()) {
            $body['html'] = $this->decodeBase64Url($payload->getBody()->getData());
        }

        if ($payload->getParts()) {
            foreach ($payload->getParts() as $part) {
                $partBody = $this->extractBody($part);
                if ($partBody['text']) {
                    $body['text'] = $partBody['text'];
                }
                if ($partBody['html']) {
                    $body['html'] = $partBody['html'];
                }
            }
        }

        return $body;
    }

    /**
     * @return array{attachments: list<array{filename: string, content: string, mime_type: string, size: int}>, rejection: ?array{reason_code: string, reported_count: int, reported_bytes: int}}
     */
    private function extractAttachments(MessagePart $payload, string $messageId): array
    {
        $descriptors = [];
        $this->collectAttachmentDescriptors($payload, $descriptors);

        try {
            $mailbox = isset($this->mailbox) ? $this->mailbox : null;
            $this->attachmentPolicy->assertMetadata($descriptors, $mailbox);
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
                $attachmentData = $this->service->users_messages_attachments->get(
                    'me',
                    $messageId,
                    $descriptor['attachment_id'],
                );
                $content = $this->decodeBase64Url((string) $attachmentData->getData());
                $this->attachmentPolicy->assertContent($content, $actualBytes);

                $attachments[] = [
                    'filename' => $descriptor['filename'],
                    'content' => $content,
                    'mime_type' => $descriptor['mime_type'],
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

    /** @param list<array{attachment_id: string, filename: string, mime_type: string, size: mixed}> $descriptors */
    private function collectAttachmentDescriptors(MessagePart $payload, array &$descriptors): void
    {
        foreach ($payload->getParts() ?: [] as $part) {
            $attachmentId = trim((string) $part->getBody()->getAttachmentId());
            $filename = (string) $part->getFilename();

            if ($filename !== '' && $attachmentId !== '') {
                $descriptors[] = [
                    'attachment_id' => $attachmentId,
                    'filename' => $filename,
                    'mime_type' => (string) $part->getMimeType(),
                    'size' => $part->getBody()->getSize(),
                ];
            }

            $this->collectAttachmentDescriptors($part, $descriptors);
        }
    }

    private function decodeBase64Url(string $data): string
    {
        $base64 = strtr($data, '-_', '+/');
        $decoded = base64_decode($base64.str_repeat('=', (4 - strlen($base64) % 4) % 4), true);

        if ($decoded === false) {
            throw new AttachmentRejected('invalid_content', 'An attachment could not be decoded safely.');
        }

        return $decoded;
    }

    private function parseEmailAddress(string $from): string
    {
        if (preg_match('/<(.+?)>/', $from, $matches)) {
            return strtolower($matches[1]);
        }

        return strtolower(trim($from));
    }

    private function parseEmailName(string $from): ?string
    {
        if (preg_match('/^"?(.+?)"?\s*</', $from, $matches)) {
            return trim($matches[1], '" ');
        }

        return null;
    }

    public function sendEmail(array $data): bool
    {
        if (! $this->service) {
            return false;
        }

        try {
            $email = (new Email)
                ->from($this->mailbox->email)
                ->to($data['to'])
                ->subject($data['subject']);

            if (! empty($data['html'])) {
                $email->html($data['html']);
            }

            if (! empty($data['text'])) {
                $email->text($data['text']);
            }

            $headers = array_change_key_case($data['headers'] ?? [], CASE_LOWER);
            foreach (['in-reply-to' => 'In-Reply-To', 'references' => 'References'] as $key => $name) {
                if (! empty($headers[$key])) {
                    $email->getHeaders()->addHeader($name, (string) $headers[$key]);
                }
            }

            $gmailMessage = new Gmail\Message;
            $gmailMessage->setRaw(rtrim(strtr(base64_encode($email->toString()), '+/', '-_'), '='));

            $this->service->users_messages->send('me', $gmailMessage);

            return true;
        } catch (\Throwable $e) {
            Log::error('Gmail send error', [
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
                $this->service->users->getProfile('me');

                return ['success' => true, 'message' => 'Gmail connection successful'];
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        return ['success' => false, 'message' => 'Failed to connect to Gmail'];
    }
}
