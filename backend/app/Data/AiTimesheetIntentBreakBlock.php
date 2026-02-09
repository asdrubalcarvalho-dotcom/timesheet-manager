<?php

declare(strict_types=1);

namespace App\Data;

final class AiTimesheetIntentBreakBlock
{
    public function __construct(
        public string $from,
        public string $to
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            from: (string) ($data['from'] ?? ''),
            to: (string) ($data['to'] ?? '')
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
