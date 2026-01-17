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

    /**
     * Calculate daily overtime breakdown. Used for jurisdictions that have daily OT rules
     * (e.g. California) without impacting weekly overtime calculations.
     *
     * @param array<string, float|int> $dayHoursByDate
     * @return array{total_hours: float, regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float}
     */
    public function calculateDailyBreakdownForTenant(Tenant $tenant, array $dayHoursByDate): array
    {
        $rule = $this->resolver->resolveForTenant($tenant);

        $total = 0.0;
        $regular = 0.0;
        $ot1_5 = 0.0;
        $ot2_0 = 0.0;

        foreach ($dayHoursByDate as $hours) {
            $dayHours = max(0.0, (float) $hours);
            $total += $dayHours;

            if ($rule && method_exists($rule, 'splitDayHours')) {
                /** @var array{regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float} $split */
                $split = $rule->splitDayHours($dayHours);
                $regular += (float) $split['regular_hours'];
                $ot1_5 += (float) $split['overtime_hours_1_5'];
                $ot2_0 += (float) $split['overtime_hours_2_0'];
            } else {
                $regular += $dayHours;
            }
        }

        return [
            'total_hours' => $total,
            'regular_hours' => $regular,
            'overtime_hours_1_5' => $ot1_5,
            'overtime_hours_2_0' => $ot2_0,
        ];
    }
}
