<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Timesheet;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Technician;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $user = $request->user();
        $technician = Technician::where('user_id', $user->id)->first();
        
        // Define date range (last 30 days by default)
        $dateFrom = $request->input('date_from', Carbon::now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->input('date_to', Carbon::now()->format('Y-m-d'));

        // Base queries based on user role
        $timesheetsQuery = Timesheet::whereBetween('date', [$dateFrom, $dateTo]);
        $expensesQuery = Expense::whereBetween('date', [$dateFrom, $dateTo]);

        // Technicians see only their data
        if ($user->hasRole('Technician') && $technician) {
            $timesheetsQuery->where('technician_id', $technician->id);
            $expensesQuery->where('technician_id', $technician->id);
        }

        // Managers see their projects + own data
        if ($user->hasRole('Manager')) {
            $managedProjectIds = $user->getManagedProjectIds();
            
            $timesheetsQuery->where(function ($query) use ($technician, $managedProjectIds) {
                $query->whereIn('project_id', $managedProjectIds);
                if ($technician) {
                    $query->orWhere('technician_id', $technician->id);
                }
            });

            $expensesQuery->where(function ($query) use ($technician, $managedProjectIds) {
                $query->whereIn('project_id', $managedProjectIds);
                if ($technician) {
                    $query->orWhere('technician_id', $technician->id);
                }
            });
        }

        // Summary stats
        $totalHours = $timesheetsQuery->sum('hours_worked');
        $totalExpenses = $expensesQuery->sum('amount');
        $pendingTimesheets = (clone $timesheetsQuery)->where('status', 'submitted')->count();
        $pendingExpenses = (clone $expensesQuery)->where('status', 'submitted')->count();
        $approvedTimesheets = (clone $timesheetsQuery)->where('status', 'approved')->count();
        $approvedExpenses = (clone $expensesQuery)->where('status', 'approved')->count();

        // Hours by project
        $hoursByProject = (clone $timesheetsQuery)
            ->select('project_id', DB::raw('SUM(hours_worked) as total_hours'))
            ->with('project:id,name')
            ->groupBy('project_id')
            ->orderByDesc('total_hours')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'project_name' => $item->project->name ?? 'Unknown',
                    'total_hours' => round((float) $item->total_hours, 2)
                ];
            });

        // Expenses by project
        $expensesByProject = (clone $expensesQuery)
            ->select('project_id', DB::raw('SUM(amount) as total_amount'))
            ->with('project:id,name')
            ->groupBy('project_id')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'project_name' => $item->project->name ?? 'Unknown',
                    'total_amount' => round((float) $item->total_amount, 2)
                ];
            });

        // Hours by status
        $hoursByStatus = (clone $timesheetsQuery)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(hours_worked) as total_hours'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                return [
                    'status' => ucfirst($item->status),
                    'count' => (int) $item->count,
                    'total_hours' => round((float) $item->total_hours, 2)
                ];
            });

        // Expenses by status
        $expensesByStatus = (clone $expensesQuery)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total_amount'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                return [
                    'status' => ucfirst($item->status),
                    'count' => (int) $item->count,
                    'total_amount' => round((float) $item->total_amount, 2)
                ];
            });

        // Daily hours trend (last 30 days)
        $dailyHours = Timesheet::whereBetween('date', [$dateFrom, $dateTo])
            ->when($user->hasRole('Technician') && $technician, function ($query) use ($technician) {
                return $query->where('technician_id', $technician->id);
            })
            ->when($user->hasRole('Manager'), function ($query) use ($user, $technician) {
                $managedProjectIds = $user->getManagedProjectIds();
                return $query->where(function ($q) use ($technician, $managedProjectIds) {
                    $q->whereIn('project_id', $managedProjectIds);
                    if ($technician) {
                        $q->orWhere('technician_id', $technician->id);
                    }
                });
            })
            ->select('date', DB::raw('SUM(hours_worked) as total_hours'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'hours' => round((float) $item->total_hours, 2)
                ];
            });

        // Daily expenses trend (last 30 days)
        $dailyExpenses = Expense::whereBetween('date', [$dateFrom, $dateTo])
            ->when($user->hasRole('Technician') && $technician, function ($query) use ($technician) {
                return $query->where('technician_id', $technician->id);
            })
            ->when($user->hasRole('Manager'), function ($query) use ($user, $technician) {
                $managedProjectIds = $user->getManagedProjectIds();
                return $query->where(function ($q) use ($technician, $managedProjectIds) {
                    $q->whereIn('project_id', $managedProjectIds);
                    if ($technician) {
                        $q->orWhere('technician_id', $technician->id);
                    }
                });
            })
            ->select('date', DB::raw('SUM(amount) as total_amount'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'amount' => round((float) $item->total_amount, 2)
                ];
            });

        return response()->json([
            'summary' => [
                'total_hours' => round((float) $totalHours, 2),
                'total_expenses' => round((float) $totalExpenses, 2),
                'pending_timesheets' => $pendingTimesheets,
                'pending_expenses' => $pendingExpenses,
                'approved_timesheets' => $approvedTimesheets,
                'approved_expenses' => $approvedExpenses,
            ],
            'hours_by_project' => $hoursByProject,
            'expenses_by_project' => $expensesByProject,
            'hours_by_status' => $hoursByStatus,
            'expenses_by_status' => $expensesByStatus,
            'daily_hours' => $dailyHours,
            'daily_expenses' => $dailyExpenses,
        ]);
    }

    /**
     * Get top projects by hours or expenses
     */
    public function getTopProjects(Request $request): JsonResponse
    {
        $user = $request->user();
        $technician = Technician::where('user_id', $user->id)->first();
        $limit = $request->input('limit', 5);
        $metric = $request->input('metric', 'hours'); // 'hours' or 'expenses'

        $dateFrom = $request->input('date_from', Carbon::now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->input('date_to', Carbon::now()->format('Y-m-d'));

        if ($metric === 'hours') {
            $query = Timesheet::whereBetween('date', [$dateFrom, $dateTo])
                ->when($user->hasRole('Technician') && $technician, function ($q) use ($technician) {
                    return $q->where('technician_id', $technician->id);
                })
                ->select('project_id', DB::raw('SUM(hours_worked) as value'))
                ->with('project:id,name')
                ->groupBy('project_id')
                ->orderByDesc('value')
                ->limit($limit)
                ->get()
                ->map(function ($item) {
                    return [
                        'project_name' => $item->project->name ?? 'Unknown',
                        'value' => round((float) $item->value, 2),
                        'metric' => 'hours'
                    ];
                });
        } else {
            $query = Expense::whereBetween('date', [$dateFrom, $dateTo])
                ->when($user->hasRole('Technician') && $technician, function ($q) use ($technician) {
                    return $q->where('technician_id', $technician->id);
                })
                ->select('project_id', DB::raw('SUM(amount) as value'))
                ->with('project:id,name')
                ->groupBy('project_id')
                ->orderByDesc('value')
                ->limit($limit)
                ->get()
                ->map(function ($item) {
                    return [
                        'project_name' => $item->project->name ?? 'Unknown',
                        'value' => round((float) $item->value, 2),
                        'metric' => 'expenses'
                    ];
                });
        }

        return response()->json($query);
    }
}
