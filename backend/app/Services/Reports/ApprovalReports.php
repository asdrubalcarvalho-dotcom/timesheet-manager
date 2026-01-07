<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ApprovalReports
{
    /**
     * @param array{
     *   range:array{from:string,to:string},
     *   include:array{timesheets:bool,expenses:bool}
     * } $payload
     * @return array{meta:array{from:string,to:string,scoped:string},days:array<string,mixed>}
     */
    public function heatmap(array $payload, User $actor): array
    {
        // TEMP — Phase 1 Transitional Report Visibility (ACCESS_RULES.md §3.4)
        // Reports only: Owner/Admin/Manager => tenant-wide approvals.
        $isElevated = $actor->hasAnyRole(['Owner', 'Admin', 'Manager']);
        if (!$isElevated) {
            throw new AuthorizationException('Forbidden');
        }

        $from = (string) $payload['range']['from'];
        $to = (string) $payload['range']['to'];

        $requestWantsTimesheets = (bool) $payload['include']['timesheets'];
        $requestWantsExpenses = (bool) $payload['include']['expenses'];

        $includeTimesheets = $requestWantsTimesheets && $actor->hasPermissionTo('approve-timesheets');
        $includeExpenses = $requestWantsExpenses && $actor->hasPermissionTo('approve-expenses');

        $days = [];

        if ($includeTimesheets) {
            $days = $this->mergeEntityCounts(
                $days,
                'timesheets',
                $this->timesheetPendingCountsByDay($actor, $from, $to),
                $this->timesheetApprovedCountsByDay($actor, $from, $to),
            );
        }

        if ($includeExpenses) {
            $days = $this->mergeEntityCounts(
                $days,
                'expenses',
                $this->expensePendingCountsByDay($actor, $from, $to),
                $this->expenseApprovedCountsByDay($actor, $from, $to),
            );
        }

        // Ensure totals are present and consistent for all returned days.
        foreach ($days as $day => $row) {
            $timesheetsPending = (int) (($row['timesheets']['pending'] ?? 0) ?: 0);
            $expensesPending = (int) (($row['expenses']['pending'] ?? 0) ?: 0);

            $totalPending = 0;
            if ($includeTimesheets) {
                $totalPending += $timesheetsPending;
            }
            if ($includeExpenses) {
                $totalPending += $expensesPending;
            }

            $days[$day]['timesheets'] = [
                'pending' => (int) (($row['timesheets']['pending'] ?? 0) ?: 0),
                'approved' => (int) (($row['timesheets']['approved'] ?? 0) ?: 0),
            ];
            $days[$day]['expenses'] = [
                'pending' => (int) (($row['expenses']['pending'] ?? 0) ?: 0),
                'approved' => (int) (($row['expenses']['approved'] ?? 0) ?: 0),
            ];
            $days[$day]['total_pending'] = $totalPending;
        }

        ksort($days);

        return [
            'meta' => [
                'from' => $from,
                'to' => $to,
                'scoped' => 'all',
            ],
            'days' => $days,
        ];
    }

    /**
     * @return array<string,int> day => count
     */
    private function timesheetPendingCountsByDay(User $actor, string $from, string $to): array
    {
        $query = Timesheet::query()
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as c')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->where('status', '=', 'submitted');

        $this->applyTimesheetApprovalScoping($query, $actor);

        return $query
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('c', 'day')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @return array<string,int> day => count
     */
    private function timesheetApprovedCountsByDay(User $actor, string $from, string $to): array
    {
        $query = Timesheet::query()
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as c')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->whereIn('status', ['approved', 'closed']);

        $this->applyTimesheetApprovalScoping($query, $actor);

        return $query
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('c', 'day')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Timesheet> $query
     */
    private function applyTimesheetApprovalScoping($query, User $actor): void
    {
        // TEMP — Phase 1 Transitional Report Visibility (ACCESS_RULES.md §3.4)
        // Reports only: Owner/Admin/Manager => tenant-wide.
        if ($actor->hasAnyRole(['Owner', 'Admin', 'Manager'])) {
            return;
        }

        // Non-elevated users should not get approval heatmaps.
        $query->whereRaw('1 = 0');
    }

    /**
     * @return array<string,int> day => count
     */
    private function expensePendingCountsByDay(User $actor, string $from, string $to): array
    {
        // Contract uses the label "pending"; in the current workflow, manager-pending expenses are status=submitted.
        $query = Expense::query()
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as c')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->where('status', '=', 'submitted');

        $this->applyExpenseApprovalScoping($query, $actor);

        return $query
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('c', 'day')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @return array<string,int> day => count
     */
    private function expenseApprovedCountsByDay(User $actor, string $from, string $to): array
    {
        // Consider all post-manager-approval states as approved.
        $approvedStatuses = ['approved', 'finance_review', 'finance_approved', 'paid'];

        $query = Expense::query()
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as c')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->whereIn('status', $approvedStatuses);

        $this->applyExpenseApprovalScoping($query, $actor);

        return $query
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('c', 'day')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Expense> $query
     */
    private function applyExpenseApprovalScoping($query, User $actor): void
    {
        // TEMP — Phase 1 Transitional Report Visibility (ACCESS_RULES.md §3.4)
        // Reports only: Owner/Admin/Manager => tenant-wide.
        if ($actor->hasAnyRole(['Owner', 'Admin', 'Manager'])) {
            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * @param array<string,mixed> $days
     * @param array<string,int> $pendingByDay
     * @param array<string,int> $approvedByDay
     * @return array<string,mixed>
     */
    private function mergeEntityCounts(array $days, string $entity, array $pendingByDay, array $approvedByDay): array
    {
        $allDays = array_unique(array_merge(array_keys($pendingByDay), array_keys($approvedByDay)));

        foreach ($allDays as $day) {
            if (!isset($days[$day])) {
                $days[$day] = [
                    'timesheets' => ['pending' => 0, 'approved' => 0],
                    'expenses' => ['pending' => 0, 'approved' => 0],
                    'total_pending' => 0,
                ];
            }

            $days[$day][$entity] = [
                'pending' => (int) ($pendingByDay[$day] ?? 0),
                'approved' => (int) ($approvedByDay[$day] ?? 0),
            ];
        }

        return $days;
    }
}
