<?php

declare(strict_types=1);

namespace App\Services\TimesheetAi;

use App\Data\AiTimesheetIntent;
use App\Data\AiTimesheetIntentDateRange;
use App\Models\Project;
use App\Services\TimesheetAIService;
class TimesheetIntentParser
{
    public function __construct(
        private readonly TimesheetAIService $aiService,
    ) {
    }

    /**
     * @return array{ok: bool, intent: AiTimesheetIntent|null, errors: string[], missing_fields: string[]}
     */
    public function parsePrompt(
        string $prompt,
        string $timezone,
        ?string $weekStart,
        ?string $startDate = null,
        ?string $endDate = null
    ): array
    {
        $extracted = $this->extractLabeledFields($prompt);
        $result = $this->aiService->parseTimesheetIntent($prompt, $timezone, $weekStart);
        if (!$result['success']) {
            return [
                'ok' => false,
                'intent' => null,
                'errors' => [$result['error'] ?? 'AI intent parsing is unavailable.'],
                'missing_fields' => [],
            ];
        }

        $payload = $this->decodeJson((string) ($result['response'] ?? ''));
        if (!$payload) {
            return [
                'ok' => false,
                'intent' => null,
                'errors' => ['AI intent response is not valid JSON.'],
                'missing_fields' => [],
            ];
        }

        $intent = AiTimesheetIntent::fromArray($payload);
        $this->mergeExtractedFields($intent, $extracted);
        $this->resolveBuilderProject($intent, $prompt);

        if (!$intent->dateRange && ($startDate || $endDate)) {
            $from = $startDate ?: $endDate;
            $to = $endDate ?: $startDate;
            if ($from && $to) {
                $intent->dateRange = new AiTimesheetIntentDateRange('absolute', $from, $to);
                $intent->missingFields = array_values(array_filter($intent->missingFields, fn (string $field) => $field !== 'date_range'));
            }
        }
        $missing = $this->validateIntent($intent);

        if (!empty($missing)) {
            return [
                'ok' => false,
                'intent' => $intent,
                'errors' => [],
                'missing_fields' => $missing,
            ];
        }

        return [
            'ok' => true,
            'intent' => $intent,
            'errors' => [],
            'missing_fields' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $text): ?array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function validateIntent(AiTimesheetIntent $intent): array
    {
        $missing = array_values(array_unique(array_filter(array_map('strval', $intent->missingFields))));

        if ($intent->intent === '') {
            $missing[] = 'intent';
            return $missing;
        }

        if ($intent->intent !== 'create_timesheets') {
            return ['intent'];
        }

        if (!$intent->dateRange) {
            $missing[] = 'date_range';
        } else {
            $type = strtolower($intent->dateRange->type);
            if ($type === 'absolute') {
                if (!$intent->dateRange->from || !$intent->dateRange->to) {
                    $missing[] = 'date_range';
                }
            } elseif ($type === 'relative') {
                if (!$intent->dateRange->value) {
                    $missing[] = 'date_range';
                } elseif ($intent->dateRange->value === 'last_n_workdays' && (!$intent->dateRange->count || $intent->dateRange->count <= 0)) {
                    $missing[] = 'date_range.count';
                }
            } else {
                $missing[] = 'date_range';
            }
        }

        if (empty($intent->schedule)) {
            $missing[] = 'schedule';
        }

        if (!$intent->project || trim($intent->project) === '') {
            $missing[] = 'project';
        }

        return array_values(array_unique($missing));
    }

    /**
     * @return array{project?: string, task?: string, description?: string, notes?: string}
     */
    private function extractLabeledFields(string $prompt): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $prompt) ?: [];
        $fields = [];

        foreach ($lines as $line) {
            $normalizedLine = $this->normalizeQuotes($line);
            if (!preg_match('/[:=]/', $normalizedLine)) {
                continue;
            }

            $parts = preg_split('/[:=]/', $normalizedLine, 2);
            $label = $this->normalizeLabel($parts[0] ?? '');
            $value = trim((string) ($parts[1] ?? ''));
            $value = $this->stripQuotes($value);

            if ($label === '' || $value === '') {
                continue;
            }

            if (in_array($label, ['project', 'projeto'], true)) {
                $fields['project'] = $value;
                continue;
            }

            if (in_array($label, ['task', 'tarefa'], true)) {
                $fields['task'] = $value;
                continue;
            }

            if (in_array($label, ['descricao', 'description'], true)) {
                $fields['description'] = $value;
                continue;
            }

            if (in_array($label, ['notes', 'note', 'observacoes', 'observacao'], true)) {
                $fields['notes'] = $value;
                continue;
            }
        }

        return $fields;
    }

    /**
     * @param array{project?: string, task?: string, description?: string, notes?: string} $extracted
     */
    private function mergeExtractedFields(AiTimesheetIntent $intent, array $extracted): void
    {
        if (!empty($extracted['project']) && (!$intent->project || trim($intent->project) === '')) {
            $intent->project = $extracted['project'];
            $intent->missingFields = array_values(array_filter($intent->missingFields, fn (string $field) => $field !== 'project'));
        }

        if (!empty($extracted['task']) && (!$intent->task || trim($intent->task) === '')) {
            $intent->task = $extracted['task'];
            $intent->missingFields = array_values(array_filter($intent->missingFields, fn (string $field) => $field !== 'task'));
        }

        if (!empty($extracted['description']) && (!$intent->description || trim($intent->description) === '')) {
            $intent->description = $extracted['description'];
            $intent->missingFields = array_values(array_filter($intent->missingFields, fn (string $field) => $field !== 'description'));
        }

        if (!empty($extracted['notes']) && (!$intent->notes || trim($intent->notes) === '')) {
            $intent->notes = $extracted['notes'];
            $intent->missingFields = array_values(array_filter($intent->missingFields, fn (string $field) => $field !== 'notes'));
        }
    }

    private function resolveBuilderProject(AiTimesheetIntent $intent, string $prompt): void
    {
        if (!$this->looksLikeBuilderPrompt($prompt)) {
            return;
        }

        $builderProject = $this->extractBuilderProjectName($prompt);
        if ($builderProject === null) {
            return;
        }

        $projectMissing = !$intent->project || trim($intent->project) === ''
            || in_array('project', $intent->missingFields, true);
        if (!$projectMissing) {
            return;
        }

        $resolved = Project::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($builderProject)])
            ->orderBy('id')
            ->first();

        if (!$resolved) {
            return;
        }

        $intent->project = $resolved->name;
        $intent->missingFields = array_values(array_filter(
            $intent->missingFields,
            fn (string $field) => $field !== 'project'
        ));
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

    private function extractBuilderProjectName(string $prompt): ?string
    {
        $normalized = $this->normalizeQuotes($prompt);
        if (!preg_match('/^\s*(projeto|project)\s*[:=]\s*["\']?(.+?)["\']?\s*$/im', $normalized, $matches)) {
            return null;
        }

        $project = trim((string) ($matches[2] ?? ''));
        $project = $this->stripQuotes($project);

        return $project !== '' ? $project : null;
    }

    private function normalizeLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if ($ascii === false) {
            $ascii = $lower;
        }

        return trim($ascii);
    }

    private function normalizeQuotes(string $value): string
    {
        $map = [
            '“' => '"',
            '”' => '"',
            '„' => '"',
            '’' => "'",
        ];

        return strtr($value, $map);
    }

    private function stripQuotes(string $value): string
    {
        $value = trim($this->normalizeQuotes($value));
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
}
