<?php

namespace App\Services\Attachments;

use App\Exceptions\AttachmentRejected;
use App\Models\Attachment;
use App\Models\Mailbox;
use Illuminate\Support\Facades\DB;

final class InboundAttachmentPolicy
{
    /**
     * Reject provider metadata before any attachment content is requested.
     *
     * @param  list<array{size?: mixed}>  $descriptors
     */
    public function assertMetadata(array $descriptors, ?Mailbox $mailbox = null): void
    {
        $maxFiles = (int) config('attachments.max_files_per_message');

        if (count($descriptors) > $maxFiles) {
            throw new AttachmentRejected('too_many_files', "A message may contain at most {$maxFiles} attachments.");
        }

        $totalBytes = 0;
        $maxFileBytes = (int) config('attachments.max_file_bytes');
        $maxMessageBytes = (int) config('attachments.max_message_bytes');

        foreach ($descriptors as $descriptor) {
            $size = filter_var($descriptor['size'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);

            if ($size === false) {
                throw new AttachmentRejected('size_unavailable', 'An attachment did not include trustworthy size metadata.');
            }

            if ($size > $maxFileBytes) {
                throw new AttachmentRejected('file_too_large', 'An attachment exceeds the per-file size limit.');
            }

            if ($size > $maxMessageBytes - $totalBytes) {
                throw new AttachmentRejected('message_too_large', 'The combined attachment size exceeds the allowed limit.');
            }

            $totalBytes += $size;
        }

        if ($mailbox !== null && $totalBytes > 0) {
            $this->assertStorageCapacity($mailbox, $totalBytes);
        }
    }

    private function assertStorageCapacity(Mailbox $mailbox, int $incomingBytes): void
    {
        $installationBytes = (int) Attachment::query()->whereNotNull('path')->sum('size');
        $installationLimit = (int) config('attachments.max_installation_bytes');

        if ($incomingBytes > $installationLimit - $installationBytes) {
            throw new AttachmentRejected('installation_quota_exceeded', 'Attachment storage has reached the installation limit.');
        }

        $mailboxBytes = (int) DB::table('attachments')
            ->join('messages', 'messages.id', '=', 'attachments.message_id')
            ->join('tickets', 'tickets.id', '=', 'messages.ticket_id')
            ->whereNotNull('attachments.path')
            ->where('tickets.mailbox_id', $mailbox->id)
            ->sum('attachments.size');
        $mailboxLimit = (int) config('attachments.max_mailbox_bytes');

        if ($incomingBytes > $mailboxLimit - $mailboxBytes) {
            throw new AttachmentRejected('mailbox_quota_exceeded', 'Attachment storage has reached the mailbox limit.');
        }
    }

    public function assertContent(string $contents, int &$totalBytes): void
    {
        $size = strlen($contents);

        if ($size > (int) config('attachments.max_file_bytes')) {
            throw new AttachmentRejected('file_too_large', 'An attachment exceeds the per-file size limit.');
        }

        $maxMessageBytes = (int) config('attachments.max_message_bytes');
        if ($size > $maxMessageBytes - $totalBytes) {
            throw new AttachmentRejected('message_too_large', 'The combined attachment size exceeds the allowed limit.');
        }

        $totalBytes += $size;
    }

    /**
     * @param  list<array{size?: mixed}>  $descriptors
     * @return array{reason_code: string, reported_count: int, reported_bytes: int}
     */
    public function rejection(AttachmentRejected $exception, array $descriptors): array
    {
        $reportedBytes = 0;

        foreach ($descriptors as $descriptor) {
            $size = filter_var($descriptor['size'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);

            if ($size !== false) {
                $reportedBytes = min(2_147_483_647, $reportedBytes + $size);
            }
        }

        return [
            'reason_code' => $exception->reasonCode,
            'reported_count' => count($descriptors),
            'reported_bytes' => $reportedBytes,
        ];
    }
}
