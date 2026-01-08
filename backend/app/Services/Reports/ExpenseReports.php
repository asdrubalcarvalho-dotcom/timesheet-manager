<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Expense;
use App\Models\User;
use App\Services\Reports\Exports\CsvExporter;
use App\Services\Reports\Exports\SimpleXlsxExporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExpenseReports
{
    public function __construct(
        private readonly CsvExporter $csv,
        private readonly SimpleXlsxExporter $xlsx,
    ) {
    }

    /**
     * Expenses Summary (pivot) grouped by period + dimensions.
     *
     * @param array{from:string,to:string,group_by:array<int,string>,period:string} $filters
     * @return Collection<int,array<string,mixed>>
     */
    public function summary(array $filters, User $actor): Collection
    {
        if (!$actor->hasPermissionTo('view-expenses')) {
            return collect();
        }

        // Canonical project membership scoping (ACCESS_RULES.md §2, §3)
        // Owner: tenant-wide. Others: restrict to projects where user is a member (project_members).
        $isOwner = $actor->hasRole('Owner');

        $periodExpr = match ((string) $filters['period']) {
            'day' => "DATE_FORMAT(expenses.date, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(expenses.date, '%x-W%v')",
            'month' => "DATE_FORMAT(expenses.date, '%Y-%m')",
            default => throw ValidationException::withMessages(['period' => 'Invalid period.']),
        };

        $groupBy = array_values(array_unique(array_map('strval', $filters['group_by'])));
        $groupBy = array_values(array_intersect($groupBy, ['user', 'project', 'category', 'status']));

        if (count($groupBy) === 0) {
            throw ValidationException::withMessages([
                'group_by' => 'group_by must include at least one of: user, project, category, status',
            ]);
        }

        $query = Expense::query()
            ->join('technicians as tech', 'tech.id', '=', 'expenses.technician_id')
            ->leftJoin('users as u', 'u.id', '=', 'tech.user_id')
            ->join('projects as p', 'p.id', '=', 'expenses.project_id')
            ->where('expenses.date', '>=', (string) $filters['from'])
            ->where('expenses.date', '<=', (string) $filters['to']);

        if (!$isOwner) {
            // Filter to projects where user is a member
            $memberProjectIds = $actor->projects()->pluck('projects.id')->toArray();
            if (count($memberProjectIds) === 0) {
                $query->whereRaw('1 = 0'); // No member projects => empty result
            } else {
                $query->whereIn('expenses.project_id', $memberProjectIds);
            }
        }

        $query->selectRaw("{$periodExpr} as period");
        $groupColumns = ['period'];

        if (in_array('user', $groupBy, true)) {
            $query->addSelect(['tech.user_id as user_id']);
            $query->selectRaw('COALESCE(u.name, tech.name) as user_name');
            $groupColumns[] = 'tech.user_id';
            $groupColumns[] = DB::raw('COALESCE(u.name, tech.name)');
        }

        if (in_array('project', $groupBy, true)) {
            $query->addSelect([
                'expenses.project_id as project_id',
                'p.name as project_name',
            ]);
            $groupColumns[] = 'expenses.project_id';
            $groupColumns[] = 'p.name';
        }

        if (in_array('category', $groupBy, true)) {
            $query->addSelect(['expenses.category as category']);
            $groupColumns[] = 'expenses.category';
        }

        if (in_array('status', $groupBy, true)) {
            $query->addSelect(['expenses.status as status']);
            $groupColumns[] = 'expenses.status';
        }

        $query
            ->selectRaw('SUM(expenses.amount) as total_amount')
            ->selectRaw('COUNT(*) as total_entries')
            ->groupBy($groupColumns)
            ->orderBy('period');

        if (in_array('user', $groupBy, true)) {
            $query->orderBy(DB::raw('COALESCE(u.name, tech.name)'));
        }

        if (in_array('project', $groupBy, true)) {
            $query->orderBy('p.name');
        }

        if (in_array('category', $groupBy, true)) {
            $query->orderBy('expenses.category');
        }

        if (in_array('status', $groupBy, true)) {
            $query->orderBy('expenses.status');
        }

        return $query->get()->map(function ($row) use ($groupBy) {
            $out = [
                'period' => (string) $row->period,
                'total_amount' => round((float) $row->total_amount, 2),
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

            if (in_array('category', $groupBy, true)) {
                $out['category'] = (string) ($row->category ?? '');
            }

            if (in_array('status', $groupBy, true)) {
                $out['status'] = (string) ($row->status ?? '');
            }

            return $out;
        });
    }

    /**
     * Export expenses as a streamed file.
     *
     * Required by /api/reports/expenses/export.
     */
    public function export(array $filters, string $format, User $user): StreamedResponse
    {
        $rows = $this->exportRows($filters, $user);

        $timestamp = now()->format('Ymd_His');
        $exportId = substr((string) Str::uuid(), 0, 8);

        return match ($format) {
            'csv' => $this->csv->stream($rows, "expenses_{$timestamp}_{$exportId}.csv"),
            'xlsx' => $this->xlsx->stream($rows, "expenses_{$timestamp}_{$exportId}.xlsx"),
            default => throw ValidationException::withMessages(['format' => 'Invalid export format.']),
        };
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    private function exportRows(array $filters, User $actor): array
    {
        if (! $actor->hasPermissionTo('view-expenses')) {
            return [];
        }

        // Canonical project membership scoping (ACCESS_RULES.md §2, §3)
        // Owner: tenant-wide. Others: restrict to projects where user is a member (project_members).
        $isOwner = $actor->hasRole('Owner');

        $query = Expense::query()
            ->join('technicians as tech', 'tech.id', '=', 'expenses.technician_id')
            ->leftJoin('users as u', 'u.id', '=', 'tech.user_id')
            ->join('projects as p', 'p.id', '=', 'expenses.project_id');

        $this->applyScopingAndUserFilter($query, $filters, $actor, $isOwner);
        $this->applyFilters($query, $filters);

        if (isset($filters['from']) && $filters['from'] !== null && $filters['from'] !== '') {
            $query->where('expenses.date', '>=', (string) $filters['from']);
        }

        if (isset($filters['to']) && $filters['to'] !== null && $filters['to'] !== '') {
            $query->where('expenses.date', '<=', (string) $filters['to']);
        }

        $data = $query
            ->select([
                'expenses.id as expense_id',
                'expenses.date as date',
                'expenses.amount as amount',
                'expenses.category as category',
                'expenses.status as status',
                'expenses.description as description',
                'expenses.project_id as project_id',
                'p.name as project_name',
                'expenses.technician_id as technician_id',
                'tech.user_id as user_id',
                'tech.name as technician_name',
                'tech.email as technician_email',
                DB::raw('COALESCE(u.name, tech.name) as user_name'),
            ])
            ->orderBy('expenses.date')
            ->orderBy('p.name')
            ->orderBy(DB::raw('COALESCE(u.name, tech.name)'))
            ->get();

        return $data
            ->map(fn ($row) => [
                'expense_id' => (int) $row->expense_id,
                'date' => (string) $row->date,
                'amount' => round((float) $row->amount, 2),
                'category' => (string) ($row->category ?? ''),
                'status' => (string) ($row->status ?? ''),
                'description' => (string) ($row->description ?? ''),
                'project_id' => (int) $row->project_id,
                'project_name' => (string) ($row->project_name ?? ''),
                'technician_id' => (int) $row->technician_id,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'user_name' => (string) ($row->user_name ?? ''),
                'technician_name' => (string) ($row->technician_name ?? ''),
                'technician_email' => (string) ($row->technician_email ?? ''),
            ])
            ->all();
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Expense> $query
     * @param array<string,mixed> $filters
     */
    private function applyScopingAndUserFilter($query, array $filters, User $actor, bool $isOwner): void
    {
        if (!$isOwner) {
            $technicianId = $actor->technician?->id;
            if (!$technicianId) {
                $query->whereRaw('1 = 0');
            } else {
                $memberProjectIds = $actor->projects()->pluck('projects.id')->toArray();
                if (empty($memberProjectIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('expenses.project_id', $memberProjectIds);
                }
            }
            return;
        }

        if (isset($filters['user_id']) && $filters['user_id'] !== null && $filters['user_id'] !== '') {
            $query->where('tech.user_id', '=', (int) $filters['user_id']);
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Expense> $query
     * @param array<string,mixed> $filters
     */
    private function applyFilters($query, array $filters): void
    {
        if (isset($filters['project_id']) && $filters['project_id'] !== null && $filters['project_id'] !== '') {
            $query->where('expenses.project_id', '=', (int) $filters['project_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('expenses.status', '=', (string) $filters['status']);
        }

        if (isset($filters['category']) && $filters['category'] !== null && $filters['category'] !== '') {
            $query->where('expenses.category', '=', (string) $filters['category']);
        }
    }
}
