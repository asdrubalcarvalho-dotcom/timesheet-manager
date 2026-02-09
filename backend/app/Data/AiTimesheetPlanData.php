<?php

declare(strict_types=1);

namespace App\Data;

final class AiTimesheetPlanData
{
    /**
     * @param AiTimesheetPlanDay[] $days
     */
    public function __construct(
        public AiTimesheetPlanRange $range,
        public ?string $timezone,
        public array $days
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'range' => $this->range->toArray(),
            'timezone' => $this->timezone,
            'days' => array_map(fn (AiTimesheetPlanDay $day) => $day->toArray(), $this->days),
        ];
    }
}
