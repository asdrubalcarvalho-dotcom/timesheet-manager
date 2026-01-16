<?php

declare(strict_types=1);

namespace App\Services\Compliance\US;

use App\Services\Compliance\OvertimeRuleInterface;

class FederalOvertimeRule implements OvertimeRuleInterface
{
    public function overtimeThresholdHours(): float
    {
        return 40.0;
    }

    public function overtimeRateMultiplier(): float
    {
        return 1.5;
    }

    public function splitWeekHours(float $totalHours): array
    {
        $threshold = $this->overtimeThresholdHours();

        $overtime = max(0.0, $totalHours - $threshold);
        $regular = max(0.0, $totalHours - $overtime);

        return [
            'regular_hours' => $regular,
            'overtime_hours' => $overtime,
            'overtime_rate' => $this->overtimeRateMultiplier(),
        ];
    }
}
