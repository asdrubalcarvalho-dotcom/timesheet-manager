<?php

declare(strict_types=1);

namespace App\Data;

final class AiTimesheetPlanBreak
{
    public function __construct(
        public string $startTime,
        public string $endTime
    ) {
    }

    /**
     * @return array{start_time: string, end_time: string}
     */
    public function toArray(): array
    {
        return [
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
        ];
    }
}
