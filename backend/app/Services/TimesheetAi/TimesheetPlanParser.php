<?php

declare(strict_types=1);

namespace App\Services\TimesheetAi;

use App\Models\Project;
use App\Models\Technician;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TimesheetPlanParser
{
    private const WEEK_STARTS = [
        'monday' => Carbon::MONDAY,
        'tuesday' => Carbon::TUESDAY,
        'wednesday' => Carbon::WEDNESDAY,
        'thursday' => Carbon::THURSDAY,
        'friday' => Carbon::FRIDAY,
        'saturday' => Carbon::SATURDAY,
        'sunday' => Carbon::SUNDAY,
    ];
    /**
     * @param array<string, mixed> $payload
     * @return array{plan: array<string, mixed>|null, errors: string[], warnings: string[]}
     */
    public function parse(array $payload, User $actor, Technician $technician, User $targetUser): array
    {
        $errors = [];
        $warnings = [];

        $intent = isset($payload['intent']) && is_array($payload['intent'])
            ? $payload['intent']
            : null;

        $prompt = trim((string) ($payload['prompt'] ?? ''));
        if ($prompt === '' && !$intent) {
            return [
                'plan' => null,
                'errors' => ['Prompt is required.'],
                'warnings' => [],
            ];
        }

        $timezone = (string) ($payload['timezone'] ?? config('app.timezone', 'UTC'));

        $range = $intent
            ? $this->resolveDateRangeFromIntent($intent, $timezone, $payload['week_start'] ?? null, $errors)
            : $this->resolveDateRange($payload, $prompt, $timezone, $errors);
        if (!$range) {
            return [
                'plan' => null,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        $range = $this->filterWeekdaysIfRequested($range, $prompt, $errors);
        if (empty($range)) {
            return [
                'plan' => null,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        $globalProject = $this->extractProjectName((string) ($payload['prompt'] ?? $prompt));
        if (is_array($intent) && $globalProject !== '' && empty($intent['project'])) {
            $intent['project'] = $globalProject;
        }

        $intentHints = $intent ? $this->extractIntentHints($intent) : [];
        if ($intent) {
            $intervalsFromIntent = $this->buildIntervalsFromIntent($intent, $errors);
            $intervalsFromPrompt = $this->parseIntervals($prompt, $errors, $intentHints);
            $intervals = $this->mergeIntervals($intervalsFromIntent, $intervalsFromPrompt);

            if (!empty($intervalsFromPrompt)) {
                $errors = array_values(array_filter($errors, static function (string $error): bool {
                    return !in_array($error, ['Project is required.', 'Schedule is required.'], true);
                }));
            }
        } else {
            $intervals = $this->parseIntervals($prompt, $errors, $intentHints);
        }
        if ($globalProject !== '') {
            $this->applyGlobalProjectToIntervals($intervals, $globalProject);
            $errors = array_values(array_filter($errors, static function (string $error): bool {
                return $error !== 'Project is required.';
            }));
        }
        if (empty($intervals)) {
            if (empty($errors)) {
                $errors[] = 'No time intervals found. Use HH:mm-HH:mm format.';
            }

            return [
                'plan' => null,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        $projects = $this->resolveProjects($intervals, $errors);
        if (!empty($errors)) {
            return [
                'plan' => null,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        $days = [];
        $breaks = [];
        foreach ($intervals as $interval) {
            if (!$interval['is_break']) {
                continue;
            }

            $breaks[] = [
                'start_time' => $interval['start_time'],
                'end_time' => $interval['end_time'],
            ];
        }

        foreach ($range as $date) {
            $entries = [];

            foreach ($intervals as $interval) {
                if ($interval['is_break']) {
                    continue;
                }

                $projectKey = $interval['project_key'];
                $project = $projects[$projectKey] ?? null;

                if (!$project) {
                    $errors[] = sprintf('Project not found for "%s".', $interval['project_name']);
                    continue;
                }

                $entries[] = [
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'start_time' => $interval['start_time'],
                    'end_time' => $interval['end_time'],
                    'notes' => $interval['notes'] ?? null,
                ];
            }

            $days[] = [
                'date' => $date,
                'entries' => $entries,
                'breaks' => $breaks,
            ];
        }

        if (!empty($errors)) {
            return [
                'plan' => null,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        return [
            'plan' => [
                'prompt' => $prompt,
                'timezone' => $timezone,
                'target_user_id' => $targetUser->id,
                'technician_id' => $technician->id,
                'days' => $days,
            ],
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @param string[] $errors
     * @return string[]|null
     */
    private function resolveDateRangeFromIntent(array $intent, string $timezone, ?string $weekStart, array &$errors): ?array
    {
        $range = $intent['date_range'] ?? null;
        if (!is_array($range)) {
            $errors[] = 'Date range is required.';
            return null;
        }

        $type = strtolower(trim((string) ($range['type'] ?? '')));
        if ($type === 'absolute') {
            $from = $range['from'] ?? null;
            $to = $range['to'] ?? null;
            if (!$from || !$to) {
                $errors[] = 'Date range is required.';
                return null;
            }

            try {
                $startDate = Carbon::parse((string) $from, $timezone)->startOfDay();
                $endDate = Carbon::parse((string) $to, $timezone)->startOfDay();
            } catch (\Throwable $e) {
                $errors[] = 'Invalid date range.';
                return null;
            }

            if ($endDate->lt($startDate)) {
                $errors[] = 'End date must be after start date.';
                return null;
            }

            return $this->expandDateRange($startDate, $endDate);
        }

        if ($type === 'relative') {
            $value = strtolower(trim((string) ($range['value'] ?? '')));
            if ($value === 'last_n_workdays') {
                $count = (int) ($range['count'] ?? 0);
                if ($count <= 0) {
                    $errors[] = 'Workdays count must be greater than zero.';
                    return null;
                }
                return $this->lastWorkdays($count, $timezone);
            }

            if (in_array($value, ['this_week', 'last_week', 'next_week'], true)) {
                $offset = $value === 'next_week' ? 1 : ($value === 'last_week' ? -1 : 0);
                $weekStartIndex = $this->resolveWeekStartIndex($weekStart);
                $startDate = Carbon::now($timezone)->startOfWeek($weekStartIndex)->addWeeks($offset);
                $endDate = $startDate->copy()->addDays(6);
                return $this->expandDateRange($startDate, $endDate);
            }
        }

        $errors[] = 'Invalid date range.';
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $errors
     * @return string[]|null
     */
    private function resolveDateRange(array $payload, string $prompt, string $timezone, array &$errors): ?array
    {
        $tokenRange = $this->matchDateRangeToken($prompt, $timezone, $errors);
        if ($tokenRange) {
            return $tokenRange;
        }

        $start = $payload['start_date'] ?? null;
        $end = $payload['end_date'] ?? null;

        if ($start || $end) {
            try {
                $startDate = $start ? Carbon::parse((string) $start, $timezone)->startOfDay() : null;
                $endDate = $end ? Carbon::parse((string) $end, $timezone)->startOfDay() : null;

                if (!$startDate && $endDate) {
                    $startDate = $endDate->copy();
                }

                if ($startDate && !$endDate) {
                    $endDate = $startDate->copy();
                }

                if ($startDate && $endDate && $endDate->lt($startDate)) {
                    $errors[] = 'End date must be after start date.';
                    return null;
                }

                return $this->expandDateRange($startDate, $endDate);
            } catch (\Throwable $e) {
                $errors[] = 'Invalid date range.';
                return null;
            }
        }

        if (preg_match('/last\s+(\d+)\s+workdays/i', $prompt, $matches)) {
            $count = (int) $matches[1];
            if ($count <= 0) {
                $errors[] = 'Workdays count must be greater than zero.';
                return null;
            }

            return $this->lastWorkdays($count, $timezone);
        }

        if (preg_match('/ultimos?\s+(\d+)\s+dias\s+uteis/i', $prompt, $matches)) {
            $count = (int) $matches[1];
            if ($count <= 0) {
                $errors[] = 'Workdays count must be greater than zero.';
                return null;
            }

            return $this->lastWorkdays($count, $timezone);
        }

        $relativeWeek = $this->matchPortugueseRelativeWeek(
            $prompt,
            $timezone,
            $payload['week_start'] ?? null
        );
        if ($relativeWeek) {
            return $relativeWeek;
        }

        $rangeMatch = $this->matchPromptDateRange($prompt, $timezone, $errors);
        if ($rangeMatch) {
            return $rangeMatch;
        }

        $errors[] = 'Provide a date range or "last N workdays" in the prompt.';
        return null;
    }

    /**
     * @param string[] $errors
     * @return string[]|null
     */
    private function matchDateRangeToken(string $prompt, string $timezone, array &$errors): ?array
    {
        if (!preg_match('/DATE_RANGE\s*=\s*(\d{4}-\d{2}-\d{2})\s*\.\.\s*(\d{4}-\d{2}-\d{2})/i', $prompt, $matches)) {
            return null;
        }

        try {
            $startDate = Carbon::parse($matches[1], $timezone)->startOfDay();
            $endDate = Carbon::parse($matches[2], $timezone)->startOfDay();
        } catch (\Throwable $e) {
            $errors[] = 'Invalid date range.';
            return null;
        }

        if ($endDate->lt($startDate)) {
            $errors[] = 'End date must be after start date.';
            return null;
        }

        return $this->expandDateRange($startDate, $endDate);
    }

    /**
     * @param string[] $errors
     * @return string[]|null
     */
    private function matchPromptDateRange(string $prompt, string $timezone, array &$errors): ?array
    {
        $patterns = [
            // from 2026-02-10 to 2026-02-14
            '/\bfrom\s+(\d{4}-\d{2}-\d{2})[\.,]?\s+to\s+(\d{4}-\d{2}-\d{2})[\.,]?\b/is',
            // de 2026-02-10 a 2026-02-14
            '/\bde\s+(\d{4}-\d{2}-\d{2})[\.,]?\s+a\s+(\d{4}-\d{2}-\d{2})[\.,]?\b/is',
            // de 2026-02-10 ate/até 2026-02-14
            '/\bde\s+(\d{4}-\d{2}-\d{2})[\.,]?\s+at(?:e|\x{00E9})\s+(\d{4}-\d{2}-\d{2})[\.,]?\b/isu',
            // 2026-02-10 to 2026-02-14
            '/\b(\d{4}-\d{2}-\d{2})[\.,]?\s+to\s+(\d{4}-\d{2}-\d{2})[\.,]?\b/is',
            // 2026-02-10 - 2026-02-14
            '/\b(\d{4}-\d{2}-\d{2})[\.,]?\s*-\s*(\d{4}-\d{2}-\d{2})[\.,]?\b/is',
            // between 2026-02-10 and 2026-02-14
            '/\bbetween\s+(\d{4}-\d{2}-\d{2})[\.,]?\s+and\s+(\d{4}-\d{2}-\d{2})[\.,]?\b/is',
            // entre 2026-02-10 e 2026-02-14
            '/\bentre\s+(\d{4}-\d{2}-\d{2})[\.,]?\s+e\s+(\d{4}-\d{2}-\d{2})[\.,]?\b/is',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $prompt, $matches)) {
                continue;
            }

            try {
                $startDate = Carbon::parse($matches[1], $timezone)->startOfDay();
                $endDate = Carbon::parse($matches[2], $timezone)->startOfDay();
            } catch (\Throwable $e) {
                $errors[] = 'Invalid date range.';
                return null;
            }

            if ($endDate->lt($startDate)) {
                $errors[] = 'End date must be after start date.';
                return null;
            }

            return $this->expandDateRange($startDate, $endDate);
        }

        return null;
    }

    /**
     * @return string[]|null
     */
    private function matchPortugueseRelativeWeek(string $prompt, string $timezone, ?string $weekStart): ?array
    {
        $normalized = $this->normalizePrompt($prompt);
        $weekOffset = null;

        if (preg_match('/\bproxima\s+semana\b/', $normalized)) {
            $weekOffset = 1;
        } elseif (preg_match('/\besta\s+semana\b/', $normalized)) {
            $weekOffset = 0;
        } elseif (preg_match('/\bsemana\s+passada\b/', $normalized) || preg_match('/\bultima\s+semana\b/', $normalized)) {
            $weekOffset = -1;
        }

        if ($weekOffset === null) {
            return null;
        }

        $weekStartIndex = $this->resolveWeekStartIndex($weekStart);
        $startDate = Carbon::now($timezone)->startOfWeek($weekStartIndex)->addWeeks($weekOffset);
        $endDate = $startDate->copy()->addDays(6);

        return $this->expandDateRange($startDate, $endDate);
    }

    private function resolveWeekStartIndex(?string $weekStart): int
    {
        $key = strtolower(trim((string) $weekStart));
        if ($key === '') {
            $key = 'monday';
        }

        return self::WEEK_STARTS[$key] ?? Carbon::MONDAY;
    }

    /**
     * @param string[] $range
     * @param string[] $errors
     * @return string[]
     */
    private function filterWeekdaysIfRequested(array $range, string $prompt, array &$errors): array
    {
        $normalized = $this->normalizePrompt($prompt);

        $weekdaysOnly = preg_match('/\b(mon|monday)\s*(?:-|to)\s*(fri|friday)\b/', $normalized)
            || preg_match('/\bseg\s*(?:-|a)\s*sex\b/', $normalized)
            || preg_match('/\bseg\s*(?:-|a)\s*sexta\b/', $normalized);

        if (!$weekdaysOnly) {
            return $range;
        }

        $filtered = [];
        foreach ($range as $date) {
            try {
                $day = Carbon::parse($date);
            } catch (\Throwable $e) {
                continue;
            }

            if ($day->isWeekday()) {
                $filtered[] = $day->toDateString();
            }
        }

        if (empty($filtered)) {
            $errors[] = 'No weekdays found in the requested range.';
        }

        return $filtered;
    }

    private function normalizePrompt(string $prompt): string
    {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($prompt, 'UTF-8')
            : strtolower($prompt);

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if ($ascii === false) {
            $ascii = $lower;
        }

        return preg_replace('/\s+/', ' ', $ascii) ?? $ascii;
    }

    /**
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @return string[]
     */
    private function expandDateRange(?Carbon $startDate, ?Carbon $endDate): array
    {
        if (!$startDate || !$endDate) {
            return [];
        }

        $dates = [];
        $cursor = $startDate->copy();

        while ($cursor->lte($endDate)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @return string[]
     */
    private function lastWorkdays(int $count, string $timezone): array
    {
        $dates = [];
        $cursor = Carbon::now($timezone)->startOfDay();

        while (count($dates) < $count) {
            if ($cursor->isWeekday()) {
                $dates[] = $cursor->toDateString();
            }
            $cursor->subDay();
        }

        return array_reverse($dates);
    }

    /**
     * @param array<int, array{start_time: string, end_time: string, project_name: string, project_key: string, project_raw: string, is_break: bool, notes?: string|null}> $intervals
     */
    private function applyGlobalProjectToIntervals(array &$intervals, string $project): void
    {
        $project = $this->normalizeProjectLabel($project);
        if ($project === '') {
            return;
        }

        $projectKey = strtolower($project);

        foreach ($intervals as &$interval) {
            if ($interval['is_break'] ?? false) {
                continue;
            }

            if (trim((string) ($interval['project_name'] ?? '')) !== '') {
                continue;
            }

            $interval['project_name'] = $project;
            $interval['project_key'] = $projectKey;
            $interval['project_raw'] = $project;
        }
        unset($interval);
    }

    /**
     * @param string[] $errors
     * @param array{project?: string, notes?: string|null} $intentHints
     * @return array<int, array{start_time: string, end_time: string, project_name: string, project_key: string, project_raw: string, is_break: bool, notes?: string|null}>
     */
    private function parseIntervals(string $prompt, array &$errors, array $intentHints = []): array
    {
        $prompt = $this->normalizeIntervalDashes($prompt);
        $prompt = $this->stripBlockLabels($prompt);
        $intervals = [];
        $pattern = '/(break\s*)?(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})(.*?)(?=\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}|$)/is';

        if (!preg_match_all($pattern, $prompt, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $fallbackProject = $this->extractProjectName($prompt);
        $builderProject = $this->resolveBuilderProjectName($prompt);
        $intentProject = trim((string) ($intentHints['project'] ?? ''));
        $intentNotes = $intentHints['notes'] ?? null;

        foreach ($matches as $match) {
            $isBreak = trim((string) $match[1]) !== '';
            $start = $match[2];
            $end = $match[3];
            $label = trim((string) $match[4]);

            $label = trim($label, " \t\n\r\0\x0B.");

            $labelLower = strtolower($label);
            if ($labelLower !== '' && (str_contains($labelLower, 'break') || str_contains($labelLower, 'lunch'))) {
                $isBreak = true;
            }

            if (!$this->isValidTime($start) || !$this->isValidTime($end)) {
                $errors[] = sprintf('Invalid time range "%s-%s".', $start, $end);
                continue;
            }

            $projectRaw = $this->normalizeProjectLabel($label);
            $projectLabel = $this->extractProjectName($label);

            if ($this->isConnectorLabel($projectLabel)) {
                $projectLabel = '';
                $projectRaw = '';
            }

            if (!$isBreak && $intentProject !== '') {
                $projectLabel = $intentProject;
                $projectRaw = $intentProject;
            } elseif (!$isBreak && $projectLabel === '' && $fallbackProject !== '') {
                $projectLabel = $fallbackProject;
                $projectRaw = $fallbackProject;
            } elseif (!$isBreak && $projectLabel === '' && $builderProject !== '') {
                $projectLabel = $builderProject;
                $projectRaw = $builderProject;
            }

            if (!$isBreak && $projectLabel === '') {
                $errors[] = sprintf('Missing project name for %s-%s.', $start, $end);
                continue;
            }

            $projectKey = strtolower($projectLabel);

            $intervals[] = [
                'start_time' => $start,
                'end_time' => $end,
                'project_name' => $projectLabel,
                'project_key' => $projectKey,
                'project_raw' => $projectRaw,
                'is_break' => $isBreak,
                'notes' => $intentNotes,
            ];
        }

        return $intervals;
    }

    private function normalizeIntervalDashes(string $prompt): string
    {
        return str_replace([
            "\u{2013}",
            "\u{2014}",
            "\u{2212}",
            "\u{2011}",
        ], '-', $prompt);
    }

    private function stripBlockLabels(string $prompt): string
    {
        return preg_replace('/\b(?:bloco|block)\s*\d+\s*:\s*/i', '', $prompt) ?? $prompt;
    }

    private function resolveBuilderProjectName(string $prompt): string
    {
        if (!$this->looksLikeBuilderPrompt($prompt)) {
            return '';
        }

        $projectName = $this->extractBuilderProject($prompt);
        if ($projectName === '') {
            return '';
        }

        $match = Project::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($projectName)])
            ->orderBy('id')
            ->first(['name']);

        return $match?->name ?? '';
    }

    private function looksLikeBuilderPrompt(string $prompt): bool
    {
        if (preg_match('/\bDATE_RANGE\s*=\s*\d{4}-\d{2}-\d{2}\s*\.\.\s*\d{4}-\d{2}-\d{2}\b/i', $prompt)) {
            return true;
        }

        if (preg_match('/^\s*(projeto|project|tarefa|task|descri[cç]ao|description|bloco|block)\s*[:=]/im', $prompt)) {
            return true;
        }

        return false;
    }

    private function extractBuilderProject(string $prompt): string
    {
        $normalized = $this->normalizeCurlyQuotes($prompt);
        if (!preg_match('/^\s*(projeto|project)\s*[:=]\s*["\']?(.+?)["\']?\s*$/im', $normalized, $matches)) {
            return '';
        }

        $project = trim((string) ($matches[2] ?? ''));
        $project = $this->stripOuterQuotes($project);

        return trim($project);
    }

    /**
     * @param array<int, array{start_time: string, end_time: string, project_key: string, is_break: bool}> $primary
     * @param array<int, array{start_time: string, end_time: string, project_key: string, is_break: bool}> $secondary
     * @return array<int, array{start_time: string, end_time: string, project_key: string, is_break: bool}>
     */
    private function mergeIntervals(array $primary, array $secondary): array
    {
        $merged = $primary;
        $seen = [];

        foreach ($primary as $interval) {
            $seen[$this->intervalKey($interval)] = true;
        }

        foreach ($secondary as $interval) {
            $key = $this->intervalKey($interval);
            if (isset($seen[$key])) {
                continue;
            }

            $merged[] = $interval;
            $seen[$key] = true;
        }

        return $merged;
    }

    /**
     * @param array{start_time: string, end_time: string, project_key: string, is_break: bool} $interval
     */
    private function intervalKey(array $interval): string
    {
        $projectKey = $interval['is_break'] ? 'break' : ($interval['project_key'] ?? '');

        return strtolower(sprintf(
            '%s-%s-%s-%d',
            $interval['start_time'] ?? '',
            $interval['end_time'] ?? '',
            $projectKey,
            $interval['is_break'] ? 1 : 0
        ));
    }

    /**
     * @param array<string, mixed> $intent
     * @param string[] $errors
     * @return array<int, array{start_time: string, end_time: string, project_name: string, project_key: string, project_raw: string, is_break: bool, notes?: string|null}>
     */
    private function buildIntervalsFromIntent(array $intent, array &$errors): array
    {
        $intervals = [];

        $project = $this->normalizeProjectLabel((string) ($intent['project'] ?? ''));
        if ($project === '') {
            $errors[] = 'Project is required.';
            return [];
        }

        $schedule = $intent['schedule'] ?? $intent['schedule_blocks'] ?? $intent['scheduleBlocks'] ?? [];
        if (!is_array($schedule) || empty($schedule)) {
            $errors[] = 'Schedule is required.';
            return [];
        }

        $notes = $this->mergeIntentNotes($intent);

        foreach ($schedule as $block) {
            if (!is_array($block)) {
                continue;
            }

            $start = (string) ($block['from'] ?? $block['start_time'] ?? '');
            $end = (string) ($block['to'] ?? $block['end_time'] ?? '');

            if (!$this->isValidTime($start) || !$this->isValidTime($end)) {
                $errors[] = sprintf('Invalid time range "%s-%s".', $start, $end);
                continue;
            }

            $intervals[] = [
                'start_time' => $start,
                'end_time' => $end,
                'project_name' => $project,
                'project_key' => strtolower($project),
                'project_raw' => $project,
                'is_break' => false,
                'notes' => $notes,
            ];
        }

        $breaks = $intent['breaks'] ?? [];
        if (is_array($breaks)) {
            foreach ($breaks as $block) {
                if (!is_array($block)) {
                    continue;
                }

                $start = (string) ($block['from'] ?? '');
                $end = (string) ($block['to'] ?? '');

                if (!$this->isValidTime($start) || !$this->isValidTime($end)) {
                    $errors[] = sprintf('Invalid time range "%s-%s".', $start, $end);
                    continue;
                }

                $intervals[] = [
                    'start_time' => $start,
                    'end_time' => $end,
                    'project_name' => '',
                    'project_key' => '',
                    'project_raw' => '',
                    'is_break' => true,
                ];
            }
        }

        return $intervals;
    }

    /**
     * @param array<string, mixed> $intent
     */
    private function mergeIntentNotes(array $intent): ?string
    {
        $description = trim((string) ($intent['description'] ?? ''));
        $notes = trim((string) ($intent['notes'] ?? ''));

        if ($description !== '' && $notes !== '') {
            return $description . ' - ' . $notes;
        }

        if ($notes !== '') {
            return $notes;
        }

        return $description !== '' ? $description : null;
    }

    /**
     * @param array<string, mixed> $intent
     * @return array{project?: string, notes?: string|null}
     */
    private function extractIntentHints(array $intent): array
    {
        $project = trim((string) ($intent['project'] ?? ''));
        $notes = $this->mergeIntentNotes($intent);

        $hints = [];
        if ($project !== '') {
            $hints['project'] = $project;
        }
        if ($notes !== null && $notes !== '') {
            $hints['notes'] = $notes;
        }

        return $hints;
    }

    /**
     * @param array<int, array{project_key: string, project_name: string, project_raw: string, is_break: bool}> $intervals
     * @param string[] $errors
     * @return array<string, Project>
     */
    private function resolveProjects(array $intervals, array &$errors): array
    {
        $projectKeys = [];
        $projectsByKey = [];

        foreach ($intervals as $interval) {
            if ($interval['is_break']) {
                continue;
            }

            $projectKeys[$interval['project_key']] = [
                'name' => $interval['project_name'],
                'raw' => $interval['project_raw'],
            ];
        }

        foreach ($projectKeys as $key => $projectInfo) {
            $name = $projectInfo['name'];
            $raw = $projectInfo['raw'];
            $nameNormalized = $this->normalizeProjectLabel($name);
            $rawNormalized = $this->normalizeProjectLabel($raw);
            $nameCurlyNormalized = $this->normalizeCurlyQuotes($name);
            $rawCurlyNormalized = $this->normalizeCurlyQuotes($raw);

            $matches = collect();
            $match = DB::connection('tenant')
                ->table('projects')
                ->select(['id', 'name'])
                ->whereRaw('LOWER(name) = ?', [strtolower($nameNormalized)])
                ->orderBy('id')
                ->first();
            if ($match) {
                $matches = collect([$this->projectFromRow($match)]);
            }

            if ($matches->isEmpty() && $rawNormalized !== $nameNormalized) {
                $match = DB::connection('tenant')
                    ->table('projects')
                    ->select(['id', 'name'])
                    ->whereRaw('LOWER(name) = ?', [strtolower($rawNormalized)])
                    ->orderBy('id')
                    ->first();
                if ($match) {
                    $matches = collect([$this->projectFromRow($match)]);
                }
            }

            if ($matches->isEmpty() && $nameCurlyNormalized !== $nameNormalized) {
                $match = DB::connection('tenant')
                    ->table('projects')
                    ->select(['id', 'name'])
                    ->whereRaw('LOWER(name) = ?', [strtolower($nameCurlyNormalized)])
                    ->orderBy('id')
                    ->first();
                if ($match) {
                    $matches = collect([$this->projectFromRow($match)]);
                }
            }

            if ($matches->isEmpty() && $rawCurlyNormalized !== $rawNormalized) {
                $match = DB::connection('tenant')
                    ->table('projects')
                    ->select(['id', 'name'])
                    ->whereRaw('LOWER(name) = ?', [strtolower($rawCurlyNormalized)])
                    ->orderBy('id')
                    ->first();
                if ($match) {
                    $matches = collect([$this->projectFromRow($match)]);
                }
            }

            if ($matches->isEmpty()) {
                $rawStripped = preg_replace('/^\s*(?:project|projeto)\s+/i', '', $rawNormalized) ?? $rawNormalized;
                if ($rawStripped !== $rawNormalized) {
                    $match = DB::connection('tenant')
                        ->table('projects')
                        ->select(['id', 'name'])
                        ->whereRaw('LOWER(name) = ?', [strtolower($rawStripped)])
                        ->orderBy('id')
                        ->first();
                    if ($match) {
                        $matches = collect([$this->projectFromRow($match)]);
                    }
                }
            }

            if ($matches->isEmpty()) {
                $matches = $this->findProjectsByNormalizedName($nameNormalized)
                    ?: $this->findProjectsByNormalizedName($rawNormalized);
            }

            if ($matches->isEmpty()) {
                $errors[] = sprintf('Project "%s" not found.', $nameNormalized);
                continue;
            }

            if ($matches->count() > 1) {
                $errors[] = sprintf('Project name "%s" is ambiguous: %s.', $nameNormalized, $matches->pluck('name')->implode(', '));
                continue;
            }

            $projectsByKey[$key] = $matches->first();
        }

        return $projectsByKey;
    }

    private function isValidTime(string $value): bool
    {
        try {
            Carbon::createFromFormat('H:i', $value);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    private function extractProjectName(string $label): string
    {
        $clean = $this->normalizeProjectLabel($label);
        if ($clean === '') {
            return '';
        }

        $quotedPattern = '/^\s*(?:project|projeto)\s*[:=]\s*["\']?(.+?)["\']?\s*$/im';
        if (preg_match($quotedPattern, $clean, $matches)) {
            return trim($matches[1]);
        }

        $raw = $clean;
        $matchedPrefix = false;
        if (preg_match('/\b(?:project|projeto)\s*[:=]\s*(.+)/i', $raw, $matches)) {
            $raw = $matches[1];
            $matchedPrefix = true;
        } elseif (preg_match('/\b(?:project|projeto)\s+(.+)/i', $raw, $matches)) {
            $raw = $matches[1];
            $matchedPrefix = true;
        }

        $raw = $this->trimProjectAtBoundary($raw);

        $raw = $this->normalizeProjectLabel($raw);
        if (!$matchedPrefix) {
            $raw = preg_replace('/^\s*(?:project|projeto)\s+/i', '', $raw) ?? $raw;
        }
        $raw = $this->stripOuterQuotes($raw);

        return trim($raw);
    }

    private function isConnectorLabel(string $label): bool
    {
        $normalized = $this->normalizePrompt($label);
        if ($normalized === '' || $normalized === ',') {
            return true;
        }

        if (in_array($normalized, ['e', 'and'], true)) {
            return true;
        }

        if (preg_match('/^(bloco|block)\s*\d+(?:\s*\/\s*\d+)?$/', $normalized)) {
            return true;
        }

        return false;
    }

    private function normalizeProjectLabel(string $value): string
    {
        $normalized = $this->normalizeCurlyQuotes($value);
        $normalized = trim($normalized);
        return $this->stripOuterQuotes($normalized);
    }

    private function normalizeCurlyQuotes(string $value): string
    {
        $map = [
            '“' => '"',
            '”' => '"',
            '„' => '"',
            '’' => "'",
        ];

        return strtr($value, $map);
    }

    private function stripOuterQuotes(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) < 2) {
            return $value;
        }

        $first = $value[0];
        $last = $value[strlen($value) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return trim(substr($value, 1, -1));
        }

        return $value;
    }

    private function trimProjectAtBoundary(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $offsets = [];

        if (preg_match('/\b\d{1,2}:\d{2}\b/', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $match[0][1];
        }

        if (preg_match('/\b(?:from|to|de|a|ate|até|until)\b/i', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $match[0][1];
        }

        if (preg_match('/DATE_RANGE\s*=/', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $match[0][1];
        }

        if (preg_match('/[;,\.]/', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $match[0][1];
        }

        if (preg_match('/\b(and|e)\b/i', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $tail = substr($raw, $match[0][1]);
            if (preg_match('/\b\d{1,2}:\d{2}\b/', $tail)) {
                $offsets[] = $match[0][1];
            }
        }

        if (preg_match('/\b(task|tarefa|descricao|description|nota|notas|notes|pausa|break|lunch)\b/i', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $match[0][1];
        }

        if (preg_match('/\(/', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $match[0][1];
        }

        if (empty($offsets)) {
            return trim($raw);
        }

        $cut = min($offsets);
        if ($cut <= 0) {
            return trim($raw);
        }

        return trim(substr($raw, 0, $cut));
    }

    /**
     * @return \Illuminate\Support\Collection<int, Project>
     */
    private function findProjectsByNormalizedName(string $name)
    {
        $target = strtolower($this->normalizeAscii($name));
        if ($target === '') {
            return collect();
        }

        return (new Project())
            ->setConnection('tenant')
            ->newQuery()
            ->get(['id', 'name'])
            ->filter(function (Project $project) use ($target) {
                return strtolower($this->normalizeAscii((string) ($project->name ?? ''))) === $target;
            })
            ->values();
    }

    private function projectFromRow(?object $row): ?Project
    {
        if (!$row) {
            return null;
        }

        $project = new Project();
        $project->setConnection('tenant');
        $project->setRawAttributes([
            'id' => $row->id ?? null,
            'name' => $row->name ?? null,
        ], true);
        $project->exists = true;

        return $project;
    }

    private function normalizeAscii(string $value): string
    {
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($ascii !== false) {
                return $ascii;
            }
        }

        return $value;
    }
}
