<?php

namespace App\Services\Email;

final class InboundBodyPolicy
{
    public const OMITTED_TEXT = '[Inbound message body omitted because it exceeded the configured safety limit.]';

    /** @return array{text: ?string, html: ?string} */
    public function normalize(mixed $text, mixed $html): array
    {
        $normalized = ['text' => null, 'html' => null];
        $acceptedBytes = 0;

        foreach (['text' => $text, 'html' => $html] as $type => $content) {
            if ($content === null) {
                continue;
            }

            if (! is_string($content) || ! $this->allowsAdditionalBytes($acceptedBytes, strlen($content))) {
                return $this->omitted();
            }

            $acceptedBytes += strlen($content);
            $normalized[$type] = $content;
        }

        return $normalized;
    }

    /** @return array{text: ?string, html: ?string} */
    public function fromProviderContent(mixed $contentType, mixed $content): array
    {
        if (! is_string($content)) {
            return $content === null ? ['text' => null, 'html' => null] : $this->omitted();
        }

        if (strtolower((string) $contentType) !== 'html') {
            return $this->normalize($content, null);
        }

        $body = $this->normalize('', $content);

        return $body;
    }

    public function allowsAdditionalBytes(int $acceptedBytes, int $candidateBytes): bool
    {
        $maxBytes = $this->maxBytes();

        return $acceptedBytes >= 0
            && $candidateBytes >= 0
            && $acceptedBytes <= $maxBytes
            && $candidateBytes <= $maxBytes - $acceptedBytes;
    }

    public function allowsMimeNode(int $depth, int $partCount): bool
    {
        return $depth >= 0
            && $depth <= max(0, (int) config('attachments.max_mime_depth'))
            && $partCount > 0
            && $partCount <= $this->maxMimeParts();
    }

    public function maxMimeParts(): int
    {
        return max(1, (int) config('attachments.max_mime_parts'));
    }

    public function maxBytes(): int
    {
        return max(0, (int) config('attachments.max_body_bytes'));
    }

    public function maxProviderMessageBytes(): int
    {
        return max(1, (int) config('attachments.max_provider_message_bytes'));
    }

    public function maxEncodedBodyTransportBytes(): int
    {
        return $this->maxBytes() > intdiv(PHP_INT_MAX, 4)
            ? PHP_INT_MAX
            : $this->maxBytes() * 4;
    }

    public function maxEncodedBytes(int $acceptedDecodedBytes): int
    {
        $remainingBytes = max(0, $this->maxBytes() - max(0, $acceptedDecodedBytes));
        $groups = intdiv($remainingBytes, 3) + ($remainingBytes % 3 === 0 ? 0 : 1);

        return $groups > intdiv(PHP_INT_MAX, 4) ? PHP_INT_MAX : $groups * 4;
    }

    public function maxGraphEnvelopeBytes(): int
    {
        // JSON may encode one input byte as a six-byte Unicode escape. Keep a
        // small fixed allowance for the body wrapper while retaining a hard cap.
        $maxMultipliableBytes = intdiv(PHP_INT_MAX - 65_536, 6);

        if ($this->maxBytes() > $maxMultipliableBytes) {
            return PHP_INT_MAX;
        }

        return ($this->maxBytes() * 6) + 65_536;
    }

    /** @return array{text: string, html: null} */
    public function omitted(): array
    {
        return ['text' => self::OMITTED_TEXT, 'html' => null];
    }
}
