<?php

declare(strict_types=1);

namespace App\Data;

final class AiTimesheetPlanWorkBlock
{
    public function __construct(
        public string $startTime,
        public string $endTime,
        public ?int $projectId,
        public ?string $projectName,
        public ?int $taskId,
        public ?string $taskName,
        public ?int $locationId,
        public ?string $locationName,
        public ?string $notes = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'project' => ['id' => $this->projectId, 'name' => $this->projectName],
            'task' => ['id' => $this->taskId, 'name' => $this->taskName],
            'location' => ['id' => $this->locationId, 'name' => $this->locationName],
            'notes' => $this->notes,
        ];
    }
}
