<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ExportReportRequest;
use App\Http\Requests\Reports\TimesheetSummaryRequest;
use App\Models\Timesheet;
use App\Services\Reports\TimesheetReports;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportsController extends Controller
{
    public function __construct(
        private readonly TimesheetReports $reports
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
}
