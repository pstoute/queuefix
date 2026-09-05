<?php

use App\Enums\MailboxType;
use App\Models\Mailbox;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

test('mailbox type is cast after database hydration', function (MailboxType $type) {
    $mailbox = Mailbox::factory()->create(['type' => $type]);

    $hydratedMailbox = Mailbox::query()->findOrFail($mailbox->id);

    expect($hydratedMailbox->type)->toBe($type)
        ->and($hydratedMailbox->getRawOriginal('type'))->toBe($type->value);
})->with(MailboxType::cases());

test('mailbox credentials are encrypted at rest and hidden from serialization', function () {
    $mailbox = Mailbox::factory()->create([
        'credentials' => [
            'username' => 'agent@example.com',
            'password' => 'plain-secret',
        ],
    ]);

    $hydratedMailbox = Mailbox::query()->findOrFail($mailbox->id);
    $storedCredentials = DB::table('mailboxes')->where('id', $mailbox->id)->value('credentials');

    expect($hydratedMailbox->getDecryptedCredential('username'))->toBe('agent@example.com')
        ->and($hydratedMailbox->getDecryptedCredential('missing'))->toBeNull()
        ->and($storedCredentials)->toBeString()
        ->and($storedCredentials)->not->toContain('agent@example.com')
        ->and($storedCredentials)->not->toContain('plain-secret')
        ->and($hydratedMailbox->toArray())->not->toHaveKey('credentials');
});

test('setting a mailbox credential persists it without dropping sibling values', function () {
    $mailbox = Mailbox::factory()->create([
        'credentials' => [
            'access_token' => ['access_token' => 'old-token'],
            'refresh_token' => 'refresh-token',
        ],
    ]);

    $mailbox->setEncryptedCredential('access_token', ['access_token' => 'rotated-token']);

    $hydratedMailbox = $mailbox->fresh();
    $storedCredentials = DB::table('mailboxes')->where('id', $mailbox->id)->value('credentials');

    expect($hydratedMailbox->getDecryptedCredential('access_token'))->toBe(['access_token' => 'rotated-token'])
        ->and($hydratedMailbox->getDecryptedCredential('refresh_token'))->toBe('refresh-token')
        ->and($storedCredentials)->not->toContain('rotated-token')
        ->and($storedCredentials)->not->toContain('refresh-token');
});

test('stale mailbox instances merge credential updates from the locked database row', function () {
    $mailbox = Mailbox::factory()->create([
        'credentials' => [
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
            'provider_account_id' => 'account-123',
        ],
    ]);
    $firstWorker = Mailbox::query()->findOrFail($mailbox->id);
    $secondWorker = Mailbox::query()->findOrFail($mailbox->id);

    $firstWorker->setEncryptedCredential('access_token', 'new-access-token');
    $secondWorker->setEncryptedCredential('refresh_token', 'new-refresh-token');

    $credentials = $mailbox->fresh()->credentials;

    expect($credentials)->toBe([
        'access_token' => 'new-access-token',
        'refresh_token' => 'new-refresh-token',
        'provider_account_id' => 'account-123',
    ]);
});

test('batch credential updates are rejected before any value is persisted', function () {
    $mailbox = Mailbox::factory()->create([
        'credentials' => [
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
        ],
    ]);

    expect(fn () => $mailbox->setEncryptedCredentials([
        'access_token' => 'new-access-token',
        '' => 'invalid-value',
    ]))->toThrow(UnexpectedValueException::class, 'Mailbox credential keys must be non-empty strings.');

    expect($mailbox->fresh()->credentials)->toBe([
        'access_token' => 'old-access-token',
        'refresh_token' => 'old-refresh-token',
    ]);
});

test('setting a mailbox credential rejects an empty key', function () {
    $mailbox = Mailbox::factory()->create();

    $mailbox->setEncryptedCredential('', 'secret');
})->throws(UnexpectedValueException::class, 'Mailbox credential keys must be non-empty strings.');

test('mailbox credential helpers reject decrypted payloads that are not arrays', function () {
    $mailbox = Mailbox::factory()->create();
    $mailbox->credentials = 'not-an-array';
    $mailbox->save();

    $mailbox->fresh()->getDecryptedCredential('username');
})->throws(UnexpectedValueException::class, 'Mailbox credentials must decrypt to an array.');

test('the demo mailbox seeder creates usable encrypted credentials', function () {
    $this->seed(DatabaseSeeder::class);

    $mailbox = Mailbox::query()->where('email', 'support@example.com')->firstOrFail();
    $storedCredentials = DB::table('mailboxes')->where('id', $mailbox->id)->value('credentials');

    expect($mailbox->getDecryptedCredential('username'))->toBe('support@example.com')
        ->and($mailbox->getDecryptedCredential('password'))->toBe('demo-password')
        ->and($storedCredentials)->not->toContain('support@example.com')
        ->and($storedCredentials)->not->toContain('demo-password');
});

test('updating a credential rotates an old-key payload to the current key', function () {
    $configuredKey = (string) config('app.key');
    $currentKey = str_starts_with($configuredKey, 'base64:')
        ? base64_decode(substr($configuredKey, 7), true)
        : $configuredKey;
    $oldKey = random_bytes(32);
    $oldEncrypter = new Encrypter($oldKey, (string) config('app.cipher'));
    $rotatingEncrypter = (new Encrypter($currentKey, (string) config('app.cipher')))
        ->previousKeys([$oldKey]);
    $mailbox = Mailbox::factory()->create();
    $oldCredentials = $oldEncrypter->encrypt(json_encode([
        'access_token' => 'old-access-token',
        'refresh_token' => 'old-refresh-token',
    ], JSON_THROW_ON_ERROR), false);
    DB::table('mailboxes')->where('id', $mailbox->id)->update(['credentials' => $oldCredentials]);

    Mailbox::encryptUsing($rotatingEncrypter);

    try {
        $hydratedMailbox = $mailbox->fresh();

        expect($hydratedMailbox->getDecryptedCredential('access_token'))->toBe('old-access-token');

        $hydratedMailbox->setEncryptedCredential('access_token', 'rotated-access-token');

        $storedCredentials = DB::table('mailboxes')->where('id', $mailbox->id)->value('credentials');
        $decryptedCredentials = json_decode($rotatingEncrypter->decrypt($storedCredentials, false), true, flags: JSON_THROW_ON_ERROR);

        expect($decryptedCredentials)->toBe([
            'access_token' => 'rotated-access-token',
            'refresh_token' => 'old-refresh-token',
        ]);
    } finally {
        Mailbox::encryptUsing(null);
    }
});
