<?php

declare(strict_types=1);

namespace App\Data;

final class AiTimesheetIntent
{
    /**
     * @param AiTimesheetIntentScheduleBlock[] $schedule
     * @param AiTimesheetIntentBreakBlock[] $breaks
     * @param string[] $missingFields
     */
    public function __construct(
        public string $intent,
        public ?AiTimesheetIntentDateRange $dateRange,
        public array $schedule,
        public array $breaks,
        public ?string $project,
        public ?string $task,
        public ?string $description,
        public ?string $location,
        public ?string $notes,
        public array $missingFields
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $schedule = [];
        $scheduleSource = $data['schedule'] ?? $data['schedule_blocks'] ?? $data['scheduleBlocks'] ?? [];
        foreach ($scheduleSource as $item) {
            if (is_array($item)) {
                $schedule[] = AiTimesheetIntentScheduleBlock::fromArray($item);
            }
        }

        $breaks = [];
        foreach (($data['breaks'] ?? []) as $item) {
            if (is_array($item)) {
                $breaks[] = AiTimesheetIntentBreakBlock::fromArray($item);
            }
        }

        $missing = [];
        foreach (($data['missing_fields'] ?? []) as $item) {
            $missing[] = (string) $item;
        }

        return new self(
            intent: (string) ($data['intent'] ?? ''),
            dateRange: isset($data['date_range']) && is_array($data['date_range'])
                ? AiTimesheetIntentDateRange::fromArray($data['date_range'])
                : null,
            schedule: $schedule,
            breaks: $breaks,
            project: isset($data['project']) ? (string) $data['project'] : null,
            task: isset($data['task']) ? (string) $data['task'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            location: isset($data['location']) ? (string) $data['location'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            missingFields: $missing
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'date_range' => $this->dateRange?->toArray(),
            'schedule' => array_map(fn (AiTimesheetIntentScheduleBlock $block) => $block->toArray(), $this->schedule),
            'breaks' => array_map(fn (AiTimesheetIntentBreakBlock $block) => $block->toArray(), $this->breaks),
            'project' => $this->project,
            'task' => $this->task,
            'description' => $this->description,
            'location' => $this->location,
            'notes' => $this->notes,
            'missing_fields' => $this->missingFields,
        ];
    }
}
