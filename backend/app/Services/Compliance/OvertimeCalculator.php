<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Tenant;
use App\Services\Compliance\US\States\CAOvertimeRule;

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
     * State-aware weekly overtime breakdown.
     *
     * For CA, applies:
     * - Daily overtime (8/12 split)
     * - 7th consecutive working day rule (within the tenant workweek)
     * - Weekly overtime combination rule (convert only remaining regular hours)
     *
     * For NY and Federal fallback, applies weekly-only overtime (40h @ 1.5x).
     *
     * @param array<string, float|int> $dayHoursByDate
     * @return array{total_hours: float, regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float}
     */
    public function calculateWeekBreakdownForTenant(Tenant $tenant, array $dayHoursByDate): array
    {
        $rule = $this->resolver->resolveForTenant($tenant);

        $total = 0.0;
        foreach ($dayHoursByDate as $hours) {
            $total += max(0.0, (float) $hours);
        }

        if (!$rule) {
            return [
                'total_hours' => $total,
                'regular_hours' => $total,
                'overtime_hours_1_5' => 0.0,
                'overtime_hours_2_0' => 0.0,
            ];
        }

        if ($rule instanceof CAOvertimeRule) {
            return $rule->splitWeekFromDays($dayHoursByDate);
        }

        // NY and Federal fallback: weekly-only overtime.
        $split = $rule->splitWeekHours($total);

        return [
            'total_hours' => $total,
            'regular_hours' => (float) $split['regular_hours'],
            'overtime_hours_1_5' => (float) $split['overtime_hours'],
            'overtime_hours_2_0' => 0.0,
        ];
    }

    /**
     * @param array<string, float|int> $dayHoursByDate
     * @return array{regular_hours: float, overtime_hours: float, overtime_rate: float, overtime_hours_2_0: float}
     */
    public function calculateWeekSummaryForTenant(Tenant $tenant, array $dayHoursByDate): array
    {
        $breakdown = $this->calculateWeekBreakdownForTenant($tenant, $dayHoursByDate);

        return [
            'regular_hours' => (float) $breakdown['regular_hours'],
            'overtime_hours' => (float) $breakdown['overtime_hours_1_5'],
            'overtime_rate' => 1.5,
            'overtime_hours_2_0' => (float) $breakdown['overtime_hours_2_0'],
        ];
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
