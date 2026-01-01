<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Timesheet;
use App\Models\User;
use App\Services\Reports\Exports\CsvExporter;
use App\Services\Reports\Exports\SimpleXlsxExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        // Step 1: Timesheets by User / Period
        'timesheets_by_user_period' => [
            'required_permission' => 'view-timesheets',
            'allowed_group_by' => ['user'],
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
            'timesheets_by_user_period' => $this->runUserReport(
                (string) ($filters['from'] ?? ''),
                (string) ($filters['to'] ?? ''),
                $filters,
                $user,
            ),
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
     * Export timesheets as a streamed file.
     *
     * Required by /api/reports/timesheets/export.
     */
    public function export(array $filters, string $format, User $user): StreamedResponse
    {
        $rows = $this->exportRows($filters, $user);

        $timestamp = now()->format('Ymd_His');
        $exportId = substr((string) Str::uuid(), 0, 8);

        return match ($format) {
            'csv' => $this->csv->stream($rows, "timesheets_{$timestamp}_{$exportId}.csv"),
            'xlsx' => $this->xlsx->stream($rows, "timesheets_{$timestamp}_{$exportId}.xlsx"),
            default => throw ValidationException::withMessages(['format' => 'Invalid export format.']),
        };
    }

    /**
     * Timesheets Summary (pivot) grouped by period + dimensions.
     *
     * @param array{from:string,to:string,group_by:array<int,string>,period:string} $filters
     * @return Collection<int,array<string,mixed>>
     */
    public function summary(array $filters, User $actor): Collection
    {
        if (!$actor->hasPermissionTo('view-timesheets')) {
            return collect();
        }

        $isElevated = $actor->hasRole('Admin') || $actor->hasRole('Manager');

        $periodExpr = match ($filters['period']) {
            'day' => "DATE_FORMAT(timesheets.date, '%Y-%m-%d')",
            // ISO week-year + ISO week number
            'week' => "DATE_FORMAT(timesheets.date, '%x-W%v')",
            'month' => "DATE_FORMAT(timesheets.date, '%Y-%m')",
            default => throw ValidationException::withMessages(['period' => 'Invalid period.']),
        };

        $groupBy = array_values(array_unique(array_map('strval', $filters['group_by'])));
        $groupBy = array_values(array_intersect($groupBy, ['user', 'project']));

        if (count($groupBy) === 0) {
            throw ValidationException::withMessages(['group_by' => 'group_by must include at least one of: user, project']);
        }

        $query = Timesheet::query()
            ->join('technicians as tech', 'tech.id', '=', 'timesheets.technician_id')
            ->leftJoin('users as u', 'u.id', '=', 'tech.user_id')
            ->join('projects as p', 'p.id', '=', 'timesheets.project_id')
            ->where('timesheets.date', '>=', (string) $filters['from'])
            ->where('timesheets.date', '<=', (string) $filters['to']);

        if (!$isElevated) {
            $query->where(function ($where) use ($actor) {
                $where->where('tech.user_id', '=', $actor->id)
                    ->orWhere('tech.email', '=', $actor->email);
            });
        }

        $query->selectRaw("{$periodExpr} as period");
        $groupColumns = ['period'];

        if (in_array('user', $groupBy, true)) {
            $query->addSelect([
                'tech.user_id as user_id',
            ]);
            $query->selectRaw('COALESCE(u.name, tech.name) as user_name');
            $groupColumns[] = 'tech.user_id';
            $groupColumns[] = DB::raw('COALESCE(u.name, tech.name)');
        }

        if (in_array('project', $groupBy, true)) {
            $query->addSelect([
                'timesheets.project_id as project_id',
                'p.name as project_name',
            ]);
            $groupColumns[] = 'timesheets.project_id';
            $groupColumns[] = 'p.name';
        }

        $query
            ->selectRaw('SUM(timesheets.hours_worked * 60) as total_minutes')
            ->selectRaw("SUM(CASE WHEN timesheets.status = 'approved' THEN timesheets.hours_worked * 60 ELSE 0 END) as approved_minutes")
            ->selectRaw("SUM(CASE WHEN timesheets.status = 'submitted' THEN timesheets.hours_worked * 60 ELSE 0 END) as pending_minutes")
            ->selectRaw("SUM(CASE WHEN timesheets.status = 'rejected' THEN timesheets.hours_worked * 60 ELSE 0 END) as rejected_minutes")
            ->selectRaw('COUNT(*) as total_entries')
            ->groupBy($groupColumns)
            ->orderBy('period');

        if (in_array('user', $groupBy, true)) {
            $query->orderBy(DB::raw('COALESCE(u.name, tech.name)'));
        }

        if (in_array('project', $groupBy, true)) {
            $query->orderBy('p.name');
        }

        return $query
            ->get()
            ->map(function ($row) use ($groupBy) {
                $out = [
                    'period' => (string) $row->period,
                    'total_minutes' => (int) round((float) $row->total_minutes),
                    'approved_minutes' => (int) round((float) $row->approved_minutes),
                    'pending_minutes' => (int) round((float) $row->pending_minutes),
                    'rejected_minutes' => (int) round((float) $row->rejected_minutes),
                    // No explicit closed/draft breakdown requested
                    'total_entries' => (int) $row->total_entries,
                ];

                if (in_array('user', $groupBy, true)) {
                    $out['user_id'] = $row->user_id !== null ? (int) $row->user_id : null;
                    $out['user_name'] = (string) ($row->user_name ?? '');
                }

                if (in_array('project', $groupBy, true)) {
                    $out['project_id'] = (int) $row->project_id;
                    $out['project_name'] = (string) ($row->project_name ?? '');
                }

                return $out;
            });
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    private function exportRows(array $filters, User $actor): array
    {
        if (!$actor->hasPermissionTo('view-timesheets')) {
            return [];
        }

        $isElevated = $actor->hasRole('Admin') || $actor->hasRole('Manager');

        $query = Timesheet::query()
            ->join('technicians as tech', 'tech.id', '=', 'timesheets.technician_id')
            ->join('projects as p', 'p.id', '=', 'timesheets.project_id');

        if (!$isElevated) {
            $query->where(function ($where) use ($actor) {
                $where->where('tech.user_id', '=', $actor->id)
                    ->orWhere('tech.email', '=', $actor->email);
            });
        }

        if (isset($filters['from']) && $filters['from'] !== null && $filters['from'] !== '') {
            $query->where('timesheets.date', '>=', (string) $filters['from']);
        }

        if (isset($filters['to']) && $filters['to'] !== null && $filters['to'] !== '') {
            $query->where('timesheets.date', '<=', (string) $filters['to']);
        }

        if (isset($filters['project_id']) && $filters['project_id'] !== null && $filters['project_id'] !== '') {
            $query->where('timesheets.project_id', '=', (int) $filters['project_id']);
        }

        if ($isElevated && isset($filters['user_id']) && $filters['user_id'] !== null && $filters['user_id'] !== '') {
            $query->where('tech.user_id', '=', (int) $filters['user_id']);
        }

        $data = $query
            ->select([
                'timesheets.id as timesheet_id',
                'timesheets.date as date',
                'timesheets.hours_worked as hours_worked',
                'timesheets.status as status',
                'timesheets.description as description',
                'timesheets.project_id as project_id',
                'p.name as project_name',
                'timesheets.technician_id as technician_id',
                'tech.user_id as user_id',
                'tech.name as technician_name',
                'tech.email as technician_email',
            ])
            ->orderBy('timesheets.date')
            ->orderBy('p.name')
            ->orderBy('tech.name')
            ->get();

        return $data
            ->map(fn ($row) => [
                'timesheet_id' => (int) $row->timesheet_id,
                'date' => (string) $row->date,
                'hours_worked' => (float) $row->hours_worked,
                'status' => (string) $row->status,
                'description' => (string) ($row->description ?? ''),
                'project_id' => (int) $row->project_id,
                'project_name' => (string) ($row->project_name ?? ''),
                'technician_id' => (int) $row->technician_id,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'technician_name' => (string) ($row->technician_name ?? ''),
                'technician_email' => (string) ($row->technician_email ?? ''),
            ])
            ->all();
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

        if ($report === 'timesheets_by_user_period') {
            $from = (string) ($filters['from'] ?? '');
            $to = (string) ($filters['to'] ?? '');

            if ($from === '' || $to === '') {
                throw ValidationException::withMessages([
                    'filters.from' => 'from is required for this report.',
                    'filters.to' => 'to is required for this report.',
                ]);
            }

            // Basic ordering check; detailed format validation happens in FormRequest.
            if ($from > $to) {
                throw ValidationException::withMessages(['filters' => 'Invalid period: from must be <= to.']);
            }
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

    /**
     * Step 1: Timesheets by User / Period.
     *
     * @return array<int,array<string,mixed>>
     */
    public function runUserReport(string $from, string $to, array $filters, User $actor): array
    {
        $query = $this->scopedTimesheetsQuery($actor);
        $this->applyFilters($query, 'timesheets_summary', array_merge($filters, ['from' => $from, 'to' => $to]));

        $rows = $query
            ->join('technicians as tech', 'tech.id', '=', 'timesheets.technician_id')
            ->join('projects as p', 'p.id', '=', 'timesheets.project_id')
            ->selectRaw('timesheets.technician_id as technician_id')
            ->selectRaw('tech.user_id as user_id')
            ->selectRaw('tech.name as technician_name')
            ->selectRaw('tech.email as technician_email')
            ->selectRaw('timesheets.project_id as project_id')
            ->selectRaw('p.name as project_name')
            ->selectRaw('SUM(timesheets.hours_worked) as total_hours')
            ->groupBy('timesheets.technician_id', 'tech.user_id', 'tech.name', 'tech.email', 'timesheets.project_id', 'p.name')
            ->orderBy('tech.name')
            ->orderBy('p.name')
            ->get();

        $byTechnician = [];

        foreach ($rows as $row) {
            $technicianId = (int) $row->technician_id;
            $projectHours = (float) $row->total_hours;

            if (!array_key_exists($technicianId, $byTechnician)) {
                $byTechnician[$technicianId] = [
                    'user' => [
                        'technician_id' => $technicianId,
                        'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                        'name' => (string) ($row->technician_name ?? ''),
                        'email' => (string) ($row->technician_email ?? ''),
                    ],
                    'total_hours' => 0.0,
                    'projects' => [],
                ];
            }

            $byTechnician[$technicianId]['projects'][] = [
                'project' => [
                    'id' => (int) $row->project_id,
                    'name' => (string) ($row->project_name ?? ''),
                ],
                'total_hours' => $projectHours,
            ];

            $byTechnician[$technicianId]['total_hours'] += $projectHours;
        }

        return array_values($byTechnician);
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
