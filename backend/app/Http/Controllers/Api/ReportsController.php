<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ExpenseExportRequest;
use App\Http\Requests\Reports\ExpenseSummaryRequest;
use App\Http\Requests\Reports\ExportReportRequest;
use App\Http\Requests\Reports\TimesheetPivotRequest;
use App\Http\Requests\Reports\TimesheetSummaryRequest;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Services\Reports\ExpenseReports;
use App\Services\Reports\TimesheetReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportsController extends Controller
{
    public function __construct(
        private readonly TimesheetReports $reports,
        private readonly ExpenseReports $expenseReports,
    ) {
    }

    public function exportTimesheets(ExportReportRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', Timesheet::class);

        $validated = $request->validated();

        return $this->reports->export(
            (array) ($validated['filters'] ?? []),
            (string) $validated['format'],
            $request->user(),
        );
    }

    public function timesheetSummary(TimesheetSummaryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Timesheet::class);

        $validated = $request->validated();

        $filters = [
            'from' => (string) $validated['from'],
            'to' => (string) $validated['to'],
            'group_by' => (array) $validated['group_by'],
            'period' => (string) $validated['period'],
        ];

        return response()->json([
            'meta' => [
                'from' => $filters['from'],
                'to' => $filters['to'],
                'group_by' => $filters['group_by'],
                'period' => $filters['period'],
            ],
            'rows' => $this->reports->summary($filters, $request->user())->values(),
        ]);
    }

    public function timesheetPivot(TimesheetPivotRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Timesheet::class);

        $validated = $request->validated();

        return response()->json(
            $this->reports->pivot($validated, $request->user()),
        );
    }

    public function timesheetsPivotExport(TimesheetPivotRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', Timesheet::class);

        $validated = $request->validated();

        $formatValidated = $this->validate($request, [
            'format' => ['required', 'string', Rule::in(['csv', 'xlsx'])],
        ]);

        return $this->reports->pivotExport(
            $validated,
            (string) $formatValidated['format'],
            $request->user(),
        );
    }

    public function exportExpenses(ExpenseExportRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', Expense::class);

        $validated = $request->validated();

        return $this->expenseReports->export(
            (array) ($validated['filters'] ?? []),
            (string) $validated['format'],
            $request->user(),
        );
    }

    public function expenseSummary(ExpenseSummaryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $validated = $request->validated();

        $filters = [
            'from' => (string) $validated['from'],
            'to' => (string) $validated['to'],
            'group_by' => (array) $validated['group_by'],
            'period' => (string) $validated['period'],
        ];

        return response()->json([
            'meta' => [
                'from' => $filters['from'],
                'to' => $filters['to'],
                'group_by' => $filters['group_by'],
                'period' => $filters['period'],
            ],
            'rows' => $this->expenseReports->summary($filters, $request->user())->values(),
        ]);
    }
}
