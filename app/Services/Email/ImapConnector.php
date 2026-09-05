<?php

namespace App\Services\Email;

use App\Exceptions\AttachmentRejected;
use App\Models\Mailbox;
use App\Services\Attachments\InboundAttachmentPolicy;
use Illuminate\Support\Facades\Log;

class ImapConnector implements InboundEmailConnector
{
    private $connection;

    private Mailbox $mailbox;

    private ?int $uidValidity = null;

    private InboundAttachmentPolicy $attachmentPolicy;

    public function __construct(?InboundAttachmentPolicy $attachmentPolicy = null)
    {
        $this->attachmentPolicy = $attachmentPolicy ?? new InboundAttachmentPolicy;
    }

    public function connect(Mailbox $mailbox): bool
    {
        $this->mailbox = $mailbox;
        $settings = $mailbox->incoming_settings;

        $host = $settings['host'] ?? '';
        $port = $settings['port'] ?? 993;
        $encryption = $settings['encryption'] ?? 'ssl';
        $username = $mailbox->getDecryptedCredential('username');
        $password = $mailbox->getDecryptedCredential('password');

        $mailboxPath = '{'.$host.':'.$port.'/imap/'.$encryption.'}INBOX';

        try {
            $this->connection = @imap_open($mailboxPath, $username, $password);

            if (! $this->connection) {
                Log::error('IMAP connection failed', [
                    'mailbox_id' => $mailbox->id,
                    'error' => imap_last_error(),
                ]);

                return false;
            }

            $status = @imap_status($this->connection, $mailboxPath, SA_UIDVALIDITY);

            if (! $status || ! isset($status->uidvalidity) || (int) $status->uidvalidity < 1) {
                Log::error('IMAP UIDVALIDITY unavailable', ['mailbox_id' => $mailbox->id]);
                $this->disconnect();

                return false;
            }

            $this->uidValidity = (int) $status->uidvalidity;

            return true;
        } catch (\Throwable $e) {
            Log::error('IMAP connection error', [
                'mailbox_id' => $mailbox->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function fetchNewEmailReferences(?\DateTimeInterface $since = null): iterable
    {
        if (! $this->connection) {
            return;
        }

        // Unseen state is the retry cursor. A timestamp filter could permanently
        // skip a message whose dispatch or acknowledgement previously failed.
        $criteria = 'UNSEEN';

        $emails = imap_search($this->connection, $criteria);

        if (! $emails) {
            return;
        }

        foreach ($emails as $emailNumber) {
            $uid = imap_uid($this->connection, $emailNumber);

            if ($uid && $this->uidValidity) {
                yield [
                    'provider_message_id' => "imap:INBOX:{$this->uidValidity}:{$uid}",
                    'provider_remote_id' => (string) $uid,
                    'uid_validity' => $this->uidValidity,
                ];
            }
        }
    }

    public function fetchEmail(array $providerReference): array
    {
        if (! $this->connection || ! $this->uidValidity) {
            throw new \RuntimeException('IMAP is not connected.');
        }

        $uid = (int) ($providerReference['provider_remote_id'] ?? 0);
        $referenceUidValidity = (int) ($providerReference['uid_validity'] ?? 0);
        $expectedIdentity = trim((string) ($providerReference['provider_message_id'] ?? ''));
        $actualIdentity = "imap:INBOX:{$this->uidValidity}:{$uid}";

        if ($uid < 1 || $referenceUidValidity !== $this->uidValidity || ! hash_equals($actualIdentity, $expectedIdentity)) {
            throw new \UnexpectedValueException('IMAP provider reference is invalid or belongs to an expired UID epoch.');
        }

        $emailNumber = imap_msgno($this->connection, $uid);
        if (! $emailNumber) {
            throw new \UnexpectedValueException('IMAP provider message is no longer available.');
        }

        return $this->parseEmail($emailNumber);
    }

    private function parseEmail(int $emailNumber): array
    {
        $uid = imap_uid($this->connection, $emailNumber);

        if (! $uid || ! $this->uidValidity) {
            throw new \UnexpectedValueException('IMAP message is missing a stable provider identity.');
        }

        $header = imap_headerinfo($this->connection, $emailNumber);
        $structure = imap_fetchstructure($this->connection, $emailNumber);

        $fromAddress = $header->from[0]->mailbox.'@'.$header->from[0]->host;
        $fromName = $header->from[0]->personal ?? null;

        $toAddress = isset($header->to[0])
            ? $header->to[0]->mailbox.'@'.$header->to[0]->host
            : null;

        $body = $this->getBody($emailNumber, $structure);
        $attachmentResult = $this->getAttachments($emailNumber, $structure);

        $rawHeader = imap_fetchheader($this->connection, $emailNumber);
        $messageId = $this->extractHeader($rawHeader, 'Message-ID');
        $inReplyTo = $this->extractHeader($rawHeader, 'In-Reply-To');
        $references = $this->extractHeader($rawHeader, 'References');

        $emailData = [
            'provider_message_id' => "imap:INBOX:{$this->uidValidity}:{$uid}",
            'provider_remote_id' => (string) $uid,
            'from_email' => $fromAddress,
            'from_name' => $fromName ? imap_utf8($fromName) : null,
            'to_email' => $toAddress,
            'subject' => $header->subject ? imap_utf8($header->subject) : null,
            'body_text' => $body['text'] ?? null,
            'body_html' => $body['html'] ?? null,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'date' => $header->date ?? null,
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
        $uid = (int) ($emailData['provider_remote_id'] ?? 0);
        $providerMessageId = trim((string) ($emailData['provider_message_id'] ?? ''));
        $expectedProviderMessageId = "imap:INBOX:{$this->uidValidity}:{$uid}";

        if (! $this->connection || ! $this->uidValidity || $uid < 1 || ! hash_equals($expectedProviderMessageId, $providerMessageId)) {
            return false;
        }

        try {
            return imap_setflag_full($this->connection, (string) $uid, '\\Seen', ST_UID);
        } catch (\Throwable $e) {
            Log::warning('Failed to mark IMAP message as seen', [
                'mailbox_id' => $this->mailbox->id,
                'provider_message_id' => $emailData['provider_message_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function getBody(int $emailNumber, object $structure): array
    {
        $body = ['text' => null, 'html' => null];

        if ($structure->type === 0) {
            $content = imap_fetchbody($this->connection, $emailNumber, '1', FT_PEEK);
            $content = $this->decodeContent($content, $structure->encoding);

            if ($structure->subtype === 'PLAIN') {
                $body['text'] = $content;
            } else {
                $body['html'] = $content;
            }
        } elseif ($structure->type === 1) {
            foreach ($structure->parts as $index => $part) {
                if ($this->isAttachmentPart($part)) {
                    continue;
                }

                $section = (string) ($index + 1);
                $content = imap_fetchbody($this->connection, $emailNumber, $section, FT_PEEK);
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
            3 => $this->decodeBase64($content),
            4 => quoted_printable_decode($content),
            default => $content,
        };
    }

    /**
     * @return array{attachments: list<array{filename: string, content: string, mime_type: string, size: int}>, rejection: ?array{reason_code: string, reported_count: int, reported_bytes: int}}
     */
    private function getAttachments(int $emailNumber, object $structure): array
    {
        $descriptors = [];

        if (! isset($structure->parts)) {
            return ['attachments' => [], 'rejection' => null];
        }

        foreach ($structure->parts as $index => $part) {
            if ($this->isAttachmentPart($part)) {
                $filename = 'unnamed';
                if ($part->ifdparameters) {
                    foreach ($part->dparameters as $param) {
                        if (strtolower($param->attribute) === 'filename') {
                            $filename = $param->value;
                            break;
                        }
                    }
                }

                $descriptors[] = [
                    'section' => (string) ($index + 1),
                    'filename' => $filename,
                    'mime_type' => $this->getMimeType($part),
                    'encoding' => (int) ($part->encoding ?? 0),
                    'size' => $part->bytes ?? null,
                ];
            }
        }

        try {
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
                $content = imap_fetchbody($this->connection, $emailNumber, $descriptor['section'], FT_PEEK);
                $content = $this->decodeContent($content, (int) $descriptor['encoding']);
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

    private function isAttachmentPart(object $part): bool
    {
        return (bool) ($part->ifdisposition ?? false)
            && strtolower((string) ($part->disposition ?? '')) === 'attachment';
    }

    private function decodeBase64(string $content): string
    {
        $decoded = base64_decode($content, true);

        if ($decoded === false) {
            throw new AttachmentRejected('invalid_content', 'An attachment could not be decoded safely.');
        }

        return $decoded;
    }

    private function getMimeType(object $part): string
    {
        $types = [0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application', 4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other'];
        $type = $types[$part->type] ?? 'application';

        return $type.'/'.strtolower($part->subtype);
    }

    private function extractHeader(string $rawHeader, string $headerName): ?string
    {
        if (preg_match('/^'.preg_quote($headerName, '/').':\s*(.+?)$/mi', $rawHeader, $matches)) {
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
            $email = (new \Symfony\Component\Mime\Email)
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
