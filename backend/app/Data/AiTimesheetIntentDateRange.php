<?php

declare(strict_types=1);

namespace App\Data;

final class AiTimesheetIntentDateRange
{
    public function __construct(
        public string $type,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $value = null,
        public ?int $count = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? ''),
            from: isset($data['from']) ? (string) $data['from'] : null,
            to: isset($data['to']) ? (string) $data['to'] : null,
            value: isset($data['value']) ? (string) $data['value'] : null,
            count: isset($data['count']) ? (int) $data['count'] : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
        ];

        if ($this->from !== null) {
            $data['from'] = $this->from;
        }
        if ($this->to !== null) {
            $data['to'] = $this->to;
        }
        if ($this->value !== null) {
            $data['value'] = $this->value;
        }
        if ($this->count !== null) {
            $data['count'] = $this->count;
        }

        return $data;
    }
}
