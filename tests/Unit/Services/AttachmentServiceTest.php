<?php

use App\Contracts\AttachmentScanner;
use App\Enums\AttachmentScanStatus;
use App\Exceptions\AttachmentRejected;
use App\Models\Attachment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Attachments\AttachmentService;
use App\Support\AttachmentScanResult;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    config([
        'attachments.disk' => 'private',
        'attachments.scanning_required' => false,
    ]);
    $this->message = Message::factory()->create();
    $this->service = app(AttachmentService::class);
});

test('stores validated content with isolated path and detected metadata', function () {
    $contents = 'Quarterly support report';

    $attachment = $this->service->storeForMessage($this->message, [[
        'filename' => 'Quarterly Report (Final).txt',
        'mime_type' => 'application/octet-stream',
        'content' => $contents,
    ]])->sole();

    expect($attachment->filename)->toBe('Quarterly Report _Final.txt')
        ->and($attachment->mime_type)->toBe('text/plain')
        ->and($attachment->claimed_mime_type)->toBe('application/octet-stream')
        ->and($attachment->size)->toBe(strlen($contents))
        ->and($attachment->sha256)->toBe(hash('sha256', $contents))
        ->and($attachment->scan_status)->toBe(AttachmentScanStatus::Clean)
        ->and($attachment->path)->not->toContain('Quarterly');

    Storage::disk('private')->assertExists($attachment->path);
    expect($attachment->toArray())
        ->not->toHaveKeys(['path', 'claimed_mime_type', 'sha256', 'rejection_reason']);
});

test('rejects MIME mismatch before persistence', function () {
    expect(fn () => $this->service->storeForMessage($this->message, [[
        'filename' => 'invoice.pdf',
        'mime_type' => 'application/pdf',
        'content' => 'This is not a PDF.',
    ]]))->toThrow(AttachmentRejected::class, 'does not match');

    expect(Storage::disk('private')->allFiles())->toBe([]);
    $this->assertDatabaseCount('attachments', 0);
});

test('rejects path traversal filenames', function () {
    expect(fn () => $this->service->storeForMessage($this->message, [[
        'filename' => '../../outside.txt',
        'content' => 'safe bytes',
    ]]))->toThrow(AttachmentRejected::class, 'unsafe filename');

    expect(Storage::disk('private')->allFiles())->toBe([]);
});

test('rejects executable content even with an allowed extension', function () {
    expect(fn () => $this->service->storeForMessage($this->message, [[
        'filename' => 'readme.txt',
        'content' => "MZ\x90\x00executable payload",
    ]]))->toThrow(AttachmentRejected::class, 'Executable attachments');
});

test('rejects archive and active content extensions', function (string $filename) {
    expect(fn () => $this->service->storeForMessage($this->message, [[
        'filename' => $filename,
        'content' => 'not accepted',
    ]]))->toThrow(AttachmentRejected::class, 'type is not allowed');
})->with(['archive.zip', 'macro.docm', 'vector.svg', 'page.html']);

test('rejects password protected PDFs', function () {
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Encrypt 2 0 R >>\nendobj\n%%EOF";

    expect(fn () => $this->service->storeForMessage($this->message, [[
        'filename' => 'protected.pdf',
        'content' => $pdf,
    ]]))->toThrow(AttachmentRejected::class, 'Password-protected');
});

test('rejects PDFs containing active content', function () {
    $pdf = "%PDF-1.4\n1 0 obj\n<< /JavaScript 2 0 R >>\nendobj\n%%EOF";

    expect(fn () => $this->service->storeForMessage($this->message, [[
        'filename' => 'active.pdf',
        'content' => $pdf,
    ]]))->toThrow(AttachmentRejected::class, 'active content');
});

test('accepts a matching macro-free Office container', function () {
    $contents = officeContainer([
        '[Content_Types].xml' => '<Types/>',
        'word/document.xml' => '<document/>',
    ]);

    $attachment = $this->service->storeForMessage($this->message, [[
        'filename' => 'letter.docx',
        'content' => $contents,
    ]])->sole();

    expect($attachment->mime_type)->toBe('application/zip')
        ->and($attachment->scan_status)->toBe(AttachmentScanStatus::Clean);
});

test('rejects active content hidden in an Office container', function () {
    $contents = officeContainer([
        '[Content_Types].xml' => '<Types/>',
        'word/document.xml' => '<document/>',
        'word/vbaProject.bin' => 'macro',
    ]);

    expect(fn () => $this->service->storeForMessage($this->message, [[
        'filename' => 'letter.docx',
        'content' => $contents,
    ]]))->toThrow(AttachmentRejected::class, 'active content');

    expect(Storage::disk('private')->allFiles())->toBe([]);
});

test('enforces per-file and combined message limits before writing', function () {
    config([
        'attachments.max_file_bytes' => 5,
        'attachments.max_message_bytes' => 8,
    ]);

    expect(fn () => $this->service->storeForMessage($this->message, [[
        'filename' => 'large.txt',
        'content' => '123456',
    ]]))->toThrow(AttachmentRejected::class, 'per-file');

    expect(fn () => $this->service->storeForMessage($this->message, [
        ['filename' => 'one.txt', 'content' => '12345'],
        ['filename' => 'two.txt', 'content' => '67890'],
    ]))->toThrow(AttachmentRejected::class, 'combined');

    expect(Storage::disk('private')->allFiles())->toBe([]);
});

test('treats a missing quota lock as retryable infrastructure failure', function () {
    expect($this->service->isTerminalRejection(
        new AttachmentRejected('quota_unavailable', 'Attachment storage admission is temporarily unavailable.'),
    ))->toBeFalse();
});

test('records duplicate content separately with the same digest', function () {
    $attachments = $this->service->storeForMessage($this->message, [
        ['filename' => 'first.txt', 'content' => 'duplicate'],
        ['filename' => 'second.txt', 'content' => 'duplicate'],
    ]);

    expect($attachments)->toHaveCount(2)
        ->and($attachments[0]->sha256)->toBe($attachments[1]->sha256)
        ->and($attachments[0]->path)->not->toBe($attachments[1]->path);
});

test('keeps attachments pending when scanning is required but unavailable', function () {
    config(['attachments.scanning_required' => true]);

    $attachment = $this->service->storeForMessage($this->message, [[
        'filename' => 'notes.txt',
        'content' => 'pending scan',
    ]])->sole();

    expect($attachment->scan_status)->toBe(AttachmentScanStatus::Pending);
});

test('scanner rejection persists safe metadata without a storage object', function () {
    config(['attachments.scanning_required' => true]);
    $scanner = new class implements AttachmentScanner
    {
        public function scan(string $contents, string $detectedMimeType, string $filename): AttachmentScanResult
        {
            return AttachmentScanResult::rejected('malware');
        }
    };
    $service = new AttachmentService($scanner);

    $attachment = $service->storeForMessage($this->message, [[
        'filename' => 'notes.txt',
        'content' => 'scanner fixture',
    ]])->sole();

    expect($attachment->scan_status)->toBe(AttachmentScanStatus::Rejected)
        ->and($attachment->rejection_reason)->toBe('scanner_rejected')
        ->and($attachment->path)->toBeNull();
    expect(Storage::disk('private')->allFiles())->toBe([]);
});

test('enforces the installation storage quota before writing', function () {
    config([
        'attachments.max_installation_bytes' => 5,
        'attachments.max_mailbox_bytes' => 100,
    ]);
    Attachment::factory()->create(['size' => 4, 'path' => 'existing/file']);

    expect(fn () => $this->service->storeForMessage($this->message, [[
        'filename' => 'next.txt',
        'content' => 'ok',
    ]]))->toThrow(AttachmentRejected::class, 'installation limit');

    expect(Storage::disk('private')->allFiles())->toBe([])
        ->and(Attachment::query()->count())->toBe(1);
});

test('enforces each mailbox quota while allowing another mailbox', function () {
    config([
        'attachments.max_installation_bytes' => 100,
        'attachments.max_mailbox_bytes' => 5,
    ]);
    $firstMailbox = Mailbox::factory()->create();
    $secondMailbox = Mailbox::factory()->create();
    $firstTicket = Ticket::factory()->create(['mailbox_id' => $firstMailbox->id]);
    $secondTicket = Ticket::factory()->create(['mailbox_id' => $secondMailbox->id]);
    $firstMessage = Message::factory()->create(['ticket_id' => $firstTicket->id]);
    $secondMessage = Message::factory()->create(['ticket_id' => $secondTicket->id]);
    Attachment::factory()->create([
        'message_id' => $firstMessage->id,
        'size' => 4,
        'path' => 'existing/mailbox-file',
    ]);

    expect(fn () => $this->service->storeForMessage($firstMessage, [[
        'filename' => 'blocked.txt',
        'content' => 'ok',
    ]]))->toThrow(AttachmentRejected::class, 'mailbox limit');

    $stored = $this->service->storeForMessage($secondMessage, [[
        'filename' => 'allowed.txt',
        'content' => 'ok',
    ]]);

    expect($stored)->toHaveCount(1)
        ->and($stored->sole()->message_id)->toBe($secondMessage->id)
        ->and(Storage::disk('private')->allFiles())->toHaveCount(1);
});

/** @param array<string, string> $entries */
function officeContainer(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'queuefix-office-test-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();
    $contents = file_get_contents($path);
    unlink($path);

    return $contents;
}
