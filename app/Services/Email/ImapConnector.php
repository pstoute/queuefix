<?php

namespace App\Services\Email;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Log;

class ImapConnector
{
    private $connection;

    private Mailbox $mailbox;

    public function connect(Mailbox $mailbox): bool
    {
        $this->mailbox = $mailbox;
        $settings = $mailbox->incoming_settings;

        $host = $settings['host'] ?? '';
        $port = $settings['port'] ?? 993;
        $encryption = $settings['encryption'] ?? 'ssl';
        $username = $mailbox->getDecryptedCredential('username');
        $password = $mailbox->getDecryptedCredential('password');

        $mailboxPath = '{' . $host . ':' . $port . '/imap/' . $encryption . '}INBOX';

        try {
            $this->connection = @imap_open($mailboxPath, $username, $password);

            if (! $this->connection) {
                Log::error('IMAP connection failed', [
                    'mailbox_id' => $mailbox->id,
                    'error' => imap_last_error(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('IMAP connection error', [
                'mailbox_id' => $mailbox->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function fetchNewEmails(?\DateTimeInterface $since = null): array
    {
        if (! $this->connection) {
            return [];
        }

        $criteria = $since
            ? 'SINCE "' . $since->format('d-M-Y') . '"'
            : 'UNSEEN';

        $emails = imap_search($this->connection, $criteria);

        if (! $emails) {
            return [];
        }

        $messages = [];

        foreach ($emails as $emailNumber) {
            try {
                $messages[] = $this->parseEmail($emailNumber);
            } catch (\Throwable $e) {
                Log::warning('Failed to parse email', [
                    'mailbox_id' => $this->mailbox->id,
                    'email_number' => $emailNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $messages;
    }

    private function parseEmail(int $emailNumber): array
    {
        $header = imap_headerinfo($this->connection, $emailNumber);
        $structure = imap_fetchstructure($this->connection, $emailNumber);

        $fromAddress = $header->from[0]->mailbox . '@' . $header->from[0]->host;
        $fromName = $header->from[0]->personal ?? null;

        $body = $this->getBody($emailNumber, $structure);
        $attachments = $this->getAttachments($emailNumber, $structure);

        $rawHeader = imap_fetchheader($this->connection, $emailNumber);
        $messageId = $this->extractHeader($rawHeader, 'Message-ID');
        $inReplyTo = $this->extractHeader($rawHeader, 'In-Reply-To');
        $references = $this->extractHeader($rawHeader, 'References');

        imap_setflag_full($this->connection, (string) $emailNumber, '\\Seen');

        return [
            'from_email' => $fromAddress,
            'from_name' => $fromName ? imap_utf8($fromName) : null,
            'subject' => $header->subject ? imap_utf8($header->subject) : null,
            'body_text' => $body['text'] ?? null,
            'body_html' => $body['html'] ?? null,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'date' => $header->date ?? null,
            'attachments' => $attachments,
        ];
    }

    private function getBody(int $emailNumber, object $structure): array
    {
        $body = ['text' => null, 'html' => null];

        if ($structure->type === 0) {
            $content = imap_fetchbody($this->connection, $emailNumber, '1');
            $content = $this->decodeContent($content, $structure->encoding);

            if ($structure->subtype === 'PLAIN') {
                $body['text'] = $content;
            } else {
                $body['html'] = $content;
            }
        } elseif ($structure->type === 1) {
            foreach ($structure->parts as $index => $part) {
                $section = (string) ($index + 1);
                $content = imap_fetchbody($this->connection, $emailNumber, $section);
                $content = $this->decodeContent($content, $part->encoding);

                if ($part->subtype === 'PLAIN') {
                    $body['text'] = $content;
                } elseif ($part->subtype === 'HTML') {
                    $body['html'] = $content;
                }
            }
        }

        return $body;
    }

    private function decodeContent(string $content, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($content),
            4 => quoted_printable_decode($content),
            default => $content,
        };
    }

    private function getAttachments(int $emailNumber, object $structure): array
    {
        $attachments = [];

        if (! isset($structure->parts)) {
            return $attachments;
        }

        foreach ($structure->parts as $index => $part) {
            if ($part->ifdisposition && strtolower($part->disposition) === 'attachment') {
                $filename = 'unnamed';
                if ($part->ifdparameters) {
                    foreach ($part->dparameters as $param) {
                        if (strtolower($param->attribute) === 'filename') {
                            $filename = $param->value;
                            break;
                        }
                    }
                }

                $section = (string) ($index + 1);
                $content = imap_fetchbody($this->connection, $emailNumber, $section);
                $content = $this->decodeContent($content, $part->encoding);

                $attachments[] = [
                    'filename' => $filename,
                    'content' => $content,
                    'mime_type' => $this->getMimeType($part),
                    'size' => strlen($content),
                ];
            }
        }

        return $attachments;
    }

    private function getMimeType(object $part): string
    {
        $types = [0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application', 4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other'];
        $type = $types[$part->type] ?? 'application';

        return $type . '/' . strtolower($part->subtype);
    }

    private function extractHeader(string $rawHeader, string $headerName): ?string
    {
        if (preg_match('/^' . preg_quote($headerName, '/') . ':\s*(.+?)$/mi', $rawHeader, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public function sendEmail(array $data): bool
    {
        $settings = $this->mailbox->outgoing_settings;

        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            $settings['host'] ?? 'localhost',
            $settings['port'] ?? 587,
            $settings['encryption'] === 'tls',
        );

        $transport->setUsername($this->mailbox->getDecryptedCredential('smtp_username') ?? $this->mailbox->getDecryptedCredential('username'));
        $transport->setPassword($this->mailbox->getDecryptedCredential('smtp_password') ?? $this->mailbox->getDecryptedCredential('password'));

        try {
            $email = (new \Symfony\Component\Mime\Email())
                ->from($this->mailbox->email)
                ->to($data['to'])
                ->subject($data['subject']);

            if (! empty($data['html'])) {
                $email->html($data['html']);
            }

            if (! empty($data['text'])) {
                $email->text($data['text']);
            }

            if (! empty($data['headers'])) {
                foreach ($data['headers'] as $name => $value) {
                    if (! in_array(strtolower($name), ['subject', 'from', 'to'])) {
                        $email->getHeaders()->addTextHeader($name, $value);
                    }
                }
            }

            $transport->send($email);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send SMTP email', [
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
            $this->disconnect();

            return ['success' => true, 'message' => 'Connection successful'];
        }

        return ['success' => false, 'message' => imap_last_error() ?: 'Connection failed'];
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
