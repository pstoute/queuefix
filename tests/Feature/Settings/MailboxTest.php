<?php

use App\Enums\MailboxType;
use App\Enums\UserRole;
use App\Models\Mailbox;
use App\Models\User;
use function Pest\Laravel\{actingAs, get, post, put, delete};

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->create(['role' => UserRole::Agent]);
});

test('admin can view mailboxes', function () {
    actingAs($this->admin);

    Mailbox::factory()->count(3)->create();

    get(route('settings.mailboxes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Mailboxes/Index')
            ->has('mailboxes', 3)
        );
});

test('creating a mailbox', function () {
    actingAs($this->admin);

    post(route('settings.mailboxes.store'), [
        'name' => 'Support Mailbox',
        'email' => 'support@example.com',
        'type' => MailboxType::Imap->value,
        'polling_interval' => 5,
        'incoming_settings' => [
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
        ],
        'outgoing_settings' => [
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
        ],
        'credentials' => [
            'username' => 'support@example.com',
            'password' => 'secret',
        ],
    ])
        ->assertRedirect(route('settings.mailboxes.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('mailboxes', [
        'name' => 'Support Mailbox',
        'email' => 'support@example.com',
        'type' => MailboxType::Imap->value,
        'polling_interval' => 5,
    ]);
});

test('updating a mailbox', function () {
    actingAs($this->admin);

    $mailbox = Mailbox::factory()->create();

    put(route('settings.mailboxes.update', $mailbox), [
        'name' => 'Updated Name',
        'email' => $mailbox->email,
        'polling_interval' => 10,
        'is_active' => false,
    ])
        ->assertRedirect(route('settings.mailboxes.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('mailboxes', [
        'id' => $mailbox->id,
        'name' => 'Updated Name',
        'polling_interval' => 10,
        'is_active' => false,
    ]);
});

test('deleting a mailbox', function () {
    actingAs($this->admin);

    $mailbox = Mailbox::factory()->create();

    delete(route('settings.mailboxes.destroy', $mailbox))
        ->assertRedirect(route('settings.mailboxes.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('mailboxes', [
        'id' => $mailbox->id,
    ]);
});

test('mailbox email must be unique', function () {
    actingAs($this->admin);

    Mailbox::factory()->create(['email' => 'existing@example.com']);

    post(route('settings.mailboxes.store'), [
        'name' => 'Duplicate Mailbox',
        'email' => 'existing@example.com',
        'type' => MailboxType::Imap->value,
        'incoming_settings' => [
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
        ],
        'credentials' => [
            'username' => 'test@example.com',
            'password' => 'secret',
        ],
    ])
        ->assertSessionHasErrors('email');
});

test('mailbox name is required', function () {
    actingAs($this->admin);

    post(route('settings.mailboxes.store'), [
        'email' => 'test@example.com',
        'type' => MailboxType::Imap->value,
    ])
        ->assertSessionHasErrors('name');
});

test('mailbox type is required', function () {
    actingAs($this->admin);

    post(route('settings.mailboxes.store'), [
        'name' => 'Test Mailbox',
        'email' => 'test@example.com',
    ])
        ->assertSessionHasErrors('type');
});

test('imap mailbox requires incoming settings', function () {
    actingAs($this->admin);

    post(route('settings.mailboxes.store'), [
        'name' => 'Test Mailbox',
        'email' => 'test@example.com',
        'type' => MailboxType::Imap->value,
    ])
        ->assertSessionHasErrors(['incoming_settings', 'credentials']);
});

test('creating gmail mailbox', function () {
    actingAs($this->admin);

    post(route('settings.mailboxes.store'), [
        'name' => 'Gmail Mailbox',
        'email' => 'support@gmail.com',
        'type' => MailboxType::Gmail->value,
        'polling_interval' => 5,
    ])
        ->assertRedirect(route('settings.mailboxes.index'));

    $this->assertDatabaseHas('mailboxes', [
        'name' => 'Gmail Mailbox',
        'email' => 'support@gmail.com',
        'type' => MailboxType::Gmail->value,
    ]);
});

test('creating microsoft mailbox', function () {
    actingAs($this->admin);

    post(route('settings.mailboxes.store'), [
        'name' => 'Microsoft Mailbox',
        'email' => 'support@outlook.com',
        'type' => MailboxType::Microsoft->value,
        'polling_interval' => 5,
    ])
        ->assertRedirect(route('settings.mailboxes.index'));

    $this->assertDatabaseHas('mailboxes', [
        'name' => 'Microsoft Mailbox',
        'email' => 'support@outlook.com',
        'type' => MailboxType::Microsoft->value,
    ]);
});
