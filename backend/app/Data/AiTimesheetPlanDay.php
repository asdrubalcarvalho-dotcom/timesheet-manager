<?php

declare(strict_types=1);

namespace App\Data;

final class AiTimesheetPlanDay
{
    /**
     * @param AiTimesheetPlanWorkBlock[] $workBlocks
     * @param AiTimesheetPlanBreak[] $breaks
     */
    public function __construct(
        public string $date,
        public array $workBlocks,
        public array $breaks
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'work_blocks' => array_map(fn (AiTimesheetPlanWorkBlock $block) => $block->toArray(), $this->workBlocks),
            'breaks' => array_map(fn (AiTimesheetPlanBreak $block) => $block->toArray(), $this->breaks),
        ];
    }
}
