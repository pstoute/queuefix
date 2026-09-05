<?php

use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    Storage::fake('private');
    config([
        'attachments.disk' => 'private',
        'attachments.scanning_required' => false,
    ]);
});

test('agent can upload a validated attachment with a reply', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create();

    actingAs($agent);

    post(route('agent.tickets.reply', $ticket), [
        'body' => 'See the report.',
        'type' => 'reply',
        'attachments' => [UploadedFile::fake()->createWithContent('report.txt', 'support metrics')],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $attachment = Attachment::query()->sole();

    expect($attachment->filename)->toBe('report.txt')
        ->and($attachment->mime_type)->toBe('text/plain')
        ->and($attachment->scan_status)->toBe(AttachmentScanStatus::Clean);
    Storage::disk('private')->assertExists($attachment->path);
});

test('MIME mismatch rejects the entire reply without orphaned storage', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create();

    actingAs($agent);

    post(route('agent.tickets.reply', $ticket), [
        'body' => 'Spoofed document.',
        'type' => 'reply',
        'attachments' => [UploadedFile::fake()->createWithContent('invoice.pdf', 'plain text')],
    ])->assertSessionHasErrors('attachments');

    $this->assertDatabaseMissing('messages', [
        'ticket_id' => $ticket->id,
        'body_text' => 'Spoofed document.',
    ]);
    $this->assertDatabaseCount('attachments', 0);
    expect(Storage::disk('private')->allFiles())->toBe([]);
});

test('owning customer can upload and download a clean public-reply attachment', function () {
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);

    actingAs($customer, 'customer');

    post(route('customer.tickets.reply', $ticket), [
        'body' => 'Requested details.',
        'attachments' => [UploadedFile::fake()->createWithContent('details.txt', 'customer attachment')],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $attachment = Attachment::query()->sole();
    get(route('customer.attachments.download', $attachment))
        ->assertOk()
        ->assertHeader('content-type', 'application/octet-stream')
        ->assertHeader('x-content-type-options', 'nosniff');
});

test('agent download is authenticated and uses safe attachment headers', function () {
    $agent = User::factory()->create();
    $attachment = storedAttachment();

    get(route('agent.attachments.download', $attachment))
        ->assertRedirect(route('login'));

    actingAs($agent);

    get(route('agent.attachments.download', $attachment))
        ->assertOk()
        ->assertHeader('content-type', 'application/octet-stream')
        ->assertHeader('cache-control', 'no-store, private')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertHeader('content-security-policy', "default-src 'none'; sandbox")
        ->assertHeader('content-disposition', 'attachment; filename=report.txt');
});

test('customer cannot download another customers attachment or an internal note', function () {
    $owner = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();
    $publicAttachment = storedAttachment($owner);

    actingAs($otherCustomer, 'customer');
    get(route('customer.attachments.download', $publicAttachment))->assertForbidden();

    $internalAttachment = storedAttachment($owner, internal: true);
    actingAs($owner, 'customer');
    get(route('customer.attachments.download', $internalAttachment))->assertForbidden();
});

test('a compatible ticket merge preserves customer attachment ownership', function () {
    $customer = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();
    $primaryTicket = Ticket::factory()->create(['customer_id' => $customer->id]);
    $secondaryTicket = Ticket::factory()->create(['customer_id' => $customer->id]);
    $message = Message::factory()->create([
        'ticket_id' => $secondaryTicket->id,
        'sender_id' => $customer->id,
    ]);
    $attachment = Attachment::factory()->create([
        'message_id' => $message->id,
        'filename' => 'merged.txt',
        'path' => "attachments/tickets/{$secondaryTicket->id}/merged",
        'size' => strlen('merged attachment'),
        'sha256' => hash('sha256', 'merged attachment'),
        'scan_status' => AttachmentScanStatus::Clean,
    ]);
    Storage::disk('private')->put($attachment->path, 'merged attachment');

    actingAs(User::factory()->admin()->create());
    post(route('agent.tickets.merge', $primaryTicket), [
        'merge_ticket_id' => $secondaryTicket->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($message->fresh()->ticket_id)->toBe($primaryTicket->id)
        ->and($attachment->fresh()->message_id)->toBe($message->id)
        ->and($attachment->path)->toBe("attachments/tickets/{$secondaryTicket->id}/merged");

    actingAs($customer, 'customer');
    get(route('customer.attachments.download', $attachment))->assertOk();

    actingAs($otherCustomer, 'customer');
    get(route('customer.attachments.download', $attachment))->assertForbidden();
});

test('pending attachments cannot be downloaded and private paths are not serialized', function () {
    $agent = User::factory()->create();
    $attachment = storedAttachment();
    $attachment->update(['scan_status' => AttachmentScanStatus::Pending]);

    actingAs($agent);

    get(route('agent.attachments.download', $attachment))->assertStatus(423);

    get(route('agent.tickets.show', $attachment->message->ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ticket.messages.0.attachments.0.id', $attachment->id)
            ->missing('ticket.messages.0.attachments.0.path')
            ->missing('ticket.messages.0.attachments.0.url')
        );

    get('/storage/'.$attachment->path)->assertForbidden();
});

function storedAttachment(?Customer $customer = null, bool $internal = false): Attachment
{
    $customer ??= Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);
    $message = $internal
        ? Message::factory()->internalNote()->create(['ticket_id' => $ticket->id])
        : Message::factory()->create(['ticket_id' => $ticket->id, 'sender_id' => $customer->id]);
    $attachment = Attachment::factory()->create([
        'message_id' => $message->id,
        'filename' => 'report.txt',
        'path' => "attachments/tickets/{$ticket->id}/fixture",
        'size' => strlen('download body'),
        'sha256' => hash('sha256', 'download body'),
        'scan_status' => AttachmentScanStatus::Clean,
    ]);

    Storage::disk('private')->put($attachment->path, 'download body');

    return $attachment;
}
