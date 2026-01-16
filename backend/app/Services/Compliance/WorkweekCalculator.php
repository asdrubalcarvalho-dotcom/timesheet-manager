<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantWeekConfig;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class WorkweekCalculator
{
    public function __construct(private readonly TenantWeekConfig $weekConfig)
    {
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function periodForDate(Tenant $tenant, TenantContext $context, CarbonInterface $date): array
    {
        $tz = $context->timezone;

        $local = CarbonImmutable::parse($date->toDateString(), $tz)->startOfDay();

        $startIndex = $this->weekConfig->weekStartsOnIndex($tenant, $context->locale);
        $currentIndex = $local->dayOfWeek; // 0=Sunday

        $daysSinceStart = ($currentIndex - $startIndex + 7) % 7;
        $start = $local->subDays($daysSinceStart)->startOfDay();
        $end = $start->addDays(6)->endOfDay();

        return ['start' => $start, 'end' => $end];
    }
}
