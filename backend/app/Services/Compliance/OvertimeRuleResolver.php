<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Tenant;
use App\Services\Compliance\US\FederalOvertimeRule;
use App\Services\Compliance\US\States\CAOvertimeRule;
use App\Services\Compliance\US\States\NYOvertimeRule;

final class OvertimeRuleResolver
{
    public function resolveForTenant(Tenant $tenant): ?OvertimeRuleInterface
    {
        $region = strtoupper((string) data_get($tenant->settings ?? [], 'region', ''));
        $region = trim($region);

        if ($region === '') {
            return null;
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
