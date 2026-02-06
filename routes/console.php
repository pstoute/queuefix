<?php

use App\Jobs\FetchEmailsJob;
use App\Models\Mailbox;
use App\Services\SlaService;
use Illuminate\Support\Facades\Schedule;

// Fetch emails from all active mailboxes based on their polling interval
Schedule::call(function () {
    Mailbox::where('is_active', true)->each(function ($mailbox) {
        $shouldPoll = ! $mailbox->last_checked_at
            || $mailbox->last_checked_at->addMinutes($mailbox->polling_interval)->isPast();

        if ($shouldPoll) {
            FetchEmailsJob::dispatch($mailbox->id);
        }
    });
})->everyMinute()->name('fetch-emails');

// Check for SLA breaches every minute
Schedule::call(function () {
    app(SlaService::class)->checkBreaches();
})->everyMinute()->name('check-sla-breaches');

// Demo mode: auto-reset on a configurable interval
if (config('demo.enabled')) {
    $interval = (int) config('demo.reset_interval', 60);
    Schedule::command('demo:reset')
        ->cron("*/{$interval} * * * *")
        ->name('demo-reset')
        ->withoutOverlapping();
}
