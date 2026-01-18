<?php

declare(strict_types=1);

namespace App\Services\Compliance\US\Hooks;

use App\Services\Compliance\US\States\CAOvertimeRule;

interface SeventhDayApplier
{
    /**
     * @return array{regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float}
     */
    public function apply(CAOvertimeRule $rule, float $dayHours): array;
}
