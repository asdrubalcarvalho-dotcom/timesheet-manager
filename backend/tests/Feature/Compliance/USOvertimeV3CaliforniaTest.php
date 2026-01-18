<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Tenant;
use App\Services\Compliance\OvertimeCalculator;
use App\Services\Compliance\OvertimeRuleResolver;
use Tests\TestCase;

class USOvertimeV3CaliforniaTest extends TestCase
{
    private function makeCalculator(): OvertimeCalculator
    {
        return new OvertimeCalculator(new OvertimeRuleResolver());
    }

    private function makeTenant(array $settings): Tenant
    {
        $tenant = new Tenant();
        $tenant->forceFill([
            'settings' => $settings,
        ]);

        return $tenant;
    }

    public function test_ca_13h_single_day_splits_correctly(): void
    {
        $tenant = $this->makeTenant([
            'region' => 'US',
            'state' => 'CA',
        ]);

        $result = $this->makeCalculator()->calculateWeekBreakdownForTenant($tenant, [
            '2026-01-12' => 13,
        ]);

        $this->assertEquals(13.0, $result['total_hours']);
        $this->assertEquals(8.0, $result['regular_hours']);
        $this->assertEquals(4.0, $result['overtime_hours_1_5']);
        $this->assertEquals(1.0, $result['overtime_hours_2_0']);
    }

    public function test_ca_seventh_consecutive_working_day_rule_applies(): void
    {
        $tenant = $this->makeTenant([
            'region' => 'US',
            'state' => 'CA',
        ]);

        // 7 consecutive working days within the same workweek.
        // Day 7 should be: first 8h @1.5x (no regular).
        $dayHoursByDate = [
            '2026-01-11' => 8,
            '2026-01-12' => 8,
            '2026-01-13' => 8,
            '2026-01-14' => 8,
            '2026-01-15' => 8,
            '2026-01-16' => 8,
            '2026-01-17' => 8,
        ];

        $result = $this->makeCalculator()->calculateWeekBreakdownForTenant($tenant, $dayHoursByDate);

        // Daily splits yield: 6 days regular (48h) + 7th day OT1.5 (8h) => 56 total.
        // Weekly excess is 16h, converted ONLY from remaining regular hours.
        $this->assertEquals(56.0, $result['total_hours']);
        $this->assertEquals(32.0, $result['regular_hours']);
        $this->assertEquals(24.0, $result['overtime_hours_1_5']);
        $this->assertEquals(0.0, $result['overtime_hours_2_0']);
    }

    public function test_ca_combination_daily_and_weekly_overtime_no_double_counting(): void
    {
        $tenant = $this->makeTenant([
            'region' => 'US',
            'state' => 'CA',
        ]);

        // Total 45h week with some daily OT already.
        // 4 days of 10h => each day 8 regular + 2 OT1.5 (daily)
        // 1 day of 5h => regular
        // Daily totals: regular 37h, OT1.5 8h. Weekly excess is 5h.
        // Convert ONLY 5h from remaining regular => regular 32h, OT1.5 13h.
        $dayHoursByDate = [
            '2026-01-12' => 10,
            '2026-01-13' => 10,
            '2026-01-14' => 10,
            '2026-01-15' => 10,
            '2026-01-16' => 5,
        ];

        $result = $this->makeCalculator()->calculateWeekBreakdownForTenant($tenant, $dayHoursByDate);

        $this->assertEquals(45.0, $result['total_hours']);
        $this->assertEquals(32.0, $result['regular_hours']);
        $this->assertEquals(13.0, $result['overtime_hours_1_5']);
        $this->assertEquals(0.0, $result['overtime_hours_2_0']);
    }
}
