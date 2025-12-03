<?php

namespace App\Support;

use Illuminate\Notifications\Notifiable;

class EmailRecipient
{
    use Notifiable;

    protected string $email;
    protected string $name;

    public function __construct(string $email, string $name = '')
    {
        $this->email = $email;
        $this->name = $name;
    }

    /**
     * Route notifications for mail channel.
     */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    /**
     * Get the recipient's name.
     */
    public function getName(): string
    {
        return $this->name;
    }
}
