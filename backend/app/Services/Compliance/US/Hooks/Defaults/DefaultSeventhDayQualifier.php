<?php

declare(strict_types=1);

namespace App\Services\Compliance\US\Hooks\Defaults;

use App\Services\Compliance\US\Hooks\SeventhDayQualifier;

final class DefaultSeventhDayQualifier implements SeventhDayQualifier
{
    public function seventhDayKey(array $normalizedDayHoursByDate): ?string
    {
        if (count($normalizedDayHoursByDate) !== 7) {
            return null;
        }

        foreach ($normalizedDayHoursByDate as $hours) {
            if ($hours <= 0.0) {
                return null;
            }
        }

        $keys = array_keys($normalizedDayHoursByDate);

        return $keys === [] ? null : (string) end($keys);
    }
}
