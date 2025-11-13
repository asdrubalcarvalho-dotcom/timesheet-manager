<?php

namespace App\Services;

use App\Models\Tenant;
use App\Notifications\TenantDomainProvisioningNeeded;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Stancl\Tenancy\Database\Models\Domain;

class TenantDomainProvisioner
{
    public function ensureDomain(Tenant $tenant): void
    {
        $baseDomain = trim((string) config('tenancy.domains.base', ''));

        if ($baseDomain === '') {
            Log::warning('Tenant domain provisioning skipped: no base domain configured.', [
                'tenant' => $tenant->id,
            ]);

            return;
        }

        $hostname = sprintf('%s.%s', $tenant->slug, $baseDomain);

        /** @var Domain $domainModel */
        $domainModel = app(config('tenancy.domain_model'));

        $domainModel->newQuery()
            ->firstOrCreate([
                'domain' => strtolower($hostname),
                'tenant_id' => $tenant->id,
            ]);

        if (! config('tenancy.domains.auto_provision', false)) {
            $this->notifyManualProvisioning($tenant, $hostname, 'Automatic DNS provisioning disabled.');
        }
    }

    protected function notifyManualProvisioning(Tenant $tenant, string $hostname, string $reason): void
    {
        $opsEmail = config('tenancy.domains.ops_email');

        if ($opsEmail) {
            Notification::route('mail', $opsEmail)
                ->notify(new TenantDomainProvisioningNeeded($tenant, $hostname, $reason));
        } else {
            Log::notice('Tenant domain requires manual DNS provisioning.', [
                'tenant' => $tenant->id,
                'slug' => $tenant->slug,
                'domain' => $hostname,
                'reason' => $reason,
            ]);
        }
    }
}
