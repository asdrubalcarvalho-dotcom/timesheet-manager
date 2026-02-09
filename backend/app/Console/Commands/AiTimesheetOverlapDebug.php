<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AiTimesheetOverlapDebug extends Command
{
    protected $signature = 'ai:timesheet-overlap-debug {tenant_id} {date} {--technician_id=}';

    protected $description = 'Debug overlap validation for timesheets on a specific tenant/date.';

    public function handle(): int
    {
        if (!config('app.debug') || !env('AI_TIMESHEET_DEBUG')) {
            $this->error('This command is available only when APP_DEBUG=true and AI_TIMESHEET_DEBUG is set.');
            return self::FAILURE;
        }

        $tenantId = (string) $this->argument('tenant_id');
        $date = (string) $this->argument('date');
        $technicianId = $this->option('technician_id');

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return self::FAILURE;
        }

        try {
            tenancy()->initialize($tenant);

            $query = Timesheet::on('tenant')
                ->whereDate('date', $date)
                ->orderBy('id');

            if (!empty($technicianId)) {
                $query->where('technician_id', (int) $technicianId);
            }

            $entries = $query->get(['id', 'technician_id', 'date', 'start_time', 'end_time', 'status', 'hours_worked', 'deleted_at']);

            $this->info(sprintf('Tenant: %s | Date: %s | Entries: %d', $tenantId, $date, $entries->count()));

            foreach ($entries as $entry) {
                [$missing, $reason] = $this->missingTimeReason($entry->start_time, $entry->end_time);
                $this->line(sprintf(
                    'id=%s tech=%s start=%s end=%s status=%s hours=%s missing_time=%s reason=%s',
                    $entry->id,
                    $entry->technician_id,
                    $entry->start_time ?? 'null',
                    $entry->end_time ?? 'null',
                    $entry->status ?? 'null',
                    $entry->hours_worked ?? 'null',
                    $missing ? 'yes' : 'no',
                    $reason
                ));
            }
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function missingTimeReason($start, $end): array
    {
        if ($start === null || trim((string) $start) === '') {
            return [true, 'start_time_missing'];
        }

        if ($end === null || trim((string) $end) === '') {
            return [true, 'end_time_missing'];
        }

        if ($this->toMinutes((string) $start) === null) {
            return [true, 'start_time_unparseable'];
        }

        if ($this->toMinutes((string) $end) === null) {
            return [true, 'end_time_unparseable'];
        }

        return [false, 'ok'];
    }

    private function toMinutes(string $time): ?int
    {
        $clean = trim($time);
        if ($clean === '') {
            return null;
        }

        $formats = ['H:i', 'H:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $clean);
                return ((int) $parsed->format('H')) * 60 + (int) $parsed->format('i');
            } catch (\Throwable $e) {
                continue;
            }
        }

        try {
            $parsed = Carbon::parse($clean);
        } catch (\Throwable $e) {
            return null;
        }

        return ((int) $parsed->format('H')) * 60 + (int) $parsed->format('i');
    }
}