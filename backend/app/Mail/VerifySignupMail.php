<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifySignupMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string $companyName
     * @param string $verificationUrl
     */
    public function __construct(
        public string $companyName,
        public string $verificationUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your email - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verify-signup',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
