<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Tenant;
use App\Services\Compliance\OvertimeCalculator;
use App\Services\Compliance\OvertimeRuleResolver;
use Tests\TestCase;

class USOvertimeV3CaliforniaGoldenTest extends TestCase
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

    public function test_ca_week_breakdown_golden_snapshot_matches_v3_1(): void
    {
        // Golden expected values taken from overtime-v3.1 baseline (commit e6e8567df3c4f05b99b2dd67781ad72302ff2531).
        // This test is intentionally strict to detect any behavior change during v3.2 skeleton refactors.

        $tenant = $this->makeTenant([
            'region' => 'US',
            'state' => 'CA',
        ]);

        $dayHoursByDate = [
            '2026-01-11' => 8,
            '2026-01-12' => 8,
            '2026-01-13' => 8,
            '2026-01-14' => 8,
            '2026-01-15' => 8,
            '2026-01-16' => 8,
            '2026-01-17' => 13,
        ];

        $result = $this->makeCalculator()->calculateWeekBreakdownForTenant($tenant, $dayHoursByDate);

        $this->assertEquals([
            'total_hours' => 61.0,
            'regular_hours' => 27.0,
            'overtime_hours_1_5' => 29.0,
            'overtime_hours_2_0' => 5.0,
        ], $result);
    }
}
