<?php

namespace App\Listeners;

use App\Services\TenantDomainProvisioner;
use Stancl\Tenancy\Events\TenantCreated;

class ProvisionTenantDomain
{
    public function __construct(
        protected TenantDomainProvisioner $provisioner
    ) {
    }

    public function handle(TenantCreated $event): void
    {
        $this->provisioner->ensureDomain($event->tenant);
    }
}
