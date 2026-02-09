<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAction;
use App\Models\Technician;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\TenantResolver;
use App\Tenancy\TenantContext;
use App\Services\TimesheetAi\TimesheetPlanApplier;
use App\Services\TimesheetAi\TimesheetIntentParser;
use App\Services\TimesheetAi\TimesheetPlanParser;
use App\Services\TimesheetAi\TimesheetPlanValidator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AiTimesheetController extends Controller
{
    public function __construct(
        private readonly TimesheetPlanParser $parser,
        private readonly TimesheetPlanValidator $validator,
        private readonly TimesheetPlanApplier $applier,
        private readonly TimesheetIntentParser $intentParser,
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorize('create', Timesheet::class);

        \Log::info('[AI_TIMESHEET] preview prompt', [
            'prompt' => $request->input('prompt'),
            'timezone' => $request->input('timezone'),
            'tenant' => $request->header('X-Tenant'),
        ]);

        $validator = Validator::make($request->all(), [
            'prompt' => 'required|string|max:2000',
            'technician_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $actor = $request->user();
        $target = $this->resolveTarget($actor, $request->input('technician_id'));

        if (!empty($target['errors'])) {
            return response()->json([
                'message' => $target['errors'][0] ?? 'Invalid technician.',
            ], 422);
        }

        $timezone = $this->resolveTimezone($request);
        $weekStart = app(TenantContext::class)->weekStart ?? null;
        $rawPrompt = (string) $request->input('prompt');
        $prompt = $this->looksLikeBuilderPrompt($rawPrompt)
            ? $rawPrompt
            : $this->normalizePrompt($rawPrompt);

        $intentResult = null;
        $intentException = null;

        try {
            $intentResult = $this->intentParser->parsePrompt(
                $prompt,
                $timezone,
                $weekStart,
                $request->input('start_date'),
                $request->input('end_date')
            );
        } catch (\Throwable $e) {
            $intentException = $e;
        }

        if ($intentException || ($intentResult && !$intentResult['ok'])) {
            \Log::warning('[AI_TIMESHEET] intent parser failed, falling back', [
                'error' => $intentException?->getMessage() ?? null,
                'missing_fields' => $intentResult['missing_fields'] ?? null,
                'prompt' => $prompt,
            ]);
        }

        $payload = [
            'prompt' => $prompt,
            'timezone' => $timezone,
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'week_start' => $weekStart,
            'intent' => $intentResult && $intentResult['ok'] ? $intentResult['intent']?->toArray() : null,
        ];

        $parse = $this->parser->parse($payload, $actor, $target['technician'], $target['user']);
        if (!empty($parse['errors'])) {
            $missingFields = $this->mergeMissingFields(
                $intentResult['missing_fields'] ?? [],
                $parse['errors'] ?? []
            );
            $originalParseErrors = $parse['errors'] ?? [];
            $originalMissingFields = $missingFields;

            if (
                $this->looksLikeBuilderPrompt($rawPrompt)
                && in_array('project', $missingFields, true)
            ) {
                $builderProject = $this->extractBuilderProject($rawPrompt);
                if ($builderProject !== null && $builderProject !== '') {
                    $missingFields = array_values(array_diff($missingFields, ['project']));
                    $filterProjectErrors = static function (array $errors): array {
                        return array_values(array_filter($errors, static function (string $error): bool {
                            return !preg_match('/\bproject\b/i', $error);
                        }));
                    };
                    $parse['errors'] = $filterProjectErrors($parse['errors'] ?? []);
                    $originalParseErrors = $filterProjectErrors($originalParseErrors);
                    $originalMissingFields = $missingFields;
                }
            }

            if (
                $this->looksLikeBuilderPrompt($rawPrompt)
                && in_array('project', $missingFields, true)
            ) {
                $builderProject = $this->extractBuilderProject($rawPrompt);
                if ($builderProject) {
                    \Log::info('[AI_TIMESHEET] retry parse with extracted project', [
                        'tenant' => $request->header('X-Tenant'),
                        'project' => $builderProject,
                    ]);

                    $patchedPrompt = rtrim($rawPrompt) . "\n\nproject \"{$builderProject}\"";
                    $payload['prompt'] = $patchedPrompt;
                    $parse = $this->parser->parse($payload, $actor, $target['technician'], $target['user']);
                }
            }

            if (!empty($parse['errors'])) {
                return response()->json([
                    'message' => $this->buildIntentErrorMessage($originalParseErrors, $originalMissingFields),
                    'missing_fields' => $originalMissingFields,
                ], 422);
            }
        }

        $validation = $this->validator->validate(
            $parse['plan'] ?? [],
            $actor,
            $target['technician'],
            $target['user'],
            false
        );

        if (!$validation['ok']) {
            return response()->json([
                'message' => $validation['errors'][0] ?? 'Plan validation failed.',
            ], 422);
        }

        $warnings = array_values(array_unique(array_merge($parse['warnings'], $validation['warnings'])));

        return response()->json([
            'plan' => $this->toApiPlan($validation['normalized_plan'] ?? []),
            'warnings' => $warnings,
        ]);
    }

    public function commit(Request $request): JsonResponse
    {
        return $this->commitInternal($request, false);
    }

    public function apply(Request $request): JsonResponse
    {
        return $this->commitInternal($request, true);
    }

    private function commitInternal(Request $request, bool $allowLegacy): JsonResponse
    {
        $this->authorize('create', Timesheet::class);

        $actor = $request->user();
        $requestId = (string) ($request->input('request_id') ?? $request->input('client_request_id'));
        $confirmed = $request->boolean('confirmed', $allowLegacy);
        $actionKey = 'timesheets_ai_commit';

        if ($requestId === '') {
            return response()->json([
                'message' => 'request_id is required.',
            ], 422);
        }

        if (!$confirmed) {
            return response()->json([
                'message' => 'confirmed must be true to commit entries.',
            ], 422);
        }

        $planPayload = $request->input('plan');

        if (!$planPayload && !$allowLegacy) {
            return response()->json([
                'message' => 'plan is required.',
            ], 422);
        }

        $existing = AiAction::query()
            ->where('actor_id', $actor->id)
            ->where('client_request_id', $requestId)
            ->where('action', $actionKey)
            ->first();

        if ($existing) {
            $response = $existing->response_json;
            if (is_array($response)) {
                return response()->json($response);
            }

            return response()->json([
                'ok' => false,
                'errors' => ['Request is already being processed.'],
                'warnings' => [],
            ], 409);
        }

        $target = $this->resolveTarget($actor, $request->input('technician_id'));
        if (!empty($target['errors'])) {
            return response()->json([
                'message' => $target['errors'][0] ?? 'Invalid technician.',
            ], 422);
        }

        $normalizedPlan = null;
        $payloadSummary = [
            'request_id' => $requestId,
            'confirmed' => $confirmed,
        ];

        if (is_array($planPayload)) {
            $normalizedPlan = $this->fromApiPlan($planPayload, $request, $target['technician'], $target['user']);
        } elseif ($allowLegacy && $request->filled('prompt')) {
            $timezone = $this->resolveTimezone($request);
            $weekStart = app(TenantContext::class)->weekStart ?? null;
            $prompt = (string) $request->input('prompt');

            $intentResult = $this->intentParser->parsePrompt(
                $prompt,
                $timezone,
                $weekStart,
                $request->input('start_date'),
                $request->input('end_date')
            );
            if (!$intentResult['ok']) {
                return response()->json([
                    'message' => $this->buildIntentErrorMessage($intentResult['errors'], $intentResult['missing_fields']),
                    'missing_fields' => $intentResult['missing_fields'],
                ], 422);
            }

            $payload = [
                'prompt' => $prompt,
                'timezone' => $timezone,
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
                'week_start' => $weekStart,
                'intent' => $intentResult['intent']?->toArray(),
            ];

            $parse = $this->parser->parse($payload, $actor, $target['technician'], $target['user']);
            if (!empty($parse['errors'])) {
                return response()->json([
                    'message' => $parse['errors'][0],
                ], 422);
            }
            $normalizedPlan = $parse['plan'] ?? null;
            $payloadSummary['legacy_prompt'] = true;
        }

        if (!$normalizedPlan) {
            return response()->json([
                'message' => 'plan is required.',
            ], 422);
        }

        $enforceBreaks = (bool) config('timesheets.enforce_breaks', false);
        $validation = $this->validator->validate(
            $normalizedPlan,
            $actor,
            $target['technician'],
            $target['user'],
            $enforceBreaks
        );

        if (!$validation['ok']) {
            return response()->json([
                'message' => $validation['errors'][0] ?? 'Plan validation failed.',
            ], 422);
        }

        try {
            $response = DB::connection('tenant')->transaction(function () use ($requestId, $payloadSummary, $actor, $actionKey, $validation) {
                $action = AiAction::create([
                    'actor_id' => $actor->id,
                    'tenant_id' => TenantResolver::getTenantId(),
                    'client_request_id' => $requestId,
                    'action' => $actionKey,
                    'request_json' => $payloadSummary,
                    'response_json' => null,
                ]);

                $applyResult = $this->applier->apply($validation['normalized_plan'] ?? [], $actor);

                $response = [
                    'created_ids' => $applyResult['created_ids'],
                    'summary' => [
                        'created_count' => $applyResult['created_count'],
                        'totals' => $validation['totals'],
                    ],
                ];

                $action->update([
                    'response_json' => [
                        ...$response,
                        'payload_summary' => [
                            'created_count' => $applyResult['created_count'],
                            'created_ids' => $applyResult['created_ids'],
                        ],
                    ],
                ]);

                return $response;
            });
        } catch (QueryException $e) {
            $existing = AiAction::query()
                ->where('actor_id', $actor->id)
                ->where('client_request_id', $requestId)
                ->where('action', $actionKey)
                ->first();

            if ($existing && is_array($existing->response_json)) {
                return response()->json($existing->response_json);
            }

            return response()->json([
                'message' => 'Request is already being processed.',
            ], 409);
        }

        return response()->json($response);
    }

    /**
     * @param string[] $errors
     * @param string[] $missingFields
     */
    private function buildIntentErrorMessage(array $errors, array $missingFields): string
    {
        if (!empty($missingFields)) {
            return 'Missing required fields: ' . implode(', ', $missingFields) . '.';
        }

        return $errors[0] ?? 'Unable to parse timesheet intent.';
    }

    /**
     * @param string[] $intentMissing
     * @param string[] $parseErrors
     * @return string[]
     */
    private function mergeMissingFields(array $intentMissing, array $parseErrors): array
    {
        $missing = $intentMissing;

        foreach ($parseErrors as $error) {
            $lower = strtolower((string) $error);
            if (str_contains($lower, 'date range') && !in_array('date_range', $missing, true)) {
                $missing[] = 'date_range';
            }
            if (str_contains($lower, 'project') && !in_array('project', $missing, true)) {
                $missing[] = 'project';
            }
            if (str_contains($lower, 'task') && !in_array('task', $missing, true)) {
                $missing[] = 'task';
            }
            if (str_contains($lower, 'time interval') || str_contains($lower, 'schedule')) {
                if (!in_array('schedule', $missing, true)) {
                    $missing[] = 'schedule';
                }
            }
        }

        return array_values(array_unique($missing));
    }

    private function normalizePrompt(string $prompt): string
    {
        $map = [
            '“' => '"',
            '”' => '"',
            '„' => '"',
            '’' => "'",
        ];

        $normalized = strtr($prompt, $map);

        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if ($ascii !== false) {
                $normalized = $ascii;
            }
        }

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    private function looksLikeBuilderPrompt(string $prompt): bool
    {
        if (preg_match('/\bDATE_RANGE\s*=\s*\d{4}-\d{2}-\d{2}\s*\.\.\s*\d{4}-\d{2}-\d{2}\b/i', $prompt)) {
            return true;
        }

        if (preg_match('/^(\s*)(projeto|project|tarefa|task|descri[cç]ao|description|notes?|notas|observa(?:coes|ções)|bloco|block)\s*[:=]/im', $prompt)) {
            return true;
        }

        return false;
    }

    private function extractBuilderProject(string $prompt): ?string
    {
        if (!preg_match('/^\s*(projeto|project)\s*[:=]\s*["“]?(.+?)["”]?\s*$/im', $prompt, $matches)) {
            return null;
        }

        $project = trim((string) ($matches[2] ?? ''));
        $project = trim($project, " \t\n\r\0\x0B\"'");

        return $project !== '' ? $project : null;
    }

    /**
     * @return array{technician: Technician, user: User, errors: string[]}|array{errors: string[]}
     */
    private function resolveTarget(User $actor, ?int $technicianId): array
    {
        $errors = [];

        if ($technicianId) {
            if (!$actor->hasRole('Owner') && !$actor->hasRole('Admin')) {
                return ['errors' => ['Only Owner or Admin can create timesheets for another technician.']];
            }

            $technician = Technician::find($technicianId);
            if (!$technician) {
                return ['errors' => ['Technician not found.']];
            }
        } else {
            $technician = $actor->technician
                ?? Technician::where('user_id', $actor->id)->first()
                ?? Technician::where('email', $actor->email)->first();

            if (!$technician) {
                return ['errors' => ['Technician profile not found.']];
            }
        }

        $targetUser = $technician->user;
        if (!$targetUser) {
            $errors[] = 'Technician does not have a linked user.';
        }

        return [
            'technician' => $technician,
            'user' => $targetUser,
            'errors' => $errors,
        ];
    }

    private function resolveTimezone(Request $request): string
    {
        $tenant = TenantResolver::resolve();
        if (!$tenant) {
            return (string) config('app.timezone', 'UTC');
        }

        return TenantContext::fromTenant($tenant)->timezone;
    }

    /**
     * @param array<string, mixed> $normalizedPlan
     * @return array<string, mixed>
     */
    private function toApiPlan(array $normalizedPlan): array
    {
        $days = [];
        $start = null;
        $end = null;

        foreach ($normalizedPlan['days'] ?? [] as $day) {
            $date = (string) ($day['date'] ?? '');
            if ($date === '') {
                continue;
            }

            $start = $start ? min($start, $date) : $date;
            $end = $end ? max($end, $date) : $date;

            $workBlocks = [];
            foreach ($day['entries'] ?? [] as $entry) {
                $workBlocks[] = [
                    'start_time' => $entry['start_time'] ?? null,
                    'end_time' => $entry['end_time'] ?? null,
                    'project' => [
                        'id' => $entry['project_id'] ?? null,
                        'name' => $entry['project_name'] ?? null,
                    ],
                    'task' => [
                        'id' => $entry['task_id'] ?? null,
                        'name' => $entry['task_name'] ?? null,
                    ],
                    'location' => [
                        'id' => $entry['location_id'] ?? null,
                        'name' => $entry['location_name'] ?? null,
                    ],
                    'notes' => $entry['notes'] ?? null,
                ];
            }

            $breaks = [];
            foreach ($day['breaks'] ?? [] as $break) {
                $breaks[] = [
                    'start_time' => $break['start_time'] ?? null,
                    'end_time' => $break['end_time'] ?? null,
                ];
            }

            $days[] = [
                'date' => $date,
                'work_blocks' => $workBlocks,
                'breaks' => $breaks,
            ];
        }

        return [
            'range' => [
                'start_date' => $start,
                'end_date' => $end,
            ],
            'timezone' => $normalizedPlan['timezone'] ?? null,
            'days' => $days,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function fromApiPlan(array $plan, Request $request, Technician $technician, User $targetUser): array
    {
        $days = [];
        foreach ($plan['days'] ?? [] as $day) {
            $date = (string) ($day['date'] ?? '');
            if ($date === '') {
                continue;
            }

            $entries = [];
            foreach ($day['work_blocks'] ?? [] as $block) {
                $projectId = (int) data_get($block, 'project.id', 0);
                $taskId = (int) data_get($block, 'task.id', 0);
                $locationId = (int) data_get($block, 'location.id', 0);

                $entries[] = [
                    'project_id' => $projectId,
                    'project_name' => data_get($block, 'project.name'),
                    'task_id' => $taskId,
                    'task_name' => data_get($block, 'task.name'),
                    'location_id' => $locationId,
                    'location_name' => data_get($block, 'location.name'),
                    'start_time' => data_get($block, 'start_time'),
                    'end_time' => data_get($block, 'end_time'),
                    'notes' => data_get($block, 'notes'),
                ];
            }

            $breaks = [];
            foreach ($day['breaks'] ?? [] as $break) {
                $breaks[] = [
                    'start_time' => data_get($break, 'start_time'),
                    'end_time' => data_get($break, 'end_time'),
                ];
            }

            $days[] = [
                'date' => $date,
                'entries' => $entries,
                'breaks' => $breaks,
            ];
        }

        return [
            'prompt' => $request->input('prompt'),
            'timezone' => data_get($plan, 'timezone'),
            'target_user_id' => $targetUser->id,
            'technician_id' => $technician->id,
            'days' => $days,
        ];
    }
}
