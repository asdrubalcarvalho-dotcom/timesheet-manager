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
     * Timesheets Pivot (sparse grid): row dimension × column dimension within a date range.
     *
     * @param array{
     *   period:string,
     *   range:array{from:string,to:string},
     *   dimensions:array{rows:array<int,string>,columns:array<int,string>},
     *   metrics?:array<int,string>,
     *   include?:array{row_totals?:bool,column_totals?:bool,grand_total?:bool},
     *   filters?:array<string,mixed>,
     *   sort?:array{rows?:string,columns?:string}
     * } $payload
     *
     * @return array<string,mixed>
     */
    public function pivot(array $payload, User $actor): array
    {
        if (!$actor->hasPermissionTo('view-timesheets')) {
            return [
                'meta' => [
                    'period' => (string) ($payload['period'] ?? ''),
                    'range' => (array) ($payload['range'] ?? []),
                    'timezone' => (string) (config('app.timezone') ?: 'UTC'),
                    'scoped' => 'self',
                    'dimensions' => (array) ($payload['dimensions'] ?? []),
                    'metrics' => (array) ($payload['metrics'] ?? ['hours']),
                ],
                'rows' => [],
                'columns' => [],
                'cells' => [],
                'totals' => [
                    'rows' => [],
                    'columns' => [],
                    'grand' => ['hours' => 0.0],
                ],
            ];
        }

        // TEMP — Phase 1 Transitional Report Visibility (ACCESS_RULES.md §3.4)
        // Reports only: Owner/Admin/Manager => tenant-wide. Technician => self (technician_id).
        $isElevated = $actor->hasAnyRole(['Owner', 'Admin', 'Manager']);

        $period = (string) $payload['period'];
        if (!in_array($period, ['day', 'week', 'month'], true)) {
            throw ValidationException::withMessages(['period' => 'Invalid period.']);
        }

        $range = (array) $payload['range'];
        $from = (string) ($range['from'] ?? '');
        $to = (string) ($range['to'] ?? '');

        $dimensions = (array) $payload['dimensions'];
        $rowDims = array_values((array) ($dimensions['rows'] ?? []));
        $colDims = array_values((array) ($dimensions['columns'] ?? []));

        $rowDim = (string) ($rowDims[0] ?? '');
        $colDim = (string) ($colDims[0] ?? '');

        $allowedDims = ['user', 'project'];
        if (!in_array($rowDim, $allowedDims, true)) {
            throw ValidationException::withMessages(['dimensions.rows' => 'Invalid row dimension.']);
        }
        if (!in_array($colDim, $allowedDims, true)) {
            throw ValidationException::withMessages(['dimensions.columns' => 'Invalid column dimension.']);
        }
        if ($rowDim === $colDim) {
            throw ValidationException::withMessages(['dimensions' => 'rows and columns must be different dimensions']);
        }

        $metrics = array_values((array) ($payload['metrics'] ?? ['hours']));
        if (count($metrics) === 0) {
            $metrics = ['hours'];
        }
        $metrics = array_values(array_intersect(array_map('strval', $metrics), ['hours']));
        if (count($metrics) === 0) {
            $metrics = ['hours'];
        }

        $include = (array) ($payload['include'] ?? []);
        $includeRowTotals = array_key_exists('row_totals', $include) ? (bool) $include['row_totals'] : true;
        $includeColumnTotals = array_key_exists('column_totals', $include) ? (bool) $include['column_totals'] : true;
        $includeGrandTotal = array_key_exists('grand_total', $include) ? (bool) $include['grand_total'] : true;

        $filters = (array) ($payload['filters'] ?? []);

        $query = Timesheet::query()
            ->join('technicians as tech', 'tech.id', '=', 'timesheets.technician_id')
            ->leftJoin('users as u', 'u.id', '=', 'tech.user_id')
            ->join('projects as p', 'p.id', '=', 'timesheets.project_id')
            ->where('timesheets.date', '>=', $from)
            ->where('timesheets.date', '<=', $to);

        if (!$isElevated) {
            $technicianId = $actor->technician?->id;
            if (!$technicianId) {
                // No technician profile => no data (safe default).
                $query->whereRaw('1 = 0');
            } else {
                $query->where('timesheets.technician_id', '=', (int) $technicianId);
            }
        }

        if (isset($filters['project_id']) && $filters['project_id'] !== null && $filters['project_id'] !== '') {
            $query->where('timesheets.project_id', '=', (int) $filters['project_id']);
        }

        if ($isElevated && isset($filters['user_id']) && $filters['user_id'] !== null && $filters['user_id'] !== '') {
            $query->where('tech.user_id', '=', (int) $filters['user_id']);
        }

        if (isset($filters['task_id']) && $filters['task_id'] !== null && $filters['task_id'] !== '') {
            $query->where('timesheets.task_id', '=', (int) $filters['task_id']);
        }

        if (isset($filters['location_id']) && $filters['location_id'] !== null && $filters['location_id'] !== '') {
            $query->where('timesheets.location_id', '=', (int) $filters['location_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $status = (string) $filters['status'];
            if ($status === 'pending') {
                $status = 'submitted';
            }
            $query->where('timesheets.status', '=', $status);
        }

        $rowIdExpr = $rowDim === 'user' ? 'tech.user_id' : 'timesheets.project_id';
        $rowLabelExpr = $rowDim === 'user' ? 'COALESCE(u.name, tech.name)' : 'p.name';
        $colIdExpr = $colDim === 'user' ? 'tech.user_id' : 'timesheets.project_id';
        $colLabelExpr = $colDim === 'user' ? 'COALESCE(u.name, tech.name)' : 'p.name';

        $query
            ->selectRaw("{$rowIdExpr} as row_id")
            ->selectRaw("{$rowLabelExpr} as row_label")
            ->selectRaw("{$colIdExpr} as column_id")
            ->selectRaw("{$colLabelExpr} as column_label")
            ->selectRaw('SUM(timesheets.hours_worked) as hours')
            ->groupBy([
                DB::raw($rowIdExpr),
                DB::raw($rowLabelExpr),
                DB::raw($colIdExpr),
                DB::raw($colLabelExpr),
            ])
            ->havingRaw('SUM(timesheets.hours_worked) > 0');

        $rawCells = $query->get();

        /** @var array<string,string> $rowsMap */
        $rowsMap = [];
        /** @var array<string,string> $colsMap */
        $colsMap = [];
        /** @var array<int,array{row_id:string,column_id:string,hours:float}> $cells */
        $cells = [];

        /** @var array<string,float> $rowTotals */
        $rowTotals = [];
        /** @var array<string,float> $colTotals */
        $colTotals = [];
        $grandTotal = 0.0;

        foreach ($rawCells as $cell) {
            $rowId = (string) $cell->row_id;
            $colId = (string) $cell->column_id;
            $hours = round((float) $cell->hours, 2);

            $rowsMap[$rowId] = (string) ($cell->row_label ?? '');
            $colsMap[$colId] = (string) ($cell->column_label ?? '');

            $cells[] = [
                'row_id' => $rowId,
                'column_id' => $colId,
                'hours' => $hours,
            ];

            $rowTotals[$rowId] = round(($rowTotals[$rowId] ?? 0.0) + $hours, 2);
            $colTotals[$colId] = round(($colTotals[$colId] ?? 0.0) + $hours, 2);
            $grandTotal = round($grandTotal + $hours, 2);
        }

        $sort = (array) ($payload['sort'] ?? []);
        $rowsSort = (string) ($sort['rows'] ?? 'name');
        $colsSort = (string) ($sort['columns'] ?? 'name');

        $rows = collect($rowsMap)
            ->map(fn (string $label, string $id) => [
                'id' => $id,
                'label' => $label,
                '_total' => (float) ($rowTotals[$id] ?? 0.0),
            ]);

        $columns = collect($colsMap)
            ->map(fn (string $label, string $id) => [
                'id' => $id,
                'label' => $label,
                '_total' => (float) ($colTotals[$id] ?? 0.0),
            ]);

        $rows = match ($rowsSort) {
            'total_desc' => $rows->sortByDesc('_total')->values(),
            'total_asc' => $rows->sortBy('_total')->values(),
            default => $rows->sortBy('label')->values(),
        };

        $columns = match ($colsSort) {
            'total_desc' => $columns->sortByDesc('_total')->values(),
            'total_asc' => $columns->sortBy('_total')->values(),
            default => $columns->sortBy('label')->values(),
        };

        $rowsOut = $rows->map(fn (array $r) => ['id' => (string) $r['id'], 'label' => (string) $r['label']])->all();
        $colsOut = $columns->map(fn (array $c) => ['id' => (string) $c['id'], 'label' => (string) $c['label']])->all();

        $totalsRowsOut = [];
        if ($includeRowTotals) {
            $totalsRowsOut = $rows
                ->map(fn (array $r) => [
                    'row_id' => (string) $r['id'],
                    'hours' => round((float) $r['_total'], 2),
                ])
                ->values()
                ->all();
        }

        $totalsColsOut = [];
        if ($includeColumnTotals) {
            $totalsColsOut = $columns
                ->map(fn (array $c) => [
                    'column_id' => (string) $c['id'],
                    'hours' => round((float) $c['_total'], 2),
                ])
                ->values()
                ->all();
        }

        $grandOut = $includeGrandTotal ? ['hours' => $grandTotal] : null;

        return [
            'meta' => [
                'period' => $period,
                'range' => ['from' => $from, 'to' => $to],
                'timezone' => (string) (config('app.timezone') ?: 'UTC'),
                'scoped' => $isElevated ? 'all' : 'self',
                'dimensions' => [
                    'rows' => [$rowDim],
                    'columns' => [$colDim],
                ],
                'metrics' => $metrics,
            ],
            'rows' => $rowsOut,
            'columns' => $colsOut,
            'cells' => $cells,
            'totals' => [
                'rows' => $totalsRowsOut,
                'columns' => $totalsColsOut,
                'grand' => $grandOut,
            ],
        ];
    }

    /**
     * Stream a pivot export (rectangular matrix) using existing exporters.
     *
     * Accepts the same payload as pivot(), and a required format.
     * Missing row×column combinations are filled with 0.
     */
    public function pivotExport(array $payload, string $format, User $actor): StreamedResponse
    {
        $pivot = $this->pivot($payload, $actor);

        $meta = (array) ($pivot['meta'] ?? []);
        $range = (array) ($meta['range'] ?? []);
        $from = (string) ($range['from'] ?? '');
        $to = (string) ($range['to'] ?? '');

        $dims = (array) ($meta['dimensions'] ?? []);
        $rowDim = (string) ((array) ($dims['rows'] ?? []))[0] ?? '';
        $colDim = (string) ((array) ($dims['columns'] ?? []))[0] ?? '';

        $include = (array) ($payload['include'] ?? []);
        $includeRowTotals = array_key_exists('row_totals', $include) ? (bool) $include['row_totals'] : true;
        $includeColumnTotals = array_key_exists('column_totals', $include) ? (bool) $include['column_totals'] : true;
        $includeGrandTotal = array_key_exists('grand_total', $include) ? (bool) $include['grand_total'] : true;

        $rowHeader = match ($rowDim) {
            'user' => 'User',
            'project' => 'Project',
            default => 'Row',
        };

        // Use labels exactly as provided by pivot output.
        $rows = array_values((array) ($pivot['rows'] ?? []));
        $columns = array_values((array) ($pivot['columns'] ?? []));
        $cells = array_values((array) ($pivot['cells'] ?? []));

        /** @var array<string,string> $rowLabels */
        $rowLabels = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $rowLabels[(string) ($r['id'] ?? '')] = (string) ($r['label'] ?? '');
        }

        /** @var array<string,string> $colLabels */
        $colLabels = [];
        foreach ($columns as $c) {
            if (!is_array($c)) {
                continue;
            }
            $colLabels[(string) ($c['id'] ?? '')] = (string) ($c['label'] ?? '');
        }

        /** @var array<string,array<string,float>> $matrix */
        $matrix = [];
        /** @var array<string,float> $rowTotals */
        $rowTotals = [];
        /** @var array<string,float> $colTotals */
        $colTotals = [];
        $grandTotal = 0.0;

        foreach ($cells as $cell) {
            if (!is_array($cell)) {
                continue;
            }
            $rId = (string) ($cell['row_id'] ?? '');
            $cId = (string) ($cell['column_id'] ?? '');
            $hours = round((float) ($cell['hours'] ?? 0), 2);

            $matrix[$rId][$cId] = $hours;
            $rowTotals[$rId] = round(($rowTotals[$rId] ?? 0.0) + $hours, 2);
            $colTotals[$cId] = round(($colTotals[$cId] ?? 0.0) + $hours, 2);
            $grandTotal = round($grandTotal + $hours, 2);
        }

        $headers = [$rowHeader, ...array_values($colLabels)];
        if ($includeRowTotals) {
            $headers[] = 'Row Total';
        }

        /** @var array<int,array<string,mixed>> $exportRows */
        $exportRows = [];

        foreach ($rowLabels as $rowId => $label) {
            $row = [
                $rowHeader => $label,
            ];

            foreach ($colLabels as $colId => $colLabel) {
                $row[$colLabel] = (float) ($matrix[$rowId][$colId] ?? 0.0);
            }

            if ($includeRowTotals) {
                $row['Row Total'] = (float) ($rowTotals[$rowId] ?? 0.0);
            }

            $exportRows[] = $row;
        }

        if ($includeColumnTotals) {
            $totalsRow = [
                $rowHeader => 'Column Total',
            ];

            foreach ($colLabels as $colId => $colLabel) {
                $totalsRow[$colLabel] = (float) ($colTotals[$colId] ?? 0.0);
            }

            if ($includeRowTotals) {
                $totalsRow['Row Total'] = $includeGrandTotal ? $grandTotal : (float) array_sum($colTotals);
            }

            $exportRows[] = $totalsRow;
        }

        $filename = "timesheets_pivot_{$from}_{$to}.{$format}";

        $response = match ($format) {
            'csv' => $this->csv->stream($exportRows, $filename),
            'xlsx' => $this->xlsx->stream($exportRows, $filename),
            default => throw ValidationException::withMessages(['format' => 'Invalid export format.']),
        };

        return $response;
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
