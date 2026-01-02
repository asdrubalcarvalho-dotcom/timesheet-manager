<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ApprovalsAiQueryRequest;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Reports\ApprovalReports;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AiApprovalsController extends Controller
{
    public function __construct(
        private readonly ApprovalReports $approvalReports,
    ) {
    }

    public function query(ApprovalsAiQueryRequest $request): JsonResponse|StreamedResponse
    {
        $payload = $request->validatedPayload();

        /** @var User $user */
        $user = $request->user();

        $requestedTypes = $payload['types'];
        $hasTypeFilter = !empty($requestedTypes);

        $canApproveTimesheets = $user->hasPermissionTo('approve-timesheets');
        $canApproveExpenses = $user->hasPermissionTo('approve-expenses');

        $effectiveTypes = [];
        if ($hasTypeFilter) {
            if (in_array('timesheets', $requestedTypes, true) && $canApproveTimesheets) {
                $effectiveTypes[] = 'timesheets';
            }
            if (in_array('expenses', $requestedTypes, true) && $canApproveExpenses) {
                $effectiveTypes[] = 'expenses';
            }
        } else {
            if ($canApproveTimesheets) {
                $effectiveTypes[] = 'timesheets';
            }
            if ($canApproveExpenses) {
                $effectiveTypes[] = 'expenses';
            }
        }

        if (empty($effectiveTypes)) {
            abort(403, 'Forbidden');
        }

        if (in_array('timesheets', $effectiveTypes, true)) {
            $this->authorize('approve', Timesheet::class);
        }

        if (in_array('expenses', $effectiveTypes, true)) {
            $this->authorize('approve', Expense::class);
        }

        $includeTimesheets = in_array('timesheets', $effectiveTypes, true);
        $includeExpenses = in_array('expenses', $effectiveTypes, true);

        $heatmap = $this->approvalReports->heatmap([
            'range' => $payload['range'],
            'include' => [
                'timesheets' => $includeTimesheets,
                'expenses' => $includeExpenses,
            ],
        ], $user);

        $insights = $this->summarizeHeatmap(
            $payload['range']['from'],
            $payload['range']['to'],
            $effectiveTypes,
            $heatmap['days'] ?? [],
            (string) $payload['format'],
        );

        $body = [
            'answer' => $insights['answer'],
            'highlights' => $insights['highlights'],
            'meta' => [
                'scoped' => $insights['scoped'],
                'used_reports' => ['approvals_heatmap'],
            ],
        ];

        if ((string) $payload['format'] === 'json') {
            $filename = sprintf('approvals_ai_%s_%s.json', $payload['range']['from'], $payload['range']['to']);

            return response()->streamDownload(
                static function () use ($body): void {
                    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                },
                $filename,
                [
                    'Content-Type' => 'application/json',
                ],
            );
        }

        return response()->json($body);
    }

    /**
     * @param array<int, string> $effectiveTypes
     * @param array<string, mixed> $days
     * @return array{answer:string,highlights:array<int,array{date:string,timesheets_pending:int,expenses_pending:int}>,scoped:string}
     */
    private function summarizeHeatmap(
        string $from,
        string $to,
        array $effectiveTypes,
        array $days,
        string $format,
    ): array {
        $includeTimesheets = in_array('timesheets', $effectiveTypes, true);
        $includeExpenses = in_array('expenses', $effectiveTypes, true);

        $timesheetsTotal = 0;
        $expensesTotal = 0;
        $totalPending = 0;

        $peakDay = null;
        $peakTotal = 0;

        foreach ($days as $date => $row) {
            $t = (int) (($row['timesheets']['pending'] ?? 0) ?: 0);
            $e = (int) (($row['expenses']['pending'] ?? 0) ?: 0);

            if (!$includeTimesheets) {
                $t = 0;
            }
            if (!$includeExpenses) {
                $e = 0;
            }

            $timesheetsTotal += $t;
            $expensesTotal += $e;
            $dayTotal = $t + $e;
            $totalPending += $dayTotal;

            if ($dayTotal > $peakTotal) {
                $peakTotal = $dayTotal;
                $peakDay = (string) $date;
            }
        }

        $highlights = $this->topDays($days, $effectiveTypes);

        $scoped = count($effectiveTypes) === 2 ? 'all' : 'partial';

        $answer = $this->formatAnswer(
            $format,
            $from,
            $to,
            $effectiveTypes,
            $totalPending,
            $peakDay,
            $peakTotal,
            $timesheetsTotal,
            $expensesTotal,
            $highlights,
        );

        return [
            'answer' => $answer,
            'highlights' => $highlights,
            'scoped' => $scoped,
        ];
    }

    /**
     * @param array<string, mixed> $days
     * @param array<int, string> $effectiveTypes
     * @return array<int, array{date:string,timesheets_pending:int,expenses_pending:int}>
     */
    private function topDays(array $days, array $effectiveTypes): array
    {
        $includeTimesheets = in_array('timesheets', $effectiveTypes, true);
        $includeExpenses = in_array('expenses', $effectiveTypes, true);

        $rows = [];
        foreach ($days as $date => $row) {
            $t = (int) (($row['timesheets']['pending'] ?? 0) ?: 0);
            $e = (int) (($row['expenses']['pending'] ?? 0) ?: 0);

            if (!$includeTimesheets) {
                $t = 0;
            }
            if (!$includeExpenses) {
                $e = 0;
            }

            $rows[] = [
                'date' => (string) $date,
                'timesheets_pending' => $t,
                'expenses_pending' => $e,
                'total' => $t + $e,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        $highlights = [];
        foreach ($rows as $row) {
            if (count($highlights) >= 3) {
                break;
            }
            if ((int) $row['total'] <= 0) {
                continue;
            }
            $highlights[] = [
                'date' => (string) $row['date'],
                'timesheets_pending' => (int) $row['timesheets_pending'],
                'expenses_pending' => (int) $row['expenses_pending'],
            ];
        }

        return $highlights;
    }

    /**
     * @param array<int, string> $effectiveTypes
     * @param array<int, array{date:string,timesheets_pending:int,expenses_pending:int}> $highlights
     */
    private function formatAnswer(
        string $format,
        string $from,
        string $to,
        array $effectiveTypes,
        int $totalPending,
        ?string $peakDay,
        int $peakTotal,
        int $timesheetsTotal,
        int $expensesTotal,
        array $highlights,
    ): string {
        $typeLabel = $this->typeLabel($effectiveTypes);

        if ($totalPending === 0) {
            $base = "No pending approvals were found for {$typeLabel} between {$from} and {$to}.";
            return $format === 'markdown' ? "**{$base}**" : $base;
        }

        $parts = [];
        $parts[] = "Based on the approvals heatmap for {$typeLabel} between {$from} and {$to}";
        $parts[] = "there were {$totalPending} pending items";

        if ($peakDay !== null && $peakTotal > 0) {
            $parts[] = "with a peak on {$peakDay} ({$peakTotal})";
        }

        $answer = implode(' ', $parts) . '.';

        $shareSentence = $this->shareSentence($effectiveTypes, $timesheetsTotal, $expensesTotal);
        if ($shareSentence !== null) {
            $answer .= ' ' . $shareSentence;
        }

        if ($format === 'markdown' && !empty($highlights)) {
            $answer .= "\n\n**Top days (pending)**\n";
            foreach ($highlights as $h) {
                $answer .= "- {$h['date']}: timesheets {$h['timesheets_pending']}, expenses {$h['expenses_pending']}\n";
            }
        }

        return $answer;
    }

    /**
     * @param array<int, string> $effectiveTypes
     */
    private function typeLabel(array $effectiveTypes): string
    {
        sort($effectiveTypes);

        if ($effectiveTypes === ['expenses']) {
            return 'expenses';
        }
        if ($effectiveTypes === ['timesheets']) {
            return 'timesheets';
        }

        return 'timesheets and expenses';
    }

    /**
     * @param array<int, string> $effectiveTypes
     */
    private function shareSentence(array $effectiveTypes, int $timesheetsTotal, int $expensesTotal): ?string
    {
        $includeTimesheets = in_array('timesheets', $effectiveTypes, true);
        $includeExpenses = in_array('expenses', $effectiveTypes, true);

        if (!$includeTimesheets || !$includeExpenses) {
            return null;
        }

        $total = $timesheetsTotal + $expensesTotal;
        if ($total <= 0) {
            return null;
        }

        $timesheetsPct = (int) round(($timesheetsTotal / $total) * 100);
        $expensesPct = 100 - $timesheetsPct;

        return "Timesheets account for ~{$timesheetsPct}% of the pending volume (expenses ~{$expensesPct}%).";
    }
}
