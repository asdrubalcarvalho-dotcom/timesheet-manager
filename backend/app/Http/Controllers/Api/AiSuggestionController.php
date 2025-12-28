<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Task;
use App\Models\Timesheet;
use App\Services\TenantFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AiSuggestionController extends Controller
{
    private const STATUS_WHITELIST = ['submitted', 'approved', 'closed'];
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 15;
    private const TASK_HISTORY_WEIGHT = 0.7;
    private const PROJECT_HISTORY_WEIGHT = 0.2;
    private const ASSIGNMENT_WEIGHT = 0.1;

    public function suggestTaskLocations(Request $request): JsonResponse
    {
        $tenant = tenancy()->tenant;

        if (!$tenant || !TenantFeatures::active($tenant, TenantFeatures::AI)) {
            return response()->json([
                'success' => false,
                'code' => 'AI_DISABLED',
                'message' => 'AI suggestions are disabled for this tenant.',
            ], 403);
        }

        $validated = $request->validate([
            'task_id' => 'required|integer|exists:tasks,id',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        /** @var Task $task */
        $task = Task::query()
            ->with(['project:id,name', 'locations:id,name,country,city,address,postal_code,timezone,meta,is_active'])
            ->findOrFail($validated['task_id']);

        $scores = [];
        $meta = [
            'task_history_records' => 0,
            'project_history_records' => 0,
            'assignment_records' => $task->locations->count(),
        ];

        $taskUsage = $this->queryTaskLocationUsage($task->id);
        $meta['task_history_records'] = $this->appendUsageScores(
            $scores,
            $taskUsage,
            self::TASK_HISTORY_WEIGHT,
            'task_history'
        );

        if ($task->project_id) {
            $projectUsage = $this->queryProjectLocationUsage($task->project_id, $task->id);
            $meta['project_history_records'] = $this->appendUsageScores(
                $scores,
                $projectUsage,
                self::PROJECT_HISTORY_WEIGHT,
                'project_history'
            );
        }

        $this->appendAssignmentScores($scores, $task->locations);

        $suggestions = $this->formatSuggestions($scores, $limit);

        return response()->json([
            'success' => true,
            'weights' => [
                'same_project' => self::TASK_HISTORY_WEIGHT,
                'cross_project' => self::PROJECT_HISTORY_WEIGHT,
                'assignment_fallback' => self::ASSIGNMENT_WEIGHT,
            ],
            'data' => [
                'task_id' => $task->id,
                'project_id' => $task->project_id,
                'project_name' => $task->project->name ?? null,
                'suggestions' => $suggestions,
                'meta' => array_merge($meta, [
                    'limit' => $limit,
                    'sources_used' => $this->collectSources($scores),
                    'generated_at' => now()->toIso8601String(),
                ]),
            ],
        ]);
    }

    private function queryTaskLocationUsage(int $taskId): Collection
    {
        return Timesheet::query()
            ->select('location_id', DB::raw('COUNT(*) as usage_count'))
            ->where('task_id', $taskId)
            ->whereIn('status', self::STATUS_WHITELIST)
            ->whereNotNull('location_id')
            ->groupBy('location_id')
            ->orderByDesc('usage_count')
            ->get();
    }

    private function queryProjectLocationUsage(int $projectId, int $taskId): Collection
    {
        return Timesheet::query()
            ->select('location_id', DB::raw('COUNT(*) as usage_count'))
            ->where('project_id', $projectId)
            ->where(function ($query) use ($taskId) {
                $query->whereNull('task_id')
                      ->orWhere('task_id', '!=', $taskId);
            })
            ->whereIn('status', self::STATUS_WHITELIST)
            ->whereNotNull('location_id')
            ->groupBy('location_id')
            ->orderByDesc('usage_count')
            ->get();
    }

    private function appendUsageScores(array &$scores, Collection $usageStats, float $weight, string $source): int
    {
        $total = (int) $usageStats->sum('usage_count');

        if ($total === 0) {
            return 0;
        }

        foreach ($usageStats as $stat) {
            $locationId = (int) $stat->location_id;
            $usageCount = (int) $stat->usage_count;
            $confidence = ($usageCount / $total) * $weight;

            $this->addScore($scores, $locationId, $confidence, $source, $usageCount);
        }

        return $total;
    }

    private function appendAssignmentScores(array &$scores, Collection $locations): void
    {
        if ($locations->isEmpty()) {
            return;
        }

        $perAssignment = self::ASSIGNMENT_WEIGHT / $locations->count();

        foreach ($locations as $location) {
            $this->addScore($scores, (int) $location->id, $perAssignment, 'task_assignment', null);
        }
    }

    private function addScore(array &$scores, int $locationId, float $confidence, string $source, ?int $usageCount): void
    {
        if (!isset($scores[$locationId])) {
            $scores[$locationId] = [
                'location_id' => $locationId,
                'confidence' => 0.0,
                'sources' => [],
                'usage_breakdown' => [],
            ];
        }

        $scores[$locationId]['confidence'] += $confidence;
        $scores[$locationId]['sources'][] = $source;

        if ($usageCount !== null) {
            $scores[$locationId]['usage_breakdown'][$source] = $usageCount;
        }
    }

    private function formatSuggestions(array $scores, int $limit): array
    {
        if (empty($scores)) {
            return [];
        }

        $locationIds = array_keys($scores);

        $locations = Location::query()
            ->whereIn('id', $locationIds)
            ->get()
            ->keyBy('id');

        return collect($scores)
            ->map(function (array $entry) use ($locations) {
                $location = $locations->get($entry['location_id']);

                if (!$location) {
                    return null;
                }

                $confidence = min(1, round($entry['confidence'], 4));

                return [
                    'location_id' => $location->id,
                    'name' => $location->name,
                    'confidence' => $confidence,
                    'sources' => array_values(array_unique($entry['sources'])),
                    'usage_breakdown' => $entry['usage_breakdown'],
                    'location' => [
                        'city' => $location->city,
                        'country' => $location->country,
                        'timezone' => $location->timezone,
                        'full_address' => $location->full_address,
                        'meta' => $location->meta,
                        'is_active' => $location->is_active,
                    ],
                ];
            })
            ->filter()
            ->sortByDesc('confidence')
            ->take($limit)
            ->values()
            ->all();
    }

    private function collectSources(array $scores): array
    {
        return collect($scores)
            ->flatMap(fn (array $entry) => $entry['sources'])
            ->unique()
            ->values()
            ->all();
    }
}
