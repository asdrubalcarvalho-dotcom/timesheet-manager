<?php

namespace App\Mail\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\Subscription;

class SubscriptionRecoveredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public Subscription $subscription,
        public float $amount,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'TimePerk subscription restored',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing.subscription_recovered',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
