<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantEmailVerification extends Notification
{
    use Queueable;

    protected string $verificationUrl;
    protected string $companyName;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $verificationUrl, string $companyName)
    {
        $this->verificationUrl = $verificationUrl;
        $this->companyName = $companyName;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your email - ' . config('app.name'))
            ->greeting('Welcome to ' . config('app.name') . '!')
            ->line('You have requested to create a new workspace: **' . $this->companyName . '**')
            ->line('To complete your registration and activate your workspace, please verify your email address by clicking the button below:')
            ->action('Verify Email Address', $this->verificationUrl)
            ->line('This verification link will expire in 24 hours.')
            ->line('If you did not create this account, no further action is required. The registration request will automatically expire.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'company_name' => $this->companyName,
            'verification_url' => $this->verificationUrl,
        ];
    }
}
