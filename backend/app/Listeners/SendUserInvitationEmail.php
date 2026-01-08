<?php

namespace App\Listeners;

use App\Events\UserInvited;
use App\Mail\UserInvitationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendUserInvitationEmail implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(UserInvited $event): void
    {
        Mail::to($event->invitedUser->email)
            ->send(new UserInvitationMail(
                $event->tenant,
                $event->invitedUser,
                $event->inviter
            ));
    }
}
