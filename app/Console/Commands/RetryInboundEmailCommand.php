<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\Email\InboundEmailClaimService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RetryInboundEmailCommand extends Command
{
    protected $signature = 'queuefix:retry-inbound-email
                            {mailbox : Mailbox UUID or email address}
                            {provider_message_id : Stable provider message identity}';

    protected $description = 'Allow an exhausted inbound message to be polled again';

    public function handle(InboundEmailClaimService $claimService): int
    {
        $mailboxArgument = trim((string) $this->argument('mailbox'));
        $providerMessageId = trim((string) $this->argument('provider_message_id'));
        $mailbox = Str::isUuid($mailboxArgument)
            ? Mailbox::query()->find($mailboxArgument)
            : Mailbox::query()->where('email', $mailboxArgument)->first();

        if (! $mailbox) {
            $this->error('Mailbox not found.');

            return self::FAILURE;
        }

        if ($providerMessageId === '' || ! $claimService->retryExhausted($mailbox->id, $providerMessageId)) {
            $this->error('No exhausted inbound message matched that mailbox and provider identity.');

            return self::FAILURE;
        }

        $this->info('The exhausted inbound message can be polled again.');

        return self::SUCCESS;
    }
}
