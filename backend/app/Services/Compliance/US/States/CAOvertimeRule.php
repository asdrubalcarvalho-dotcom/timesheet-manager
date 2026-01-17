<?php

declare(strict_types=1);

namespace App\Services\Compliance\US\States;

use App\Services\Compliance\US\FederalOvertimeRule;

final class CAOvertimeRule extends FederalOvertimeRule
{
	/**
	 * California daily overtime (v2):
	 * - First 8h: regular
	 * - >8h up to 12h: overtime at 1.5x
	 * - >12h: overtime at 2.0x
	 *
	 * Note: 7th consecutive day rules are intentionally NOT implemented.
	 *
	 * @return array{regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float}
	 */
	public function splitDayHours(float $dayHours): array
	{
		$hours = max(0.0, $dayHours);

		$regular = min(8.0, $hours);
		$overtime1_5 = min(4.0, max(0.0, $hours - 8.0));
		$overtime2_0 = max(0.0, $hours - 12.0);

		return [
			'regular_hours' => $regular,
			'overtime_hours_1_5' => $overtime1_5,
			'overtime_hours_2_0' => $overtime2_0,
		];
	}
}
