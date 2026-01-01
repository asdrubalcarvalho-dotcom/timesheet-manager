<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ExportReportRequest;
use App\Http\Requests\Reports\RunReportRequest;
use App\Models\Timesheet;
use App\Services\Reports\TimesheetReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class ReportsController extends Controller
{
    public function __construct(
        private readonly TimesheetReports $reports
    ) {
    }

    public function run(RunReportRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Timesheet::class);

        $validated = $request->validated();

        $result = $this->reports->run(
            $request->user(),
            (string) $validated['report'],
            (array) ($validated['filters'] ?? []),
            (string) $validated['group_by'],
        );

        return response()->json($result);
    }

    public function export(ExportReportRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Timesheet::class);

        $validated = $request->validated();

        $result = $this->reports->export(
            $request->user(),
            (string) $validated['report'],
            (array) ($validated['filters'] ?? []),
            (string) $validated['group_by'],
            (string) $validated['format'],
        );

        return response()->json($result);
    }

    public function download(Request $request, string $id)
    {
        $this->authorize('viewAny', Timesheet::class);

        $record = $this->reports->resolveDownload($id);
        if (!$record) {
            return response()->json(['message' => 'Export not found or expired.'], 404);
        }

        if (($record['user_id'] ?? null) !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $path = (string) ($record['path'] ?? '');
        if ($path === '' || !Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'Export file missing.'], 404);
        }

        $this->reports->forgetDownload($id);

        $fullPath = Storage::disk('local')->path($path);
        $filename = basename($path);

        return response()->download($fullPath, $filename)->deleteFileAfterSend(true);
    }
}
