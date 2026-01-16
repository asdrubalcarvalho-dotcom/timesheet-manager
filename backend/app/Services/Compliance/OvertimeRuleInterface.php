<?php

declare(strict_types=1);

namespace App\Services\Compliance;

interface OvertimeRuleInterface
{
    public function overtimeThresholdHours(): float;

    public function overtimeRateMultiplier(): float;

    /**
     * @return array{regular_hours: float, overtime_hours: float, overtime_rate: float}
     */
    public function splitWeekHours(float $totalHours): array;
}
