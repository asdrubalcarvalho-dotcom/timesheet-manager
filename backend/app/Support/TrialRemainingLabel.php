<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class TrialRemainingLabel
{
    private const SECONDS_PER_DAY = 86400;

    /**
     * Option C: Human-friendly trial remaining label.
     *
     * All calculations derive solely from the absolute `trialEndsAt` timestamp.
     */
    public static function getTrialRemainingLabel(string|DateTimeInterface $trialEndsAt, ?DateTimeInterface $now = null): string
    {
        $end = $trialEndsAt instanceof DateTimeInterface
            ? CarbonImmutable::instance($trialEndsAt)
            : CarbonImmutable::parse($trialEndsAt);

        $end = $end->utc();

        $nowCarbon = $now
            ? CarbonImmutable::instance($now)->utc()
            : CarbonImmutable::now('UTC');

        if ($end->lessThanOrEqualTo($nowCarbon)) {
            return 'Trial expired';
        }

        $diffSeconds = $nowCarbon->diffInSeconds($end);

        if ($diffSeconds < self::SECONDS_PER_DAY) {
            return 'Ends today';
        }

        if ($diffSeconds < 2 * self::SECONDS_PER_DAY) {
            return 'Ends tomorrow';
        }

        $daysLeft = (int) ceil($diffSeconds / self::SECONDS_PER_DAY);

        return $daysLeft . ' days left';
    }
}
