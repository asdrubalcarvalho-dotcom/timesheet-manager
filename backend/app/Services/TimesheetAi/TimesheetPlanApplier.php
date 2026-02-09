<?php

declare(strict_types=1);

namespace App\Services\TimesheetAi;

use App\Models\Timesheet;
use App\Models\User;

class TimesheetPlanApplier
{
    /**
     * @param array<string, mixed> $normalizedPlan
     * @return array{created_ids: int[], created_count: int}
     */
    public function apply(array $normalizedPlan, User $actor): array
    {
        $createdIds = [];
        $days = $normalizedPlan['days'] ?? [];

        foreach ($days as $day) {
            $date = (string) ($day['date'] ?? '');
            $entries = is_array($day['entries'] ?? null) ? $day['entries'] : [];

            foreach ($entries as $entry) {
                $minutes = (int) ($entry['minutes'] ?? 0);

                $timesheet = Timesheet::create([
                    'technician_id' => (int) ($normalizedPlan['technician_id'] ?? 0),
                    'project_id' => (int) ($entry['project_id'] ?? 0),
                    'task_id' => (int) ($entry['task_id'] ?? 0),
                    'location_id' => (int) ($entry['location_id'] ?? 0),
                    'date' => $date,
                    'start_time' => $entry['start_time'] ?? null,
                    'end_time' => $entry['end_time'] ?? null,
                    'hours_worked' => round($minutes / 60, 2),
                    'description' => $entry['notes'] ?? null,
                    'status' => 'draft',
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $createdIds[] = $timesheet->id;
            }
        }

        return [
            'created_ids' => $createdIds,
            'created_count' => count($createdIds),
        ];
    }
}
