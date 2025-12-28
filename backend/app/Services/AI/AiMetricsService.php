<?php

declare(strict_types=1);

namespace App\Services\AI;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiMetricsService
{
    public const DEFAULT_WINDOW_DAYS = 30;
    private const MIN_WINDOW_DAYS = 1;
    private const MAX_WINDOW_DAYS = 180;

    private string $table = 'ai_suggestion_feedback';

    /**
     * Generate aggregate metrics for AI suggestion feedback.
     */
    public function getMetrics(?int $windowDays = null): array
    {
        $days = $this->normalizeWindow($windowDays);

        if (!Schema::hasTable($this->table)) {
            return $this->formatResponse($days, 0, []);
        }

        $windowStart = CarbonImmutable::now()->subDays($days)->startOfDay();

        $statusCounts = DB::table($this->table)
            ->select('status', DB::raw('COUNT(*) as aggregate_count'))
            ->where('created_at', '>=', $windowStart)
            ->groupBy('status')
            ->pluck('aggregate_count', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $tenantsWithAi = DB::table($this->table)
            ->where('created_at', '>=', $windowStart)
            ->distinct()
            ->count('tenant_id');

        return $this->formatResponse($days, $tenantsWithAi, $statusCounts);
    }

    private function normalizeWindow(?int $windowDays): int
    {
        $days = $windowDays ?? self::DEFAULT_WINDOW_DAYS;

        if ($days < self::MIN_WINDOW_DAYS) {
            return self::MIN_WINDOW_DAYS;
        }

        if ($days > self::MAX_WINDOW_DAYS) {
            return self::MAX_WINDOW_DAYS;
        }

        return $days;
    }

    private function formatResponse(int $days, int $tenantsWithAi, array $statusCounts): array
    {
        $total = array_sum($statusCounts);

        $accepted = $statusCounts['accepted'] ?? 0;
        $rejected = $statusCounts['rejected'] ?? 0;
        $ignored = $statusCounts['ignored'] ?? 0;

        return [
            'window_days' => $days,
            'tenants_with_ai' => $tenantsWithAi,
            'suggestions_shown' => $total,
            'accepted_rate' => $this->ratio($accepted, $total),
            'rejected_rate' => $this->ratio($rejected, $total),
            'ignored_rate' => $this->ratio($ignored, $total),
        ];
    }

    private function ratio(int $partial, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round($partial / $total, 2);
    }
}
