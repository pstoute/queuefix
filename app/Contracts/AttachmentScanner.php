<?php

namespace App\Contracts;

use App\Support\AttachmentScanResult;

interface AttachmentScanner
{
    public function scan(string $contents, string $detectedMimeType, string $filename): AttachmentScanResult;
}
