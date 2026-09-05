<?php

namespace App\Services\Email;

use App\Exceptions\AttachmentRejected;
use App\Models\Mailbox;
use App\Services\Attachments\InboundAttachmentPolicy;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;

class ImapConnector implements InboundEmailConnector
{
    private $connection;

    private Mailbox $mailbox;

    private ?int $uidValidity = null;

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

        $messageCount = imap_num_msg($this->connection);
        if ($messageCount < 1) {
            return;
        }

        $windowSize = $this->pollingPolicy->scanSize();
        $windowStart = max(1, (int) ($this->mailbox->imap_poll_cursor ?? 1));
        if ($windowStart > $messageCount) {
            $windowStart = 1;
        }
        $windowEnd = min($messageCount, $windowStart + $windowSize - 1);

        // Fetch one bounded server-side sequence window per poll. The durable
        // cursor rotates through large inboxes so older unread messages cannot
        // be permanently hidden behind a hot prefix.
        $overviews = imap_fetch_overview($this->connection, "{$windowStart}:{$windowEnd}");
        $nextWindowStart = $windowEnd >= $messageCount ? 1 : $windowEnd + 1;
        Mailbox::query()->whereKey($this->mailbox->id)->update(['imap_poll_cursor' => $nextWindowStart]);
        $this->mailbox->imap_poll_cursor = $nextWindowStart;

        if (! $overviews) {
            return;
        }

        foreach ($overviews as $overview) {
            if ((bool) ($overview->seen ?? false)) {
                continue;
            }

            $emailNumber = filter_var($overview->msgno ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($emailNumber === false) {
                continue;
            }

            $uid = filter_var($overview->uid ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($uid === false) {
                $uid = imap_uid($this->connection, $emailNumber);
            }

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

        $parts = $this->leafParts($structure);
        if ($parts === null) {
            $attachmentResult = [
                'attachments' => [],
                'rejection' => [
                    'reason_code' => 'invalid_metadata',
                    'reported_count' => 0,
                    'reported_bytes' => 0,
                ],
            ];
            $body = $this->bodyPolicy->omitted();
        } else {
            $attachmentResult = $this->getAttachments($emailNumber, $parts);
            $body = $this->getBody($emailNumber, $parts);
        }

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

    /**
     * @param  list<array{part: object, section: string}>  $parts
     * @return array{text: ?string, html: ?string}
     */
    private function getBody(int $emailNumber, array $parts): array
    {
        $body = ['text' => null, 'html' => null];
        $bodyParts = array_values(array_filter(
            $parts,
            fn (array $descriptor): bool => $this->isBodyPart($descriptor['part']),
        ));
        $maxBodyBytes = $this->bodyPolicy->maxBytes();
        $maxTransportBytes = $this->bodyPolicy->maxEncodedBodyTransportBytes();
        $declaredTransportBytes = 0;

        foreach ($bodyParts as $descriptor) {
            $size = filter_var($descriptor['part']->bytes ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);

            if ($size === false
                || $declaredTransportBytes > $maxTransportBytes
                || $size > $maxTransportBytes - $declaredTransportBytes) {
                return $this->bodyPolicy->omitted();
            }

            $declaredTransportBytes += $size;
        }

        $actualBytes = 0;

        try {
            foreach ($bodyParts as $descriptor) {
                $part = $descriptor['part'];
                $content = $this->fetchPartContent(
                    $emailNumber,
                    $descriptor['section'],
                    (int) ($part->encoding ?? 0),
                );

                if (strlen($content) > $maxBodyBytes - $actualBytes) {
                    return $this->bodyPolicy->omitted();
                }

                $actualBytes += strlen($content);

                if (strtoupper((string) ($part->subtype ?? '')) === 'PLAIN') {
                    $body['text'] = $content;
                } else {
                    $body['html'] = $content;
                }
            }
        } catch (AttachmentRejected) {
            return $this->bodyPolicy->omitted();
        }

        return $this->bodyPolicy->normalize($body['text'], $body['html']);
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
     * @param  list<array{part: object, section: string}>  $parts
     * @return array{attachments: list<array{filename: string, content: string, mime_type: string, size: int}>, rejection: ?array{reason_code: string, reported_count: int, reported_bytes: int}}
     */
    private function getAttachments(int $emailNumber, array $parts): array
    {
        $descriptors = [];

        foreach ($parts as $partDescriptor) {
            $part = $partDescriptor['part'];

            if (! $this->isBodyPart($part)) {
                $descriptors[] = [
                    'section' => $partDescriptor['section'],
                    'filename' => $this->attachmentFilename($part, $partDescriptor['section']),
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
                $content = $this->fetchPartContent(
                    $emailNumber,
                    $descriptor['section'],
                    (int) $descriptor['encoding'],
                );
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

    private function fetchPartContent(int $emailNumber, string $section, int $encoding): string
    {
        $content = imap_fetchbody($this->connection, $emailNumber, $section, FT_PEEK);

        if (! is_string($content)) {
            throw new \RuntimeException('IMAP message part fetch failed.');
        }

        return $this->decodeContent($content, $encoding);
    }

    /**
     * Flatten every MIME leaf so nested and inline binary parts cannot bypass
     * attachment admission by hiding outside the top-level part list.
     *
     * @return list<array{part: object, section: string}>|null
     */
    private function leafParts(object $part): ?array
    {
        $leaves = [];
        $partCount = 0;
        $scheduledPartCount = 1;
        $stack = [[$part, '', 0]];

        while ($stack !== []) {
            [$currentPart, $section, $depth] = array_pop($stack);
            $partCount++;

            if (! $this->bodyPolicy->allowsMimeNode($depth, $partCount)) {
                return null;
            }

            $children = $currentPart->parts ?? [];
            if (is_array($children) && $children !== []) {
                if (count($children) > $this->bodyPolicy->maxMimeParts() - $scheduledPartCount) {
                    return null;
                }
                $scheduledPartCount += count($children);

                for ($index = count($children) - 1; $index >= 0; $index--) {
                    $childSection = $section === ''
                        ? (string) ($index + 1)
                        : $section.'.'.($index + 1);
                    $stack[] = [$children[$index], $childSection, $depth + 1];
                }

                continue;
            }

            $leaves[] = [
                'part' => $currentPart,
                'section' => $section === '' ? '1' : $section,
            ];
        }

        return $leaves;
    }

    private function isBodyPart(object $part): bool
    {
        $subtype = strtoupper((string) ($part->subtype ?? ''));
        $disposition = strtolower((string) ($part->disposition ?? ''));

        return (int) ($part->type ?? -1) === 0
            && in_array($subtype, ['PLAIN', 'HTML'], true)
            && $disposition !== 'attachment'
            && $this->partParameter($part, ['filename', 'name']) === null;
    }

    private function attachmentFilename(object $part, string $section): string
    {
        $filename = $this->partParameter($part, ['filename', 'name']);

        if ($filename !== null && trim($filename) !== '') {
            return $filename;
        }

        $subtype = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($part->subtype ?? 'bin')) ?: 'bin');

        return 'inline-'.str_replace('.', '-', $section).'.'.$subtype;
    }

    /** @param list<string> $attributes */
    private function partParameter(object $part, array $attributes): ?string
    {
        foreach (['dparameters', 'parameters'] as $parameterProperty) {
            $parameters = $part->{$parameterProperty} ?? [];

            if (! is_array($parameters)) {
                continue;
            }

            foreach ($parameters as $parameter) {
                if (in_array(strtolower((string) ($parameter->attribute ?? '')), $attributes, true)) {
                    return (string) ($parameter->value ?? '');
                }
            }
        }

        return null;
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

        return $type.'/'.strtolower((string) ($part->subtype ?? 'octet-stream'));
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
            $transport->send($this->buildOutboundMessage($data));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send SMTP email', [
                'mailbox_id' => $this->mailbox->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildOutboundMessage(array $data): Email
    {
        $email = (new Email)
            ->from($this->mailbox->email)
            ->to($data['to'])
            ->subject($data['subject']);

        if (! empty($data['reply_to'])) {
            $email->replyTo($data['reply_to']);
        }

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

        return $email;
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
