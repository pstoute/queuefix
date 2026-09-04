<?php

use App\Enums\MailboxType;
use App\Enums\TicketActivityActorType;
use App\Enums\TicketActivityType;
use App\Jobs\SendEmailReplyJob;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\ImapConnector;
use App\Services\TicketActivityService;
use Illuminate\Contracts\Queue\Job as QueueJob;

test('outbound delivery outcome is recorded with system and job attribution', function (bool $delivered) {
    $mailbox = Mailbox::factory()->create(['type' => MailboxType::Imap]);
    $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id]);
    $message = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'body_text' => 'Reply body',
        'body_html' => '<p>Reply body</p>',
    ]);

    $connector = Mockery::mock(ImapConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('sendEmail')->once()->andReturn($delivered);
    app()->instance(ImapConnector::class, $connector);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('getJobId')->andReturn('queue-job-123');

    $job = new SendEmailReplyJob($ticket->id, $message->id);
    $job->setJob($queueJob);
    $job->handle(app(EmailProcessorService::class), app(TicketActivityService::class));

    $activity = $ticket->activities()->sole();
    expect($activity->event_type)->toBe(
        $delivered ? TicketActivityType::OutboundDelivered : TicketActivityType::OutboundFailed
    )
        ->and($activity->actor_type)->toBe(TicketActivityActorType::System)
        ->and($activity->actor_id)->toBeNull()
        ->and($activity->correlation_id)->toBe('queue-job-123')
        ->and($activity->after['message_id'])->toBe($message->id)
        ->and($activity->after['status'])->toBe($delivered ? 'delivered' : 'failed');
})->with([
    'delivered' => [true],
    'failed' => [false],
]);

test('terminal job exceptions record a failed delivery activity', function () {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->create(['ticket_id' => $ticket->id]);
    $job = new SendEmailReplyJob($ticket->id, $message->id);

    $job->failed(new RuntimeException('transport exploded'));

    $activity = $ticket->activities()->sole();
    expect($activity->event_type)->toBe(TicketActivityType::OutboundFailed)
        ->and($activity->actor_type)->toBe(TicketActivityActorType::System)
        ->and($activity->correlation_id)->toBe('outbound-message:'.$message->id)
        ->and($activity->after['reason'])->toBe('job_failed:'.RuntimeException::class);
});
