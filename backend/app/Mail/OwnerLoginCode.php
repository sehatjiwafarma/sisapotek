<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OwnerLoginCode extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly string $loginEmail,
        public readonly string $requestedAt,
        public readonly string $expiresAt,
        public readonly string $ipAddress,
        public readonly string $device,
        public readonly string $reference,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: 'Persetujuan login Owner · Sehat Jiwa',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.owner-login-code');
    }
}
