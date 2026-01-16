<?php

declare(strict_types=1);

namespace App\Support;

use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;

final class DateFormatter
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function date(CarbonInterface $dateTime): string
    {
        return $dateTime->copy()->timezone($this->context->timezone)->format($this->context->dateFormat);
    }

    public function time(CarbonInterface $dateTime): string
    {
        return $dateTime->copy()->timezone($this->context->timezone)->format($this->context->timeFormat);
    }

    public function dateTime(CarbonInterface $dateTime): string
    {
        return $this->date($dateTime) . ' ' . $this->time($dateTime);
    }
}
