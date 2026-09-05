<?php

namespace App\Services\Email;

use App\Exceptions\InboundEmailRejected;

final class InboundEmailNormalizer
{
    public const MAX_METADATA_BYTES = 255;

    public const MAX_REFERENCE_COUNT = 100;

    public const MAX_REFERENCES_BYTES = 32_768;

    public function __construct(private InboundBodyPolicy $bodyPolicy) {}

    /**
     * Normalize provider-specific data to the database and lookup contract.
     *
     * @param  array<string, mixed>  $emailData
     * @return array<string, mixed>
     *
     * @throws InboundEmailRejected
     */
    public function normalize(array $emailData): array
    {
        $fromEmail = strtolower($this->requiredHeader($emailData['from_email'] ?? null, 'invalid_from_email', trim: true));
        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InboundEmailRejected('invalid_from_email');
        }

        $fromName = $this->optionalHeader($emailData['from_name'] ?? null, 'invalid_from_name');
        if ($fromName === null || $fromName === '') {
            $fromName = explode('@', $fromEmail, 2)[0];
        }

        $toEmail = $this->optionalHeader($emailData['to_email'] ?? null, 'invalid_to_email', trim: true);
        if ($toEmail !== null && $toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InboundEmailRejected('invalid_to_email');
        }

        $subject = $this->optionalHeader($emailData['subject'] ?? null, 'invalid_subject') ?? '(No Subject)';
        $messageId = $this->optionalHeader($emailData['message_id'] ?? null, 'invalid_message_id', trim: true);
        $inReplyTo = $this->optionalHeader($emailData['in_reply_to'] ?? null, 'invalid_in_reply_to', trim: true);
        $references = $this->normalizeReferences($emailData['references'] ?? null);
        $body = $this->bodyPolicy->normalize(
            $emailData['body_text'] ?? null,
            $emailData['body_html'] ?? null,
        );

        foreach ($body as $content) {
            if ($content !== null && (! $this->isUtf8($content) || str_contains($content, "\0"))) {
                throw new InboundEmailRejected('invalid_body');
            }
        }

        if (! is_array($emailData['attachments'] ?? [])) {
            throw new InboundEmailRejected('invalid_attachments');
        }
        if (isset($emailData['attachment_rejection']) && ! is_array($emailData['attachment_rejection'])) {
            throw new InboundEmailRejected('invalid_attachment_rejection');
        }

        return [
            ...$emailData,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'to_email' => $toEmail,
            'subject' => $subject,
            'body_text' => $body['text'] ?? strip_tags($body['html'] ?? ''),
            'body_html' => $body['html'],
            'message_id' => $messageId === '' ? null : $messageId,
            'in_reply_to' => $inReplyTo === '' ? null : $inReplyTo,
            'references' => $references,
            'attachments' => $emailData['attachments'] ?? [],
        ];
    }

    private function requiredHeader(mixed $value, string $reasonCode, bool $trim = false): string
    {
        $value = $this->optionalHeader($value, $reasonCode, $trim);

        if ($value === null || $value === '') {
            throw new InboundEmailRejected($reasonCode);
        }

        return $value;
    }

    private function optionalHeader(mixed $value, string $reasonCode, bool $trim = false): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InboundEmailRejected($reasonCode);
        }

        $value = $trim ? trim($value) : $value;
        if (strlen($value) > self::MAX_METADATA_BYTES
            || ! $this->isUtf8($value)
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InboundEmailRejected($reasonCode);
        }

        return $value;
    }

    /** @return list<string>|null */
    private function normalizeReferences(mixed $references): ?array
    {
        if ($references === null || $references === '') {
            return null;
        }

        if (is_string($references)) {
            if (strlen($references) > self::MAX_REFERENCES_BYTES
                || ! $this->isUtf8($references)
                || preg_match('/[\x00-\x1F\x7F]/', $references) === 1) {
                throw new InboundEmailRejected('invalid_references');
            }

            $parts = preg_split('/\s+/u', trim($references), -1, PREG_SPLIT_NO_EMPTY);
            if ($parts === false) {
                throw new InboundEmailRejected('invalid_references');
            }
        } elseif (is_array($references)) {
            if (count($references) > self::MAX_REFERENCE_COUNT) {
                throw new InboundEmailRejected('invalid_references');
            }
            $parts = $references;
        } else {
            throw new InboundEmailRejected('invalid_references');
        }

        $normalized = [];
        $aggregateBytes = 0;
        foreach ($parts as $part) {
            $part = $this->requiredHeader($part, 'invalid_references', trim: true);
            if (isset($normalized[$part])) {
                continue;
            }

            $aggregateBytes += strlen($part) + ($normalized === [] ? 0 : 1);
            if (count($normalized) >= self::MAX_REFERENCE_COUNT || $aggregateBytes > self::MAX_REFERENCES_BYTES) {
                throw new InboundEmailRejected('invalid_references');
            }

            $normalized[$part] = true;
        }

        return $normalized === [] ? null : array_keys($normalized);
    }

    private function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }
}
