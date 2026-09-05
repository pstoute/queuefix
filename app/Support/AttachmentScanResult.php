<?php

namespace App\Support;

use App\Enums\AttachmentScanStatus;

final readonly class AttachmentScanResult
{
    public function __construct(
        public AttachmentScanStatus $status,
        public ?string $reason = null,
    ) {}

    public static function pending(): self
    {
        return new self(AttachmentScanStatus::Pending);
    }

    public static function clean(): self
    {
        return new self(AttachmentScanStatus::Clean);
    }

    public static function rejected(string $reason): self
    {
        return new self(AttachmentScanStatus::Rejected, $reason);
    }
}
