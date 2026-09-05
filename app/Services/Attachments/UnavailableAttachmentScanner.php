<?php

namespace App\Services\Attachments;

use App\Contracts\AttachmentScanner;
use App\Support\AttachmentScanResult;

class UnavailableAttachmentScanner implements AttachmentScanner
{
    public function scan(string $contents, string $detectedMimeType, string $filename): AttachmentScanResult
    {
        return AttachmentScanResult::pending();
    }
}
