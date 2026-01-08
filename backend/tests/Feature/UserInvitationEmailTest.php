<?php

namespace Tests\Feature;

use App\Mail\UserInvitationMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UserInvitationEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_invitation_email_can_be_sent(): void
    {
        // Arrange: Fake mail to capture emails without sending
        Mail::fake();

        // Create test tenant in central database
        $tenant = Tenant::create([
            'id' => '01HZTEST000000000000000001',
            'name' => 'Test Company',
            'slug' => 'test-company-' . uniqid(),
        ]);

        // Create test users
        $inviter = User::factory()->create([
            'name' => 'John Manager',
            'email' => 'manager@test.com',
        ]);

        $invitedUser = User::factory()->create([
            'name' => 'Jane Technician',
            'email' => 'technician@test.com',
        ]);

        // Act: Send the mail directly (this is what the listener does)
        Mail::to($invitedUser->email)->send(
            new UserInvitationMail($tenant, $invitedUser, $inviter)
        );

        // Assert: Check that mail was sent to the invited user
        Mail::assertSent(UserInvitationMail::class, function ($mail) use ($invitedUser) {
            return $mail->hasTo($invitedUser->email);
        });
    }

    public function test_user_invitation_email_has_correct_subject(): void
    {
        // Arrange
        $tenant = Tenant::create([
            'id' => '01HZTEST000000000000000002',
            'name' => 'Acme Corp',
            'slug' => 'acme-corp-' . uniqid(),
        ]);

        $inviter = User::factory()->create(['name' => 'Admin User']);
        $invitedUser = User::factory()->create(['name' => 'New User']);

        // Act: Create the mailable
        $mailable = new UserInvitationMail($tenant, $invitedUser, $inviter);

        // Assert: Check subject line includes tenant name
        $envelope = $mailable->envelope();
        $this->assertEquals("You've been invited to Acme Corp", $envelope->subject);
    }
}
