<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerMagicLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public Customer $customer,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Sign-in Link - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.customer-magic-link',
            with: [
                'url' => $this->url,
                'customerName' => $this->customer->name,
            ],
        );
    }
}
