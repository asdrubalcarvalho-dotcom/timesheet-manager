<?php

declare(strict_types=1);

namespace App\Services\Compliance\US\Hooks\Defaults;

use App\Services\Compliance\US\Hooks\SeventhDayApplier;
use App\Services\Compliance\US\States\CAOvertimeRule;

final class DefaultSeventhDayApplier implements SeventhDayApplier
{
    public function apply(CAOvertimeRule $rule, float $dayHours): array
    {
        return $rule->splitSeventhDayHours($dayHours);
    }
}
