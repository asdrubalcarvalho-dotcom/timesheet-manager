<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Tenant;

final class OvertimeCalculator
{
    public function __construct(private readonly OvertimeRuleResolver $resolver)
    {
    }

    /**
     * @return array{regular_hours: float, overtime_hours: float, overtime_rate: float}
     */
    public function calculateForTenant(Tenant $tenant, float $weekHours): array
    {
        $rule = $this->resolver->resolveForTenant($tenant);

        if (!$rule) {
            return [
                'regular_hours' => max(0.0, $weekHours),
                'overtime_hours' => 0.0,
                'overtime_rate' => 1.5,
            ];
        }

        return $rule->splitWeekHours($weekHours);
    }
}
