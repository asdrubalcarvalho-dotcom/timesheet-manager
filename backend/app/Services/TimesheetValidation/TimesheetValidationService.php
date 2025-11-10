<?php

namespace App\Services\TimesheetValidation;

use App\Data\TimesheetValidationResult;
use App\Data\TimesheetValidationSnapshot;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\TimesheetAIService;

class TimesheetValidationService
{
    private const DAILY_HOUR_CAP = 12.0;

    public function __construct(
        private readonly TimesheetAIService $aiService
    ) {
    }

    public function summarize(Timesheet $timesheet, ?User $actingUser = null): TimesheetValidationResult
    {
        $timesheet->loadMissing('project.memberRecords', 'technician.user');

        $dailyTotal = $this->calculateDailyTotal($timesheet);
        $overlapRisk = $this->detectOverlap($timesheet);
        $membershipOk = $this->checkMembership($timesheet->project, $timesheet);
        $projectActive = $timesheet->project?->isActive() ?? false;

        $snapshot = TimesheetValidationSnapshot::fromTimesheet(
            $timesheet,
            $dailyTotal,
            $overlapRisk,
            $membershipOk,
            $projectActive
        );

        $warnings = $this->buildWarnings($snapshot);
        $notes = $this->buildNotes($actingUser);
        $status = $this->determineStatus($snapshot, $warnings);
        $aiInsights = $this->aiService->analyzeTimesheet($snapshot);

        $this->persistAiInsights($timesheet, $aiInsights);

        return new TimesheetValidationResult($snapshot, $warnings, $notes, $status, $aiInsights);
    }

    private function calculateDailyTotal(Timesheet $timesheet): float
    {
        return (float) Timesheet::where('technician_id', $timesheet->technician_id)
            ->whereDate('date', $timesheet->date)
            ->sum('hours_worked');
    }

    private function detectOverlap(Timesheet $timesheet): string
    {
        if (!$timesheet->start_time || !$timesheet->end_time) {
            return 'warning';
        }

        $overlapExists = Timesheet::where('technician_id', $timesheet->technician_id)
            ->where('date', $timesheet->date)
            ->where('id', '!=', $timesheet->id)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->where(function ($query) use ($timesheet) {
                $query->where(function ($q) use ($timesheet) {
                    $q->where('start_time', '<=', $timesheet->start_time)
                        ->where('end_time', '>', $timesheet->start_time);
                })->orWhere(function ($q) use ($timesheet) {
                    $q->where('start_time', '<', $timesheet->end_time)
                        ->where('end_time', '>=', $timesheet->end_time);
                })->orWhere(function ($q) use ($timesheet) {
                    $q->where('start_time', '>=', $timesheet->start_time)
                        ->where('end_time', '<=', $timesheet->end_time);
                });
            })
            ->exists();

        return $overlapExists ? 'block' : 'ok';
    }

    private function checkMembership(?Project $project, Timesheet $timesheet): bool
    {
        if (!$project) {
            return false;
        }

        $technicianUser = $timesheet->technician?->user;
        if (!$technicianUser) {
            return true;
        }

        return $project->isUserMember($technicianUser);
    }

    private function buildWarnings(TimesheetValidationSnapshot $snapshot): array
    {
        $warnings = [];

        if ($snapshot->dailyTotalHours > self::DAILY_HOUR_CAP) {
            $warnings[] = sprintf(
                'Daily total (%.2f h) exceeds cap of %.0f h.',
                $snapshot->dailyTotalHours,
                self::DAILY_HOUR_CAP
            );
        }

        if ($snapshot->overlapRisk === 'block') {
            $warnings[] = 'Time interval overlaps with an existing entry.';
        } elseif ($snapshot->overlapRisk === 'warning') {
            $warnings[] = 'Overlap check inconclusive (missing start or end time).';
        }

        if (!$snapshot->membershipOk) {
            $warnings[] = 'Technician is not assigned to this project.';
        }

        if (!$snapshot->projectActive) {
            $warnings[] = 'Project is not active.';
        }

        return $warnings;
    }

    private function determineStatus(TimesheetValidationSnapshot $snapshot, array $warnings): string
    {
        if (
            $snapshot->overlapRisk === 'block' ||
            $snapshot->dailyTotalHours > self::DAILY_HOUR_CAP
        ) {
            return 'block';
        }

        return empty($warnings) ? 'ok' : 'warning';
    }

    private function buildNotes(?User $actingUser): array
    {
        $notes = [
            'daily_hour_cap' => self::DAILY_HOUR_CAP,
        ];

        if ($actingUser) {
            $notes['evaluated_by'] = [
                'id' => $actingUser->id,
                'name' => $actingUser->name,
                'email' => $actingUser->email,
            ];
        }

        return $notes;
    }

    private function persistAiInsights(Timesheet $timesheet, array $aiInsights): void
    {
        $timesheet->ai_flagged = $aiInsights['flagged'] ?? false;
        $timesheet->ai_score = $aiInsights['score'] ?? null;
        $timesheet->ai_feedback = $aiInsights['feedback'] ?? null;

        if ($timesheet->isDirty(['ai_flagged', 'ai_score', 'ai_feedback'])) {
            $timesheet->save();
        }
    }
}
