<?php

use App\Enums\MailboxType;
use App\Models\Mailbox;

test('mailbox type is cast after database hydration', function (MailboxType $type) {
    $mailbox = Mailbox::factory()->create(['type' => $type]);

    $hydratedMailbox = Mailbox::query()->findOrFail($mailbox->id);

    expect($hydratedMailbox->type)->toBe($type)
        ->and($hydratedMailbox->getRawOriginal('type'))->toBe($type->value);
})->with(MailboxType::cases());
