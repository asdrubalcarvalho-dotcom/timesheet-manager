<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PendingTenantSignup;
use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ProvisionTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int|string $tenantId;

    /**
     * Keep retries safe and bounded.
     */
    public int $tries = 3;

    public function __construct(int|string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function handle(TenantProvisioningService $provisioningService): void
    {
        $lock = Cache::lock('tenant:provision:' . $this->tenantId, 600);

        if (! $lock->get()) {
            // Another worker is provisioning the same tenant.
            return;
        }

        try {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::find($this->tenantId);
            if (! $tenant) {
                return;
            }

            if ($tenant->status === 'active') {
                return;
            }

            $settings = is_array($tenant->settings) ? $tenant->settings : [];
            $settings['provisioning_status'] = 'provisioning';
            $settings['provisioning_error'] = null;
            $tenant->forceFill([
                'status' => 'provisioning',
                'settings' => $settings,
            ])->save();

            $pendingSignup = PendingTenantSignup::where('slug', $tenant->slug)->first();
            if (! $pendingSignup) {
                throw new \RuntimeException('Pending signup not found for tenant slug: ' . $tenant->slug);
            }

            $provisioningService->provisionFromPendingSignup($tenant, $pendingSignup);

            $settings = is_array($tenant->settings) ? $tenant->settings : [];
            $settings['provisioning_status'] = 'active';
            $settings['provisioning_error'] = null;
            $tenant->forceFill([
                'status' => 'active',
                'settings' => $settings,
            ])->save();
        } catch (\Throwable $e) {
            try {
                $tenant = Tenant::find($this->tenantId);
                if ($tenant) {
                    $settings = is_array($tenant->settings) ? $tenant->settings : [];
                    $settings['provisioning_status'] = 'failed';
                    $settings['provisioning_error'] = substr($e->getMessage(), 0, 1000);

                    $tenant->forceFill([
                        'status' => 'failed',
                        'settings' => $settings,
                    ])->save();
                }
            } finally {
                // Allow retries (job is safe/idempotent).
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }
}
