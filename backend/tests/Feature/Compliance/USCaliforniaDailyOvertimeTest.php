<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Tenant;
use App\Services\Compliance\OvertimeCalculator;
use App\Services\Compliance\OvertimeRuleResolver;
use Tests\TestCase;

class USCaliforniaDailyOvertimeTest extends TestCase
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

    public function test_ca_10h_one_day_yields_8_regular_2_ot_1_5(): void
    {
        $tenant = $this->makeTenant([
            'region' => 'US',
            'state' => 'CA',
        ]);

        $result = $this->makeCalculator()->calculateDailyBreakdownForTenant($tenant, [
            '2026-01-12' => 10,
        ]);

        $this->assertEquals(10.0, $result['total_hours']);
        $this->assertEquals(8.0, $result['regular_hours']);
        $this->assertEquals(2.0, $result['overtime_hours_1_5']);
        $this->assertEquals(0.0, $result['overtime_hours_2_0']);
    }

    public function test_ca_13h_one_day_yields_8_regular_4_ot_1_5_1_ot_2_0(): void
    {
        $tenant = $this->makeTenant([
            'region' => 'US',
            'state' => 'CA',
        ]);

        $result = $this->makeCalculator()->calculateDailyBreakdownForTenant($tenant, [
            '2026-01-12' => 13,
        ]);

        $this->assertEquals(13.0, $result['total_hours']);
        $this->assertEquals(8.0, $result['regular_hours']);
        $this->assertEquals(4.0, $result['overtime_hours_1_5']);
        $this->assertEquals(1.0, $result['overtime_hours_2_0']);
    }

    public function test_ca_multiple_days_daily_ot_but_under_40h_week_still_applies_daily(): void
    {
        $tenant = $this->makeTenant([
            'region' => 'US',
            'state' => 'CA',
        ]);

        $result = $this->makeCalculator()->calculateDailyBreakdownForTenant($tenant, [
            '2026-01-12' => 10,
            '2026-01-13' => 10,
            '2026-01-14' => 10,
        ]);

        $this->assertEquals(30.0, $result['total_hours']);
        $this->assertEquals(24.0, $result['regular_hours']);
        $this->assertEquals(6.0, $result['overtime_hours_1_5']);
        $this->assertEquals(0.0, $result['overtime_hours_2_0']);
    }

    public function test_eu_tenant_has_no_daily_overtime(): void
    {
        $tenant = $this->makeTenant([
            'region' => 'EU',
        ]);

        $result = $this->makeCalculator()->calculateDailyBreakdownForTenant($tenant, [
            '2026-01-12' => 13,
        ]);

        $this->assertEquals(13.0, $result['total_hours']);
        $this->assertEquals(13.0, $result['regular_hours']);
        $this->assertEquals(0.0, $result['overtime_hours_1_5']);
        $this->assertEquals(0.0, $result['overtime_hours_2_0']);
    }

    public function test_us_non_ca_tenant_has_no_daily_overtime(): void
    {
        $tenant = $this->makeTenant([
            'region' => 'US',
            'state' => 'NY',
        ]);

        $result = $this->makeCalculator()->calculateDailyBreakdownForTenant($tenant, [
            '2026-01-12' => 13,
        ]);

        // Federal/other state rules: only weekly OT, so a 13h week is all regular.
        $this->assertEquals(13.0, $result['total_hours']);
        $this->assertEquals(13.0, $result['regular_hours']);
        $this->assertEquals(0.0, $result['overtime_hours_1_5']);
        $this->assertEquals(0.0, $result['overtime_hours_2_0']);
    }
}
