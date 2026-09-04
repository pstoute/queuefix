<?php

use App\Enums\TicketActivityActorType;
use App\Enums\TicketActivityType;
use App\Jobs\ProcessInboundEmailJob;
use App\Models\Mailbox;
use App\Models\TicketActivity;
use App\Services\Email\EmailProcessorService;
use Illuminate\Contracts\Queue\Job as QueueJob;

test('inbound email activities carry explicit system and job attribution', function () {
    $mailbox = Mailbox::factory()->create();
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('getJobId')->andReturn('inbound-job-456');
    $job = new ProcessInboundEmailJob([
        'from_email' => 'customer@example.com',
        'from_name' => 'Example Customer',
        'subject' => 'Background email',
        'body_text' => 'Created by the queue',
        'message_id' => '<background@example.com>',
        'attachments' => [[
            'filename' => 'details.txt',
            'content' => 'details',
            'mime_type' => 'text/plain',
        ]],
    ], $mailbox->id);
    $job->setJob($queueJob);

    $job->handle(app(EmailProcessorService::class));

    $activities = TicketActivity::orderBy('created_at')->get();
    expect($activities)->toHaveCount(2)
        ->and($activities->pluck('event_type')->all())->toContain(
            TicketActivityType::TicketCreated,
            TicketActivityType::AttachmentAdded,
        )
        ->and($activities->every(
            fn (TicketActivity $activity): bool => $activity->actor_id === null
                && $activity->actor_type === TicketActivityActorType::System
                && $activity->correlation_id === 'inbound-job-456'
        ))->toBeTrue();
});
