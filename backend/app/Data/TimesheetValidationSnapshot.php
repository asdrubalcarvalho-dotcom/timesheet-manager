<?php

namespace App\Data;

use App\Models\Timesheet;

class TimesheetValidationSnapshot
{
    public function __construct(
        public ?int $timesheetId,
        public int $technicianId,
        public int $projectId,
        public int $taskId,
        public int $locationId,
        public string $date,
        public float $hoursWorked,
        public ?string $startTime,
        public ?string $endTime,
        public float $dailyTotalHours,
        public string $overlapRisk,
        public bool $membershipOk,
        public bool $projectActive,
        public bool $aiFlagged,
        public ?float $aiScore,
        public ?array $aiFeedback
    ) {
    }

    public static function fromTimesheet(
        Timesheet $timesheet,
        float $dailyTotalHours,
        string $overlapRisk,
        bool $membershipOk,
        bool $projectActive
    ): self {
        return new self(
            timesheetId: $timesheet->id,
            technicianId: $timesheet->technician_id,
            projectId: $timesheet->project_id,
            taskId: (int) $timesheet->task_id,
            locationId: (int) $timesheet->location_id,
            date: $timesheet->date?->toDateString() ?? (string) $timesheet->date,
            hoursWorked: (float) $timesheet->hours_worked,
            startTime: $timesheet->start_time ? (string) $timesheet->start_time : null,
            endTime: $timesheet->end_time ? (string) $timesheet->end_time : null,
            dailyTotalHours: $dailyTotalHours,
            overlapRisk: $overlapRisk,
            membershipOk: $membershipOk,
            projectActive: $projectActive,
            aiFlagged: (bool) $timesheet->ai_flagged,
            aiScore: $timesheet->ai_score !== null ? (float) $timesheet->ai_score : null,
            aiFeedback: $timesheet->ai_feedback ?? null
        );
    }

    public function toArray(): array
    {
        return [
            'timesheet_id' => $this->timesheetId,
            'technician_id' => $this->technicianId,
            'project_id' => $this->projectId,
            'task_id' => $this->taskId,
            'location_id' => $this->locationId,
            'date' => $this->date,
            'hours_worked' => $this->hoursWorked,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'daily_total_hours' => $this->dailyTotalHours,
            'overlap_risk' => $this->overlapRisk,
            'membership_ok' => $this->membershipOk,
            'project_active' => $this->projectActive,
            'ai_flagged' => $this->aiFlagged,
            'ai_score' => $this->aiScore,
            'ai_feedback' => $this->aiFeedback,
        ];
    }
}
