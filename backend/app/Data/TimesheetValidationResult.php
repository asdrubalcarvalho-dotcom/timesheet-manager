<?php

namespace App\Data;

class TimesheetValidationResult
{
    public function __construct(
        public TimesheetValidationSnapshot $snapshot,
        public array $warnings = [],
        public array $notes = [],
        public string $status = 'ok',
        public ?array $ai = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'warnings' => $this->warnings,
            'notes' => $this->notes,
            'snapshot' => $this->snapshot->toArray(),
            'ai' => $this->ai,
        ];
    }
}
