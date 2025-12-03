<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PendingTenantSignup;
use App\Notifications\TenantEmailVerification;
use App\Support\EmailRecipient;

class TestEmailVerification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:email-verification {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test tenant email verification by sending a test email and showing the verification token';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');

        $this->info("🚀 Testing Email Verification System");
        $this->newLine();

        // Create a test pending signup
        $pendingSignup = PendingTenantSignup::create([
            'company_name' => 'Test Company',
            'slug' => 'test-' . time(),
            'admin_name' => 'Test Admin',
            'admin_email' => $email,
            'password_hash' => bcrypt('password123'),
            'verification_token' => PendingTenantSignup::generateToken(),
            'expires_at' => now()->addHours(24),
        ]);

        $this->info("✅ Created pending signup:");
        $this->table(
            ['Field', 'Value'],
            [
                ['Company', $pendingSignup->company_name],
                ['Slug', $pendingSignup->slug],
                ['Email', $pendingSignup->admin_email],
                ['Token', $pendingSignup->verification_token],
                ['Expires At', $pendingSignup->expires_at],
            ]
        );

        $this->newLine();
        $this->info("📧 Sending verification email...");

        // Build verification URL
        $frontendUrl = config('app.frontend_url', config('app.url'));
        $verificationUrl = $frontendUrl . '/verify-signup?token=' . $pendingSignup->verification_token;

        // Send email
        $recipient = new EmailRecipient($pendingSignup->admin_email, $pendingSignup->admin_name);
        $recipient->notify(new TenantEmailVerification($verificationUrl, $pendingSignup->company_name));

        $this->newLine();
        $this->info("✅ Email sent! Check logs with:");
        $this->comment("   docker-compose exec app tail -f storage/logs/laravel.log");

        $this->newLine();
        $this->info("🔗 Verification URL:");
        $verificationUrl = config('app.frontend_url') . '/verify-signup?token=' . $pendingSignup->verification_token;
        $this->comment("   $verificationUrl");

        $this->newLine();
        $this->info("🧪 Test the verification with:");
        $this->comment("   curl -X GET 'http://localhost/api/tenants/verify-signup?token={$pendingSignup->verification_token}'");

        $this->newLine();
        $this->info("📋 Or validate token in database:");
        $this->comment("   docker-compose exec app php artisan tinker");
        $this->comment("   >>> \\App\\Models\\PendingTenantSignup::where('verification_token', '{$pendingSignup->verification_token}')->first()");

        return 0;
    }
}
