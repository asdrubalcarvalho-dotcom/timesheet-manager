<?php

declare(strict_types=1);

namespace App\Data;

final class AiTimesheetPlanRange
{
    public function __construct(
        public string $startDate,
        public string $endDate
    ) {
    }

    /**
     * @return array{start_date: string, end_date: string}
     */
    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }
}
