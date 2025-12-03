<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PendingTenantSignup;

class ShowPendingSignups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'signups:list {--token= : Show specific token details}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all pending tenant signups and their verification tokens';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $token = $this->option('token');

        if ($token) {
            $this->showTokenDetails($token);
            return 0;
        }

        $this->info("📋 Pending Tenant Signups");
        $this->newLine();

        $pendingSignups = PendingTenantSignup::orderBy('created_at', 'desc')->get();

        if ($pendingSignups->isEmpty()) {
            $this->warn("No pending signups found.");
            return 0;
        }

        $tableData = $pendingSignups->map(function ($signup) {
            $status = $signup->verified ? '✅ Verified' : 
                     ($signup->isExpired() ? '⏰ Expired' : '⏳ Pending');
            
            return [
                $signup->company_name,
                $signup->slug,
                $signup->admin_email,
                substr($signup->verification_token, 0, 20) . '...',
                $signup->created_at->diffForHumans(),
                $signup->expires_at->format('Y-m-d H:i'),
                $status,
            ];
        })->toArray();

        $this->table(
            ['Company', 'Slug', 'Email', 'Token (preview)', 'Created', 'Expires', 'Status'],
            $tableData
        );

        $this->newLine();
        $this->info("💡 To see full token details:");
        $this->comment("   php artisan signups:list --token=YOUR_TOKEN");

        return 0;
    }

    private function showTokenDetails(string $token)
    {
        $signup = PendingTenantSignup::where('verification_token', $token)->first();

        if (!$signup) {
            $this->error("❌ Token not found!");
            return;
        }

        $this->info("🔍 Token Details");
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Company Name', $signup->company_name],
                ['Slug', $signup->slug],
                ['Admin Name', $signup->admin_name],
                ['Admin Email', $signup->admin_email],
                ['Verification Token', $signup->verification_token],
                ['Created At', $signup->created_at],
                ['Expires At', $signup->expires_at],
                ['Verified', $signup->verified ? 'Yes' : 'No'],
                ['Is Expired', $signup->isExpired() ? 'Yes' : 'No'],
                ['Is Valid', $signup->isValid() ? 'Yes ✅' : 'No ❌'],
            ]
        );

        $this->newLine();

        if ($signup->isValid()) {
            $verificationUrl = config('app.frontend_url', 'http://localhost:8082') . '/verify-signup?token=' . $signup->verification_token;
            $this->info("🔗 Verification URL:");
            $this->comment("   $verificationUrl");

            $this->newLine();
            $this->info("🧪 Test via API:");
            $this->comment("   curl -X GET 'http://localhost/api/tenants/verify-signup?token={$signup->verification_token}'");
        } else {
            $this->error("⚠️  This token is no longer valid!");
            if ($signup->verified) {
                $this->warn("   Reason: Already verified");
            } elseif ($signup->isExpired()) {
                $this->warn("   Reason: Expired at {$signup->expires_at}");
            }
        }
    }
}
