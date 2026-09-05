<?php

namespace App\Services\Attachments;

use App\Contracts\AttachmentScanner;
use App\Enums\AttachmentScanStatus;
use App\Exceptions\AttachmentRejected;
use App\Models\Attachment;
use App\Models\Message;
use App\Support\AttachmentScanResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

class AttachmentService
{
    public function __construct(
        private readonly AttachmentScanner $scanner,
    ) {}

    /**
     * Validate and store every attachment as one batch. Validation and scanning
     * complete before the first durable object is written.
     *
     * @param  iterable<int, UploadedFile|array{filename?: mixed, mime_type?: mixed, content?: mixed}>  $sources
     * @param  list<string>  $storedPaths
     *
     * @param-out list<string> $storedPaths
     *
     * @return Collection<int, Attachment>
     */
    public function storeForMessage(
        Message $message,
        iterable $sources,
        ?string $stableNamespace = null,
        array &$storedPaths = [],
    ): Collection {
        $sourceList = collect($sources)->values();
        $maxFiles = (int) config('attachments.max_files_per_message');

        if ($sourceList->count() > $maxFiles) {
            throw new AttachmentRejected('too_many_files', "A message may contain at most {$maxFiles} attachments.");
        }

        $reportedTotalBytes = $sourceList->sum(function (UploadedFile|array $source): int {
            if ($source instanceof UploadedFile) {
                return (int) ($source->getSize() ?: 0);
            }

            return is_string($source['content'] ?? null) ? strlen($source['content']) : 0;
        });

        if ($reportedTotalBytes > (int) config('attachments.max_message_bytes')) {
            throw new AttachmentRejected('message_too_large', 'The combined attachment size exceeds the allowed limit.');
        }

        $prepared = $sourceList->map(fn (UploadedFile|array $source): array => $this->prepare($source));
        $totalBytes = $prepared->sum('size');

        if ($totalBytes > (int) config('attachments.max_message_bytes')) {
            throw new AttachmentRejected('message_too_large', 'The combined attachment size exceeds the allowed limit.');
        }

        $prepared = $prepared->map(function (array $candidate): array {
            $candidate['scan'] = $this->scan($candidate);

            return $candidate;
        });

        $storedBytes = (int) $prepared
            ->reject(fn (array $candidate): bool => $candidate['scan']->status === AttachmentScanStatus::Rejected)
            ->sum('size');
        $disk = Storage::disk((string) config('attachments.disk'));
        /** @var Collection<int, string> $pathsCreatedHere */
        $pathsCreatedHere = collect();
        $created = collect();

        try {
            return DB::transaction(function () use (
                $message,
                $prepared,
                $storedBytes,
                $stableNamespace,
                $disk,
                &$storedPaths,
                &$pathsCreatedHere,
                $created,
            ): Collection {
                if ($storedBytes > 0) {
                    $this->assertStorageQuota($message, $storedBytes);
                }

                foreach ($prepared as $index => $candidate) {
                    if ($candidate['scan']->status === AttachmentScanStatus::Rejected) {
                        $created->push(Attachment::create([
                            'message_id' => $message->id,
                            'filename' => $candidate['filename'],
                            'path' => null,
                            'mime_type' => $candidate['detected_mime_type'],
                            'claimed_mime_type' => $candidate['claimed_mime_type'],
                            'size' => $candidate['size'],
                            'sha256' => $candidate['sha256'],
                            'scan_status' => AttachmentScanStatus::Rejected,
                            'rejection_reason' => 'scanner_rejected',
                        ]));

                        continue;
                    }

                    $path = $this->storagePath($message, $candidate['filename'], $index, $stableNamespace);

                    if (! $disk->put($path, $candidate['contents'])) {
                        throw new AttachmentRejected('storage_failed', 'The attachment could not be stored safely.');
                    }

                    $pathsCreatedHere->push($path);
                    $storedPaths[] = $path;
                    $created->push(Attachment::create([
                        'message_id' => $message->id,
                        'filename' => $candidate['filename'],
                        'path' => $path,
                        'mime_type' => $candidate['detected_mime_type'],
                        'claimed_mime_type' => $candidate['claimed_mime_type'],
                        'size' => $candidate['size'],
                        'sha256' => $candidate['sha256'],
                        'scan_status' => $candidate['scan']->status,
                        'rejection_reason' => null,
                    ]));
                }

                return $created;
            });
        } catch (Throwable $exception) {
            if ($pathsCreatedHere->isNotEmpty()) {
                $disk->delete($pathsCreatedHere->all());
                $storedPaths = array_values(array_diff($storedPaths, $pathsCreatedHere->all()));
            }

            throw $exception;
        }
    }

    /** @param array{reason_code?: mixed, reported_count?: mixed, reported_bytes?: mixed} $rejection */
    public function recordInboundRejection(Message $message, array $rejection): Attachment
    {
        $reasonCode = is_string($rejection['reason_code'] ?? null)
            ? Str::limit($rejection['reason_code'], 255, '')
            : 'policy_rejected';
        $reportedCount = max(1, (int) ($rejection['reported_count'] ?? 1));
        $reportedBytes = max(0, min(2_147_483_647, (int) ($rejection['reported_bytes'] ?? 0)));

        return Attachment::create([
            'message_id' => $message->id,
            'filename' => $reportedCount === 1 ? 'Rejected attachment' : "{$reportedCount} rejected attachments",
            'path' => null,
            'mime_type' => 'application/octet-stream',
            'claimed_mime_type' => null,
            'size' => $reportedBytes,
            'sha256' => null,
            'scan_status' => AttachmentScanStatus::Rejected,
            'rejection_reason' => $reasonCode,
        ]);
    }

    public function isTerminalRejection(AttachmentRejected $exception): bool
    {
        return ! in_array($exception->reasonCode, ['storage_failed', 'inspection_failed', 'quota_unavailable'], true);
    }

    private function storagePath(Message $message, string $filename, int $index, ?string $stableNamespace): string
    {
        if ($stableNamespace === null) {
            return sprintf('attachments/tickets/%s/%s', $message->ticket_id, Str::uuid());
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $name = hash('sha256', $stableNamespace."\0".$index);

        return 'attachments/inbound/'.$stableNamespace.'/'.$name.($extension === '' ? '' : '.'.$extension);
    }

    private function assertStorageQuota(Message $message, int $incomingBytes): void
    {
        $guard = DB::table('attachment_quota_locks')
            ->where('id', 1)
            ->lockForUpdate()
            ->first();

        if ($guard === null) {
            throw new AttachmentRejected('quota_unavailable', 'Attachment storage admission is temporarily unavailable.');
        }

        $installationBytes = (int) Attachment::query()->whereNotNull('path')->sum('size');
        $installationLimit = (int) config('attachments.max_installation_bytes');

        if ($incomingBytes > $installationLimit - $installationBytes) {
            throw new AttachmentRejected('installation_quota_exceeded', 'Attachment storage has reached the installation limit.');
        }

        $mailboxId = $message->ticket()->value('mailbox_id');
        if ($mailboxId === null) {
            return;
        }

        $mailboxBytes = (int) DB::table('attachments')
            ->join('messages', 'messages.id', '=', 'attachments.message_id')
            ->join('tickets', 'tickets.id', '=', 'messages.ticket_id')
            ->whereNotNull('attachments.path')
            ->where('tickets.mailbox_id', $mailboxId)
            ->sum('attachments.size');
        $mailboxLimit = (int) config('attachments.max_mailbox_bytes');

        if ($incomingBytes > $mailboxLimit - $mailboxBytes) {
            throw new AttachmentRejected('mailbox_quota_exceeded', 'Attachment storage has reached the mailbox limit.');
        }
    }

    /**
     * @param  UploadedFile|array{filename?: mixed, mime_type?: mixed, content?: mixed}  $source
     * @return array{filename: string, claimed_mime_type: ?string, detected_mime_type: string, contents: string, size: int, sha256: string}
     */
    private function prepare(UploadedFile|array $source): array
    {
        if ($source instanceof UploadedFile) {
            if (! $source->isValid()) {
                throw new AttachmentRejected('upload_failed', 'An attachment upload did not complete successfully.');
            }

            if ((int) $source->getSize() > (int) config('attachments.max_file_bytes')) {
                throw new AttachmentRejected('file_too_large', 'An attachment exceeds the per-file size limit.');
            }

            $filename = $source->getClientOriginalName();
            $claimedMimeType = $source->getClientMimeType();
            $contents = $source->getContent();
        } else {
            $filename = is_string($source['filename'] ?? null) ? $source['filename'] : '';
            $claimedMimeType = isset($source['mime_type']) ? (string) $source['mime_type'] : null;
            $contents = $source['content'] ?? null;

            if (! is_string($contents)) {
                throw new AttachmentRejected('invalid_content', 'An attachment could not be read.');
            }
        }

        $size = strlen($contents);
        if ($size === 0) {
            throw new AttachmentRejected('empty_file', 'Empty attachments are not allowed.');
        }

        if ($size > (int) config('attachments.max_file_bytes')) {
            throw new AttachmentRejected('file_too_large', 'An attachment exceeds the per-file size limit.');
        }

        $safeFilename = $this->normalizeFilename($filename);
        $extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));
        $allowedExtensions = (array) config('attachments.allowed_extensions');

        if ($extension === '' || ! array_key_exists($extension, $allowedExtensions)) {
            throw new AttachmentRejected('extension_not_allowed', 'An attachment type is not allowed.');
        }

        $detectedMimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';

        $this->rejectExecutableContent($contents);

        if (! in_array($detectedMimeType, $allowedExtensions[$extension], true)) {
            throw new AttachmentRejected('mime_mismatch', 'An attachment does not match its file extension.');
        }

        if ($extension === 'pdf') {
            if (preg_match('/\/Encrypt\b/', $contents) === 1) {
                throw new AttachmentRejected('password_protected', 'Password-protected attachments are not allowed.');
            }

            if (preg_match('/\/(JavaScript|JS|Launch|EmbeddedFile|RichMedia)\b/i', $contents) === 1) {
                throw new AttachmentRejected('active_pdf_content', 'PDF attachments containing active content are not allowed.');
            }
        }

        if (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            $this->validateOfficeContainer($contents, $extension);
        }

        return [
            'filename' => $safeFilename,
            'claimed_mime_type' => $claimedMimeType ? Str::limit($claimedMimeType, 255, '') : null,
            'detected_mime_type' => $detectedMimeType,
            'contents' => $contents,
            'size' => $size,
            'sha256' => hash('sha256', $contents),
        ];
    }

    private function normalizeFilename(string $filename): string
    {
        $filename = trim($filename);

        if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '\\') || preg_match('/[\x00-\x1F\x7F]/', $filename)) {
            throw new AttachmentRejected('unsafe_filename', 'An attachment has an unsafe filename.');
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $stem = preg_replace('/[^\pL\pN._ -]+/u', '_', $stem) ?? '';
        $stem = trim(preg_replace('/\s+/', ' ', $stem) ?? '', ' ._-');

        if ($stem === '') {
            throw new AttachmentRejected('unsafe_filename', 'An attachment has an unsafe filename.');
        }

        return Str::limit($stem, 140, '').'.'.$extension;
    }

    private function rejectExecutableContent(string $contents): void
    {
        $prefix = substr($contents, 0, 4);
        $executableMagic = [
            'MZ',
            "\x7FELF",
            "\xCF\xFA\xED\xFE",
            "\xCE\xFA\xED\xFE",
            "\xFE\xED\xFA\xCF",
            "\xFE\xED\xFA\xCE",
        ];

        foreach ($executableMagic as $magic) {
            if (str_starts_with($prefix, $magic)) {
                throw new AttachmentRejected('executable_content', 'Executable attachments are not allowed.');
            }
        }
    }

    private function validateOfficeContainer(string $contents, string $extension): void
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'queuefix-attachment-');
        if ($temporaryPath === false || file_put_contents($temporaryPath, $contents) === false) {
            throw new AttachmentRejected('inspection_failed', 'An attachment could not be inspected safely.');
        }

        $zip = new ZipArchive;
        $isOpen = false;

        try {
            $isOpen = $zip->open($temporaryPath) === true;

            if (! $isOpen || $zip->locateName('[Content_Types].xml') === false) {
                throw new AttachmentRejected('invalid_office_file', 'An Office attachment has an invalid container.');
            }

            $requiredDirectory = match ($extension) {
                'docx' => 'word/',
                'xlsx' => 'xl/',
                'pptx' => 'ppt/',
                default => throw new AttachmentRejected('invalid_office_file', 'An Office attachment has an invalid file extension.'),
            };
            $hasRequiredDirectory = false;
            $uncompressedBytes = 0;

            if ($zip->numFiles > (int) config('attachments.max_office_entries')) {
                throw new AttachmentRejected('office_container_too_complex', 'An Office attachment contains too many files.');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = (string) $zip->getNameIndex($index);
                $lowerEntry = strtolower($entry);
                $statistics = $zip->statIndex($index);

                if ($statistics === false) {
                    throw new AttachmentRejected('invalid_office_file', 'An Office attachment could not be inspected safely.');
                }

                $entrySize = (int) $statistics['size'];
                $compressedSize = (int) $statistics['comp_size'];
                $encryptionMethod = (int) $statistics['encryption_method'];
                $uncompressedBytes += $entrySize;

                if ($encryptionMethod !== 0) {
                    throw new AttachmentRejected('password_protected', 'Password-protected attachments are not allowed.');
                }

                if ($uncompressedBytes > (int) config('attachments.max_office_uncompressed_bytes')) {
                    throw new AttachmentRejected('office_container_too_large', 'An Office attachment expands beyond the allowed limit.');
                }

                if ($compressedSize > 0 && ($entrySize / $compressedSize) > (int) config('attachments.max_office_compression_ratio')) {
                    throw new AttachmentRejected('suspicious_compression', 'An Office attachment has a suspicious compression ratio.');
                }

                if (str_starts_with($entry, $requiredDirectory)) {
                    $hasRequiredDirectory = true;
                }

                if (str_ends_with($lowerEntry, 'vbaproject.bin') || str_contains($lowerEntry, '/embeddings/') || str_contains($lowerEntry, '/activex/')) {
                    throw new AttachmentRejected('active_office_content', 'Office attachments containing active content are not allowed.');
                }
            }

            if (! $hasRequiredDirectory) {
                throw new AttachmentRejected('invalid_office_file', 'An Office attachment does not match its file extension.');
            }

            $contentTypes = $zip->getFromName('[Content_Types].xml');
            if (! is_string($contentTypes) || preg_match('/(macroEnabled|vbaProject)/i', $contentTypes) === 1) {
                throw new AttachmentRejected('active_office_content', 'Office attachments containing active content are not allowed.');
            }
        } finally {
            if ($isOpen) {
                $zip->close();
            }
            @unlink($temporaryPath);
        }
    }

    /** @param array{filename: string, detected_mime_type: string, contents: string} $candidate */
    private function scan(array $candidate): AttachmentScanResult
    {
        if (! config('attachments.scanning_required')) {
            return AttachmentScanResult::clean();
        }

        return $this->scanner->scan(
            $candidate['contents'],
            $candidate['detected_mime_type'],
            $candidate['filename'],
        );
    }
}
