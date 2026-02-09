<?php

declare(strict_types=1);

namespace App\Services\TimesheetAi;

use App\Models\Location;
use App\Models\Project;
use App\Models\Task;
use App\Models\Technician;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\TenantResolver;
use Carbon\Carbon;

class TimesheetPlanValidator
{
    /**
     * @param array<string, mixed> $plan
     * @return array{ok: bool, errors: string[], warnings: string[], normalized_plan: array<string, mixed>|null, totals: array<string, mixed>|null}
     */
    public function validate(array $plan, User $actor, Technician $technician, User $targetUser, bool $enforceBreaks): array
    {
        $errors = [];
        $warnings = [];

        if (!$actor->hasPermissionTo('create-timesheets')) {
            $errors[] = 'You do not have permission to create timesheets.';
        }

        $days = $plan['days'] ?? [];
        if (!is_array($days) || empty($days)) {
            $errors[] = 'No days were generated for this plan.';
        }

        $normalizedDays = [];
        $totalsByDay = [];
        $overallMinutes = 0;

        foreach ($days as $day) {
            $date = (string) ($day['date'] ?? '');
            if ($date === '') {
                $errors[] = 'Missing date in plan.';
                continue;
            }

            $entries = is_array($day['entries'] ?? null) ? $day['entries'] : [];
            if (empty($entries)) {
                $warnings[] = sprintf('No entries found for %s.', $date);
            }

            $normalizedEntries = [];
            $planMinutes = 0;
            $entryRanges = [];

            foreach ($entries as $entry) {
                $projectId = (int) ($entry['project_id'] ?? 0);
                $project = $projectId ? Project::find($projectId) : null;

                if (!$project) {
                    $errors[] = sprintf('Project %s not found for %s.', (string) ($entry['project_name'] ?? 'unknown'), $date);
                    continue;
                }

                if (!$project->isUserMember($targetUser)) {
                    $errors[] = sprintf('User is not assigned to project "%s" (%s).', $project->name, $date);
                    continue;
                }

                $timeRange = $this->normalizeTimeRange($entry, $date, $errors);
                if (!$timeRange) {
                    continue;
                }

                $task = $this->resolveTask($project, $entry, $errors, $date);
                $location = $this->resolveLocation($task, $entry, $errors, $date);

                if (!$task || !$location) {
                    continue;
                }

                $minutes = $timeRange['minutes'];
                $planMinutes += $minutes;
                $overallMinutes += $minutes;

                $entryRanges[] = [
                    'start' => $timeRange['start_minutes'],
                    'end' => $timeRange['end_minutes'],
                ];

                $normalizedEntries[] = [
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'task_id' => $task->id,
                    'task_name' => $task->name,
                    'location_id' => $location->id,
                    'location_name' => $location->name,
                    'date' => $date,
                    'start_time' => $timeRange['start_time'],
                    'end_time' => $timeRange['end_time'],
                    'minutes' => $minutes,
                    'notes' => $entry['notes'] ?? null,
                ];
            }

            $this->validateNoOverlap($entryRanges, $date, $errors);
            $this->validateAgainstExisting($technician->id, $date, $entryRanges, $planMinutes, $errors);

            $dailyHours = $planMinutes / 60;
            $totalsByDay[$date] = [
                'minutes' => $planMinutes,
                'hours' => round($dailyHours, 2),
            ];

            $normalizedEntries = $this->sortEntriesByStart($normalizedEntries);
            $this->validateBreaks($normalizedEntries, $date, $warnings, $errors, $enforceBreaks);

            $normalizedDays[] = [
                'date' => $date,
                'entries' => $normalizedEntries,
            ];
        }

        $normalizedPlan = [
            'prompt' => $plan['prompt'] ?? null,
            'timezone' => $plan['timezone'] ?? null,
            'target_user_id' => $targetUser->id,
            'technician_id' => $technician->id,
            'days' => $normalizedDays,
        ];

        $totals = [
            'overall_minutes' => $overallMinutes,
            'overall_hours' => round($overallMinutes / 60, 2),
            'per_day' => $totalsByDay,
        ];

        return [
            'ok' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'normalized_plan' => $normalizedPlan,
            'totals' => $totals,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param string[] $errors
     * @return array{start_time: string, end_time: string, minutes: int, start_minutes: int, end_minutes: int}|null
     */
    private function normalizeTimeRange(array $entry, string $date, array &$errors): ?array
    {
        $start = (string) ($entry['start_time'] ?? '');
        $end = (string) ($entry['end_time'] ?? '');

        if ($start === '' || $end === '') {
            $errors[] = sprintf('Missing time range for %s.', $date);
            return null;
        }

        $startMinutes = $this->toMinutes($start);
        $endMinutes = $this->toMinutes($end);

        if ($startMinutes === null || $endMinutes === null) {
            $errors[] = sprintf('Invalid time range %s-%s on %s.', $start, $end, $date);
            return null;
        }

        if ($endMinutes <= $startMinutes) {
            $errors[] = sprintf('End time must be after start time for %s (%s-%s).', $date, $start, $end);
            return null;
        }

        return [
            'start_time' => $start,
            'end_time' => $end,
            'minutes' => $endMinutes - $startMinutes,
            'start_minutes' => $startMinutes,
            'end_minutes' => $endMinutes,
        ];
    }

    private function resolveTask(Project $project, array $entry, array &$errors, string $date): ?Task
    {
        $taskId = isset($entry['task_id']) ? (int) $entry['task_id'] : 0;

        if ($taskId > 0) {
            $task = Task::where('id', $taskId)->where('project_id', $project->id)->first();
            if ($task) {
                return $task;
            }

            $errors[] = sprintf('Task %d is not part of project "%s" (%s).', $taskId, $project->name, $date);
            return null;
        }

        $task = Task::where('project_id', $project->id)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        if (!$task) {
            $errors[] = sprintf('Project "%s" has no tasks (%s).', $project->name, $date);
            return null;
        }

        return $task;
    }

    private function resolveLocation(?Task $task, array $entry, array &$errors, string $date): ?Location
    {
        if (!$task) {
            return null;
        }

        $locationId = isset($entry['location_id']) ? (int) $entry['location_id'] : 0;

        if ($locationId > 0) {
            $location = Location::find($locationId);
            if ($location) {
                return $location;
            }

            $errors[] = sprintf('Location %d not found (%s).', $locationId, $date);
            return null;
        }

        $location = $task->locations()->orderBy('locations.id')->first();
        if ($location) {
            return $location;
        }

        $fallback = Location::query()->orderByDesc('is_active')->orderBy('id')->first();
        if ($fallback) {
            return $fallback;
        }

        $errors[] = sprintf('No locations available for %s.', $date);
        return null;
    }

    /**
     * @param array<int, array{start: int, end: int}> $ranges
     */
    private function validateNoOverlap(array $ranges, string $date, array &$errors): void
    {
        if (count($ranges) < 2) {
            return;
        }

        usort($ranges, fn($a, $b) => $a['start'] <=> $b['start']);

        for ($i = 1; $i < count($ranges); $i += 1) {
            $prev = $ranges[$i - 1];
            $current = $ranges[$i];

            if ($current['start'] < $prev['end']) {
                $errors[] = sprintf('Overlapping time ranges detected on %s.', $date);
                return;
            }
        }
    }

    /**
     * @param array<int, array{start: int, end: int}> $ranges
     */
    private function validateAgainstExisting(int $technicianId, string $date, array $ranges, int $planMinutes, array &$errors): void
    {
        $existing = Timesheet::on('tenant')
            ->where('technician_id', $technicianId)
            ->whereDate('date', $date)
            ->get(['id', 'start_time', 'end_time', 'status', 'hours_worked']);

        if ($existing->whereIn('status', ['approved', 'closed'])->isNotEmpty()) {
            $errors[] = sprintf('Date %s is locked by approved/closed entries.', $date);
            return;
        }

        foreach ($existing as $entry) {
            if ($this->isMissingTime($entry)) {
                $this->logOverlapValidationIssue('missing_time', $date, $existing);
                $errors[] = sprintf('Cannot validate overlaps on %s due to existing entries without time.', $date);
                return;
            }
        }

        foreach ($ranges as $range) {
            foreach ($existing as $entry) {
                $startMinutes = $this->toMinutes((string) $entry->start_time);
                $endMinutes = $this->toMinutes((string) $entry->end_time);

                if ($startMinutes === null || $endMinutes === null) {
                    $this->logOverlapValidationIssue('unsupported_time', $date, $existing);
                    $errors[] = sprintf(
                        'Cannot validate overlaps on %s due to unsupported existing entry data (invalid time format).',
                        $date
                    );
                    return;
                }

                if ($range['start'] < $endMinutes && $startMinutes < $range['end']) {
                    $errors[] = sprintf('Overlaps with existing entry on %s.', $date);
                    return;
                }
            }
        }

        $existingMinutes = (int) round($existing->sum('hours_worked') * 60);
        $totalMinutes = $existingMinutes + $planMinutes;
        $dailyCap = (float) config('timesheets.daily_hour_cap', 12);

        if ($totalMinutes > (int) round($dailyCap * 60)) {
            $errors[] = sprintf('Daily total exceeds %.0f hours on %s.', $dailyCap, $date);
        }
    }

    private function isMissingTime(Timesheet $entry): bool
    {
        $start = $entry->start_time;
        $end = $entry->end_time;

        if ($start === null || $end === null) {
            return true;
        }

        if (trim((string) $start) === '' || trim((string) $end) === '') {
            return true;
        }

        return false;
    }

    /**
     * @param \Illuminate\Support\Collection<int, Timesheet> $entries
     */
    private function logOverlapValidationIssue(string $reason, string $date, $entries): void
    {
        if (!config('app.debug') || !env('AI_TIMESHEET_DEBUG')) {
            return;
        }

        $connection = Timesheet::query()->getConnectionName();

        \Log::debug('[AI_TIMESHEET] overlap validation issue', [
            'reason' => $reason,
            'tenant_id' => TenantResolver::getTenantId(),
            'connection' => $connection,
            'date' => $date,
            'existing_count' => $entries->count(),
            'entries' => $entries->map(fn (Timesheet $entry) => [
                'id' => $entry->id,
                'start_time' => $entry->start_time,
                'end_time' => $entry->end_time,
                'hours_worked' => $entry->hours_worked,
                'duration' => $entry->getAttribute('duration'),
                'status' => $entry->status,
                'deleted_at' => $entry->getAttribute('deleted_at'),
            ])->values()->all(),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function sortEntriesByStart(array $entries): array
    {
        usort($entries, fn($a, $b) => $this->toMinutes((string) $a['start_time']) <=> $this->toMinutes((string) $b['start_time']));
        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param string[] $warnings
     * @param string[] $errors
     */
    private function validateBreaks(array $entries, string $date, array &$warnings, array &$errors, bool $enforceBreaks): void
    {
        if (count($entries) < 1) {
            return;
        }

        $breakAfterMinutes = (int) round((float) config('timesheets.break_required_after_hours', 6) * 60);
        $breakMinMinutes = (int) config('timesheets.break_min_minutes', 30);

        $currentBlockStart = null;
        $currentBlockEnd = null;
        $maxContinuous = 0;

        foreach ($entries as $entry) {
            $start = $this->toMinutes((string) $entry['start_time']);
            $end = $this->toMinutes((string) $entry['end_time']);

            if ($start === null || $end === null) {
                continue;
            }

            if ($currentBlockStart === null) {
                $currentBlockStart = $start;
                $currentBlockEnd = $end;
                $maxContinuous = max($maxContinuous, $currentBlockEnd - $currentBlockStart);
                continue;
            }

            $gap = $start - $currentBlockEnd;

            if ($gap >= $breakMinMinutes) {
                $currentBlockStart = $start;
                $currentBlockEnd = $end;
            } else {
                $currentBlockEnd = max($currentBlockEnd, $end);
            }

            $maxContinuous = max($maxContinuous, $currentBlockEnd - $currentBlockStart);
        }

        if ($maxContinuous > $breakAfterMinutes) {
            $message = sprintf('Break required for continuous work over %.1f hours on %s.', $breakAfterMinutes / 60, $date);
            if ($enforceBreaks) {
                $errors[] = $message;
            } else {
                $warnings[] = $message;
            }
        }
    }

    private function toMinutes(string $time): ?int
    {
        $clean = trim($time);
        if ($clean === '') {
            return null;
        }

        $formats = ['H:i', 'H:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $clean);
                return ((int) $parsed->format('H')) * 60 + (int) $parsed->format('i');
            } catch (\Throwable $e) {
                continue;
            }
        }

        try {
            $parsed = Carbon::parse($clean);
        } catch (\Throwable $e) {
            return null;
        }

        return ((int) $parsed->format('H')) * 60 + (int) $parsed->format('i');
    }
}
