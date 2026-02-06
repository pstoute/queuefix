<?php

use App\Enums\MessageType;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Email\EmailProcessorService;
use App\Services\TicketService;

beforeEach(function () {
    $this->ticketService = app(TicketService::class);
    $this->emailProcessor = new EmailProcessorService($this->ticketService);
});

test('creating new ticket from new sender', function () {
    $mailbox = Mailbox::factory()->create();

    $emailData = [
        'from_email' => 'newcustomer@example.com',
        'from_name' => 'New Customer',
        'subject' => 'Help needed',
        'body_text' => 'I need help with my account',
        'body_html' => '<p>I need help with my account</p>',
        'message_id' => '<unique-id@example.com>',
    ];

    $ticket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    expect($ticket)->toBeInstanceOf(Ticket::class);
    expect($ticket->subject)->toBe('Help needed');
    expect($ticket->mailbox_id)->toBe($mailbox->id);
    expect($ticket->status)->toBe(TicketStatus::Open);

    $this->assertDatabaseHas('customers', [
        'email' => 'newcustomer@example.com',
        'name' => 'New Customer',
    ]);
});

test('creating customer from email', function () {
    $mailbox = Mailbox::factory()->create();

    $emailData = [
        'from_email' => 'customer@example.com',
        'from_name' => 'John Doe',
        'subject' => 'Test',
        'body_text' => 'Test body',
    ];

    $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    $this->assertDatabaseHas('customers', [
        'email' => 'customer@example.com',
        'name' => 'John Doe',
    ]);
});

test('creating customer without name uses email prefix', function () {
    $mailbox = Mailbox::factory()->create();

    $emailData = [
        'from_email' => 'john.smith@example.com',
        'subject' => 'Test',
        'body_text' => 'Test body',
    ];

    $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    $this->assertDatabaseHas('customers', [
        'email' => 'john.smith@example.com',
        'name' => 'john.smith',
    ]);
});

test('matching existing ticket by In-Reply-To header', function () {
    $mailbox = Mailbox::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);
    $originalMessage = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'message_id' => '<original-message@example.com>',
    ]);

    $emailData = [
        'from_email' => $customer->email,
        'subject' => 'Re: Test',
        'body_text' => 'This is a reply',
        'in_reply_to' => '<original-message@example.com>',
    ];

    $resultTicket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    expect($resultTicket->id)->toBe($ticket->id);
    expect($ticket->messages()->count())->toBe(2);
});

test('matching existing ticket by References header', function () {
    $mailbox = Mailbox::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);
    $originalMessage = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'message_id' => '<first-message@example.com>',
    ]);

    $emailData = [
        'from_email' => $customer->email,
        'subject' => 'Re: Test',
        'body_text' => 'This is a reply',
        'references' => '<first-message@example.com> <second-message@example.com>',
    ];

    $resultTicket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    expect($resultTicket->id)->toBe($ticket->id);
});

test('matching existing ticket by References header as array', function () {
    $mailbox = Mailbox::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);
    $originalMessage = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'message_id' => '<first-message@example.com>',
    ]);

    $emailData = [
        'from_email' => $customer->email,
        'subject' => 'Re: Test',
        'body_text' => 'This is a reply',
        'references' => ['<first-message@example.com>', '<second-message@example.com>'],
    ];

    $resultTicket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    expect($resultTicket->id)->toBe($ticket->id);
});

test('matching existing ticket by subject line pattern', function () {
    $mailbox = Mailbox::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->id,
        'ticket_number' => 'ST-123',
    ]);

    $emailData = [
        'from_email' => $customer->email,
        'subject' => 'Re: [ST-123] Original subject',
        'body_text' => 'This is a reply',
    ];

    $resultTicket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    expect($resultTicket->id)->toBe($ticket->id);
});

test('matching existing ticket by subject line pattern with different format', function () {
    $mailbox = Mailbox::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->id,
        'ticket_number' => 'ST-456',
    ]);

    $emailData = [
        'from_email' => $customer->email,
        'subject' => 'Question about [ST-456]',
        'body_text' => 'This is a reply',
    ];

    $resultTicket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    expect($resultTicket->id)->toBe($ticket->id);
});

test('reopening resolved ticket on customer reply', function () {
    $mailbox = Mailbox::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->resolved()->create(['customer_id' => $customer->id]);
    $originalMessage = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'message_id' => '<original@example.com>',
    ]);

    expect($ticket->status)->toBe(TicketStatus::Resolved);

    $emailData = [
        'from_email' => $customer->email,
        'subject' => 'Re: Test',
        'body_text' => 'I still need help',
        'in_reply_to' => '<original@example.com>',
    ];

    $resultTicket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    $resultTicket->refresh();
    expect($resultTicket->status)->toBe(TicketStatus::Open);
});

test('reopening closed ticket on customer reply', function () {
    $mailbox = Mailbox::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->closed()->create(['customer_id' => $customer->id]);
    $originalMessage = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'message_id' => '<original@example.com>',
    ]);

    $emailData = [
        'from_email' => $customer->email,
        'subject' => 'Re: Test',
        'body_text' => 'Follow up question',
        'in_reply_to' => '<original@example.com>',
    ];

    $resultTicket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    $resultTicket->refresh();
    expect($resultTicket->status)->toBe(TicketStatus::Open);
});

test('attachment processing creates attachment records', function () {
    $mailbox = Mailbox::factory()->create();

    $emailData = [
        'from_email' => 'customer@example.com',
        'subject' => 'Test with attachment',
        'body_text' => 'See attached',
        'attachments' => [
            [
                'filename' => 'document.pdf',
                'content' => 'fake-pdf-content',
                'mime_type' => 'application/pdf',
            ],
            [
                'filename' => 'image.png',
                'content' => 'fake-image-content',
                'mime_type' => 'image/png',
            ],
        ],
    ];

    $ticket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    $message = $ticket->messages()->first();
    expect($message->attachments)->toHaveCount(2);

    $this->assertDatabaseHas('attachments', [
        'message_id' => $message->id,
        'filename' => 'document.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->assertDatabaseHas('attachments', [
        'message_id' => $message->id,
        'filename' => 'image.png',
        'mime_type' => 'image/png',
    ]);
});

test('attachment without filename uses default name', function () {
    $mailbox = Mailbox::factory()->create();

    $emailData = [
        'from_email' => 'customer@example.com',
        'subject' => 'Test',
        'body_text' => 'Test',
        'attachments' => [
            [
                'content' => 'content',
                'mime_type' => 'application/octet-stream',
            ],
        ],
    ];

    $ticket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);
    $message = $ticket->messages()->first();

    $this->assertDatabaseHas('attachments', [
        'message_id' => $message->id,
        'filename' => 'unnamed',
    ]);
});

test('email without subject uses default subject', function () {
    $mailbox = Mailbox::factory()->create();

    $emailData = [
        'from_email' => 'customer@example.com',
        'body_text' => 'Test body',
    ];

    $ticket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    expect($ticket->subject)->toBe('(No Subject)');
});

test('email with only html body creates ticket', function () {
    $mailbox = Mailbox::factory()->create();

    $emailData = [
        'from_email' => 'customer@example.com',
        'subject' => 'Test',
        'body_html' => '<p>HTML body</p>',
    ];

    $ticket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    $message = $ticket->messages()->first();
    expect($message->body_html)->toBe('<p>HTML body</p>');
});

test('build outbound headers includes ticket number in subject', function () {
    $ticket = Ticket::factory()->create([
        'ticket_number' => 'ST-100',
        'subject' => 'Original Subject',
    ]);

    $headers = $this->emailProcessor->buildOutboundHeaders($ticket);

    expect($headers['Subject'])->toBe('[ST-100] Original Subject');
});

test('build outbound headers includes In-Reply-To when last message exists', function () {
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'message_id' => '<last-message@example.com>',
    ]);

    $headers = $this->emailProcessor->buildOutboundHeaders($ticket, $message);

    expect($headers['In-Reply-To'])->toBe('<last-message@example.com>');
});

test('build outbound headers includes References from all messages', function () {
    $ticket = Ticket::factory()->create();
    $message1 = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'message_id' => '<first@example.com>',
    ]);
    $message2 = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'message_id' => '<second@example.com>',
    ]);

    $headers = $this->emailProcessor->buildOutboundHeaders($ticket);

    expect($headers['References'])->toBe('<first@example.com> <second@example.com>');
});

test('existing customer is reused not duplicated', function () {
    $mailbox = Mailbox::factory()->create();
    $existingCustomer = Customer::factory()->create([
        'email' => 'existing@example.com',
        'name' => 'Original Name',
    ]);

    $emailData = [
        'from_email' => 'existing@example.com',
        'from_name' => 'Different Name',
        'subject' => 'Test',
        'body_text' => 'Test',
    ];

    $ticket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    expect($ticket->customer_id)->toBe($existingCustomer->id);
    $this->assertDatabaseCount('customers', 1);
});

test('email addresses are case insensitive for customer matching', function () {
    $mailbox = Mailbox::factory()->create();
    $customer = Customer::factory()->create(['email' => 'test@example.com']);

    $emailData = [
        'from_email' => 'TEST@EXAMPLE.COM',
        'subject' => 'Test',
        'body_text' => 'Test',
    ];

    $ticket = $this->emailProcessor->processInboundEmail($emailData, $mailbox);

    expect($ticket->customer_id)->toBe($customer->id);
    $this->assertDatabaseCount('customers', 1);
});
