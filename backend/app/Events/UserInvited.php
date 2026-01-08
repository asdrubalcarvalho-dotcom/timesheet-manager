<?php

namespace App\Events;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserInvited
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Tenant $tenant,
        public User $invitedUser,
        public User $inviter
    ) {
    }
}
