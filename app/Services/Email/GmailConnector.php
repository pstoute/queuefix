<?php

namespace App\Services\Email;

use App\Models\Mailbox;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\ModifyMessageRequest;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;

class GmailConnector implements InboundEmailConnector
{
    private ?Gmail $service = null;

    private Mailbox $mailbox;

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

    public function fetchNewEmails(?\DateTimeInterface $since = null): array
    {
        if (! $this->service) {
            return [];
        }

        try {
            // Unread state is the retry cursor. A timestamp filter could permanently
            // skip a message whose dispatch or acknowledgement previously failed.
            $query = 'is:unread';

            $results = $this->service->users_messages->listUsersMessages('me', [
                'q' => $query,
                'maxResults' => 50,
            ]);

            $messages = [];

            foreach ($results->getMessages() as $msgRef) {
                try {
                    $messages[] = $this->parseMessage($msgRef->getId());
                } catch (\Throwable $e) {
                    Log::warning('Failed to parse Gmail message', [
                        'mailbox_id' => $this->mailbox->id,
                        'message_id' => $msgRef->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $messages;
        } catch (\Throwable $e) {
            Log::error('Gmail fetch error', [
                'mailbox_id' => $this->mailbox->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function parseMessage(string $messageId): array
    {
        if (trim($messageId) === '') {
            throw new \UnexpectedValueException('Gmail message is missing a stable provider identity.');
        }

        $message = $this->service->users_messages->get('me', $messageId, ['format' => 'full']);
        $headers = $this->extractHeaders($message->getPayload()->getHeaders());

        $body = $this->extractBody($message->getPayload());
        $attachments = $this->extractAttachments($message->getPayload(), $messageId);

        return [
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
            'attachments' => $attachments,
        ];
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

    private function extractAttachments($payload, string $messageId): array
    {
        $attachments = [];

        if ($payload->getParts()) {
            foreach ($payload->getParts() as $part) {
                if ($part->getFilename() && $part->getBody()->getAttachmentId()) {
                    $attachmentData = $this->service->users_messages_attachments->get(
                        'me',
                        $messageId,
                        $part->getBody()->getAttachmentId()
                    );

                    $attachments[] = [
                        'filename' => $part->getFilename(),
                        'content' => $this->decodeBase64Url($attachmentData->getData()),
                        'mime_type' => $part->getMimeType(),
                        'size' => $part->getBody()->getSize(),
                    ];
                }

                $nested = $this->extractAttachments($part, $messageId);
                $attachments = array_merge($attachments, $nested);
            }
        }

        return $attachments;
    }

    private function decodeBase64Url(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
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
