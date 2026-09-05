<?php

use App\Jobs\EvaluateEscalationRulesJob;
use App\Models\Mailbox;
use App\Services\MailboxFetchDispatcher;
use App\Services\SlaService;
use Illuminate\Support\Facades\Schedule;

// Dispatch due mailbox fetches. The dispatcher persists the queued claim before
// dispatch, and the job holds a second per-mailbox overlap lock while running.
Schedule::call(function () {
    $dispatcher = app(MailboxFetchDispatcher::class);

    Mailbox::query()
        ->where('is_active', true)
        ->where(fn ($query) => $query->whereNull('next_fetch_at')->orWhere('next_fetch_at', '<=', now()))
        ->each(fn (Mailbox $mailbox) => $dispatcher->dispatch($mailbox));
})->everyMinute()->name('fetch-emails')->withoutOverlapping(10);

// Check for SLA breaches every minute
Schedule::call(function () {
    app(SlaService::class)->checkBreaches();
})->everyMinute()->name('check-sla-breaches');

// Evaluate active escalation rules every minute. The scheduler and queued job
// both hold overlap locks so slow runs cannot apply the same trigger in parallel.
Schedule::job(new EvaluateEscalationRulesJob)
    ->everyMinute()
    ->name('evaluate-escalation-rules')
    ->withoutOverlapping(10);

// Demo mode: auto-reset on a configurable interval
if (config('demo.enabled')) {
    $interval = (int) config('demo.reset_interval', 60);
    Schedule::command('demo:reset')
        ->cron("*/{$interval} * * * *")
        ->name('demo-reset')
        ->withoutOverlapping();
}
