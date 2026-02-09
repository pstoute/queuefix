<?php

namespace App\Services\Email;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphConnector
{
    private Mailbox $mailbox;

    private string $baseUrl = 'https://graph.microsoft.com/v1.0';

    private ?string $accessToken = null;

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
            'https://login.microsoftonline.com/' . config('services.microsoft_graph.tenant_id', 'common') . '/oauth2/v2.0/token',
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
            $this->mailbox->setEncryptedCredential('access_token', $data['access_token']);
            $this->mailbox->setEncryptedCredential('refresh_token', $data['refresh_token'] ?? $refreshToken);
            $this->mailbox->setEncryptedCredential('token_expires_at', (string) (now()->timestamp + $data['expires_in']));

            return $data['access_token'];
        }

        Log::error('Microsoft Graph token refresh failed', [
            'mailbox_id' => $this->mailbox->id,
            'response' => $response->body(),
        ]);

        return null;
    }

    public function fetchNewEmails(?\DateTimeInterface $since = null): array
    {
        if (! $this->accessToken) {
            return [];
        }

        try {
            $filter = 'isRead eq false';
            if ($since) {
                $filter .= " and receivedDateTime ge {$since->format('Y-m-d\TH:i:s\Z')}";
            }

            $response = Http::withToken($this->accessToken)
                ->get("{$this->baseUrl}/me/messages", [
                    '$filter' => $filter,
                    '$top' => 50,
                    '$orderby' => 'receivedDateTime desc',
                    '$select' => 'id,subject,from,toRecipients,body,receivedDateTime,internetMessageHeaders,hasAttachments,internetMessageId',
                ]);

            if (! $response->successful()) {
                Log::error('Microsoft Graph fetch error', [
                    'mailbox_id' => $this->mailbox->id,
                    'response' => $response->body(),
                ]);

                return [];
            }

            $messages = [];

            foreach ($response->json('value', []) as $msg) {
                try {
                    $messages[] = $this->parseMessage($msg);

                    Http::withToken($this->accessToken)
                        ->patch("{$this->baseUrl}/me/messages/{$msg['id']}", [
                            'isRead' => true,
                        ]);
                } catch (\Throwable $e) {
                    Log::warning('Failed to parse Graph message', [
                        'mailbox_id' => $this->mailbox->id,
                        'message_id' => $msg['id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $messages;
        } catch (\Throwable $e) {
            Log::error('Microsoft Graph fetch error', [
                'mailbox_id' => $this->mailbox->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function parseMessage(array $msg): array
    {
        $headers = $this->extractInternetHeaders($msg['internetMessageHeaders'] ?? []);
        $attachments = [];

        if ($msg['hasAttachments'] ?? false) {
            $attachments = $this->fetchAttachments($msg['id']);
        }

        return [
            'from_email' => strtolower($msg['from']['emailAddress']['address'] ?? ''),
            'from_name' => $msg['from']['emailAddress']['name'] ?? null,
            'to_email' => strtolower($msg['toRecipients'][0]['emailAddress']['address'] ?? ''),
            'subject' => $msg['subject'] ?? null,
            'body_text' => strip_tags($msg['body']['content'] ?? ''),
            'body_html' => ($msg['body']['contentType'] ?? '') === 'html' ? ($msg['body']['content'] ?? null) : null,
            'message_id' => $msg['internetMessageId'] ?? $headers['Message-ID'] ?? null,
            'in_reply_to' => $headers['In-Reply-To'] ?? null,
            'references' => $headers['References'] ?? null,
            'date' => $msg['receivedDateTime'] ?? null,
            'attachments' => $attachments,
        ];
    }

    private function extractInternetHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $header) {
            $result[$header['name']] = $header['value'];
        }

        return $result;
    }

    private function fetchAttachments(string $messageId): array
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/me/messages/{$messageId}/attachments");

        if (! $response->successful()) {
            return [];
        }

        $attachments = [];

        foreach ($response->json('value', []) as $att) {
            if (($att['@odata.type'] ?? '') === '#microsoft.graph.fileAttachment') {
                $attachments[] = [
                    'filename' => $att['name'],
                    'content' => base64_decode($att['contentBytes']),
                    'mime_type' => $att['contentType'] ?? 'application/octet-stream',
                    'size' => $att['size'] ?? 0,
                ];
            }
        }

        return $attachments;
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

            $response = Http::withToken($this->accessToken)
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
                $response = Http::withToken($this->accessToken)
                    ->get("{$this->baseUrl}/me");

                if ($response->successful()) {
                    return ['success' => true, 'message' => 'Microsoft Graph connection successful'];
                }

                return ['success' => false, 'message' => 'API call failed: ' . $response->body()];
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        return ['success' => false, 'message' => 'Failed to connect to Microsoft Graph'];
    }
}
