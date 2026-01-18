<?php

declare(strict_types=1);

namespace App\Services\Compliance\US\Hooks;

interface SeventhDayQualifier
{
    /**
     * @param array<string, float> $normalizedDayHoursByDate
     */
    public function seventhDayKey(array $normalizedDayHoursByDate): ?string;
}
