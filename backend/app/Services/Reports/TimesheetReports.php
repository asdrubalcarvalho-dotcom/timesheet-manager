<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Timesheet;
use App\Models\User;
use App\Services\Reports\Exports\CsvExporter;
use App\Services\Reports\Exports\SimpleXlsxExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TimesheetReports
{
    /**
     * Hardcoded templates only. No dynamic SQL.
     */
    public const TEMPLATES = [
        'timesheets_summary' => [
            'required_permission' => 'view-timesheets',
            'allowed_group_by' => ['user', 'project', 'date'],
            'allowed_filters' => ['from', 'to', 'status'],
        ],
        'timesheets_approval_status' => [
            'required_permission' => 'view-timesheets',
            'allowed_group_by' => ['user', 'project'],
            'allowed_filters' => ['status'],
        ],
        'timesheets_calendar' => [
            'required_permission' => 'view-timesheets',
            'allowed_group_by' => ['date'],
            'allowed_filters' => ['from', 'to'],
        ],
    ];

    private const EXPORT_TTL_MINUTES = 10;

    public function __construct(
        private readonly CsvExporter $csv,
        private readonly SimpleXlsxExporter $xlsx,
    ) {
    }

    public static function template(string $name): array
    {
        return self::TEMPLATES[$name] ?? [];
    }

    public static function isValidTemplate(string $name): bool
    {
        return array_key_exists($name, self::TEMPLATES);
    }

    /**
     * Execute a report and return aggregated rows only.
     *
     * @return array{report:string,group_by:string,filters:array,data:array}
     */
    public function run(User $user, string $report, array $filters, string $groupBy): array
    {
        $this->assertTemplateAndInputs($report, $filters, $groupBy);

        $query = $this->scopedTimesheetsQuery($user);
        $this->applyFilters($query, $report, $filters);

        $rows = match ($report) {
            'timesheets_summary' => $this->runTimesheetsSummary($query, $groupBy),
            'timesheets_approval_status' => $this->runTimesheetsApprovalStatus($query, $groupBy),
            'timesheets_calendar' => $this->runTimesheetsCalendar($query),
            default => throw ValidationException::withMessages(['report' => 'Invalid report template.']),
        };

        return [
            'report' => $report,
            'group_by' => $groupBy,
            'filters' => $filters,
            'data' => $rows,
        ];
    }

    /**
     * Build and persist an export file; return a signed download URL.
     *
     * @return array{download_url:string,expires_at:string,report:string,format:string}
     */
    public function export(User $user, string $report, array $filters, string $groupBy, string $format): array
    {
        $payload = $this->run($user, $report, $filters, $groupBy);
        $rows = $payload['data'];

        $exportId = (string) Str::uuid();
        $tenantId = tenant('id') ?? 'unknown';

        $relativePath = match ($format) {
            'csv' => $this->csv->store($rows, $exportId),
            'xlsx' => $this->xlsx->store($rows, $exportId),
            default => throw ValidationException::withMessages(['format' => 'Invalid export format.']),
        };

        $cacheKey = $this->downloadCacheKey($tenantId, $exportId);
        Cache::put($cacheKey, [
            'path' => $relativePath,
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
        ], now()->addMinutes(self::EXPORT_TTL_MINUTES));

        $expiresAt = now()->addMinutes(self::EXPORT_TTL_MINUTES);
        $downloadUrl = URL::temporarySignedRoute(
            'reports.download',
            $expiresAt,
            ['id' => $exportId]
        );

        return [
            'report' => $report,
            'format' => $format,
            'download_url' => $downloadUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function resolveDownload(string $exportId): ?array
    {
        $tenantId = tenant('id') ?? 'unknown';
        $cacheKey = $this->downloadCacheKey($tenantId, $exportId);
        $data = Cache::get($cacheKey);

        return is_array($data) ? $data : null;
    }

    public function forgetDownload(string $exportId): void
    {
        $tenantId = tenant('id') ?? 'unknown';
        Cache::forget($this->downloadCacheKey($tenantId, $exportId));
    }

    private function downloadCacheKey(string $tenantId, string $exportId): string
    {
        return "reports:download:{$tenantId}:{$exportId}";
    }

    private function assertTemplateAndInputs(string $report, array $filters, string $groupBy): void
    {
        if (!self::isValidTemplate($report)) {
            throw ValidationException::withMessages(['report' => 'Invalid report name.']);
        }

        $template = self::TEMPLATES[$report];

        $unknownFilters = array_diff(array_keys($filters), $template['allowed_filters']);
        if (!empty($unknownFilters)) {
            throw ValidationException::withMessages([
                'filters' => 'Unknown filters: ' . implode(', ', array_values($unknownFilters)),
            ]);
        }

        if (!in_array($groupBy, $template['allowed_group_by'], true)) {
            throw ValidationException::withMessages(['group_by' => 'Invalid group_by for this report.']);
        }

        // Status constraints (template-specific)
        if (array_key_exists('status', $filters)) {
            $this->normalizeAndValidateStatus($report, (string) $filters['status']);
        }
    }

    private function normalizeAndValidateStatus(string $report, string $status): void
    {
        $allowed = match ($report) {
            'timesheets_approval_status' => ['pending', 'approved', 'rejected'],
            default => ['draft', 'submitted', 'approved', 'rejected', 'closed'],
        };

        if (!in_array($status, $allowed, true)) {
            throw ValidationException::withMessages(['filters.status' => 'Invalid status filter.']);
        }
    }

    /**
     * Scoped base query that is >= TimesheetPolicy::view restrictions.
     *
     * IMPORTANT: This intentionally does NOT change existing Timesheet CRUD.
     */
    private function scopedTimesheetsQuery(User $user): Builder
    {
        // Enforce view-timesheets via policy baseline (controller/middleware also enforce).
        if (!$user->hasPermissionTo('view-timesheets')) {
            // Return an always-empty query (no leakage).
            return Timesheet::query()->whereRaw('1 = 0');
        }

        if ($user->hasRole('Admin')) {
            return Timesheet::query();
        }

        // Policy requires project membership AND either:
        // - own timesheet (technician.email == user.email)
        // - or project manager, but only for member-owned timesheets (not other managers)
        return Timesheet::query()
            ->join('technicians', 'technicians.id', '=', 'timesheets.technician_id')
            ->join('project_members as pm_me', function ($join) use ($user) {
                $join->on('pm_me.project_id', '=', 'timesheets.project_id')
                    ->where('pm_me.user_id', '=', $user->id);
            })
            ->leftJoin('project_members as pm_me_manager', function ($join) use ($user) {
                $join->on('pm_me_manager.project_id', '=', 'timesheets.project_id')
                    ->where('pm_me_manager.user_id', '=', $user->id)
                    ->where('pm_me_manager.project_role', '=', 'manager');
            })
            ->leftJoin('project_members as pm_owner', function ($join) {
                $join->on('pm_owner.project_id', '=', 'timesheets.project_id')
                    ->on('pm_owner.user_id', '=', 'technicians.user_id');
            })
            ->where(function ($where) use ($user) {
                $where->where('technicians.email', '=', $user->email)
                    ->orWhere(function ($managerClause) {
                        $managerClause
                            ->whereNotNull('pm_me_manager.user_id')
                            ->where(function ($ownerRoleClause) {
                                $ownerRoleClause
                                    ->whereNull('technicians.user_id')
                                    ->orWhere('pm_owner.project_role', '=', 'member');
                            });
                    });
            });
    }

    private function applyFilters(Builder $query, string $report, array $filters): void
    {
        if (isset($filters['from'])) {
            $query->where('timesheets.date', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('timesheets.date', '<=', $filters['to']);
        }

        if (isset($filters['status'])) {
            $status = (string) $filters['status'];

            if ($report === 'timesheets_approval_status') {
                $status = match ($status) {
                    'pending' => 'submitted',
                    'approved' => 'approved',
                    'rejected' => 'rejected',
                    default => $status,
                };
            }

            $query->where('timesheets.status', '=', $status);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function runTimesheetsSummary(Builder $query, string $groupBy): array
    {
        return $this->aggregate($query, $groupBy);
    }

    /** @return array<int,array<string,mixed>> */
    private function runTimesheetsApprovalStatus(Builder $query, string $groupBy): array
    {
        return $this->aggregate($query, $groupBy);
    }

    /** @return array<int,array<string,mixed>> */
    private function runTimesheetsCalendar(Builder $query): array
    {
        return $this->aggregate($query, 'date');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function aggregate(Builder $query, string $groupBy): array
    {
        // Ensure correct base table aliasing whether joins exist or not.
        $base = $query;

        return match ($groupBy) {
            'date' => $base
                ->selectRaw('timesheets.date as date')
                ->selectRaw('SUM(timesheets.hours_worked) as total_hours')
                ->groupBy('timesheets.date')
                ->orderBy('timesheets.date')
                ->get()
                ->map(fn ($row) => [
                    'date' => (string) $row->date,
                    'total_hours' => (float) $row->total_hours,
                ])
                ->all(),

            'project' => $base
                ->join('projects', 'projects.id', '=', 'timesheets.project_id')
                ->selectRaw('timesheets.project_id as project_id')
                ->selectRaw('projects.name as project_name')
                ->selectRaw('SUM(timesheets.hours_worked) as total_hours')
                ->groupBy('timesheets.project_id', 'projects.name')
                ->orderBy('projects.name')
                ->get()
                ->map(fn ($row) => [
                    'project' => [
                        'id' => (int) $row->project_id,
                        'name' => (string) $row->project_name,
                    ],
                    'total_hours' => (float) $row->total_hours,
                ])
                ->all(),

            'user' => $base
                ->join('technicians as t2', 't2.id', '=', 'timesheets.technician_id')
                ->selectRaw('timesheets.technician_id as technician_id')
                ->selectRaw('t2.name as technician_name')
                ->selectRaw('t2.email as technician_email')
                ->selectRaw('SUM(timesheets.hours_worked) as total_hours')
                ->groupBy('timesheets.technician_id', 't2.name', 't2.email')
                ->orderBy('t2.name')
                ->get()
                ->map(fn ($row) => [
                    'user' => [
                        'technician_id' => (int) $row->technician_id,
                        'name' => (string) ($row->technician_name ?? ''),
                        'email' => (string) ($row->technician_email ?? ''),
                    ],
                    'total_hours' => (float) $row->total_hours,
                ])
                ->all(),

            default => throw ValidationException::withMessages(['group_by' => 'Invalid group_by.']),
        };
    }
}
