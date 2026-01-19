<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Tenant;
use App\Services\Compliance\US\FederalOvertimeRule;
use App\Services\Compliance\US\States\CAOvertimeRule;
use App\Services\Compliance\US\States\NYOvertimeRule;

final class OvertimeRuleResolver
{
    /**
     * Canonical policy label used by API/UI.
     *
     * @return 'US-CA'|'US-NY'|'US-FLSA'|'NON-US'
     */
    public function policyKeyForTenant(Tenant $tenant): string
    {
        $region = strtoupper((string) data_get($tenant->settings ?? [], 'region', ''));
        $region = trim($region);

        $state = strtoupper((string) data_get($tenant->settings ?? [], 'state', ''));
        $state = trim($state);

        if ($region === '') {
            return 'NON-US';
        }

        // New shape: region=US + state=CA/NY
        if ($region === 'US' && $state !== '') {
            return match ($state) {
                'CA' => 'US-CA',
                'NY' => 'US-NY',
                default => 'US-FLSA',
            };
        }

        if ($region === 'US' || str_starts_with($region, 'US-')) {
            return match ($region) {
                'US-CA' => 'US-CA',
                'US-NY' => 'US-NY',
                default => 'US-FLSA',
            };
        }

        return 'NON-US';
    }

    public function resolveForTenant(Tenant $tenant): ?OvertimeRuleInterface
    {
        $region = strtoupper((string) data_get($tenant->settings ?? [], 'region', ''));
        $region = trim($region);

        $state = strtoupper((string) data_get($tenant->settings ?? [], 'state', ''));
        $state = trim($state);

        if ($region === '') {
            return null;
        }

        // New shape: region=US + state=CA/NY
        if ($region === 'US' && $state !== '') {
            return match ($state) {
                'CA' => new CAOvertimeRule(),
                'NY' => new NYOvertimeRule(),
                default => new FederalOvertimeRule(),
            };
        }

        if ($region === 'US' || str_starts_with($region, 'US-')) {
            return match ($region) {
                'US-CA' => new CAOvertimeRule(),
                'US-NY' => new NYOvertimeRule(),
                default => new FederalOvertimeRule(),
            };
        }

        return null;
    }
}
