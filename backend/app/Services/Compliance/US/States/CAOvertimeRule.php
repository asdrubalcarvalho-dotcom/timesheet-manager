<?php

declare(strict_types=1);

namespace App\Services\Compliance\US\States;

use App\Services\Compliance\US\FederalOvertimeRule;

final class CAOvertimeRule extends FederalOvertimeRule
{
	/**
	 * California daily overtime (v3):
	 * - First 8h: regular
	 * - 8–12h: overtime at 1.5x (max 4h)
	 * - 12h+: double time at 2.0x
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

	/**
	 * California 7th consecutive working day rule (within the tenant workweek):
	 * - On the 7th consecutive working day:
	 *   - First 8h: overtime at 1.5x
	 *   - Beyond 8h: double time at 2.0x
	 *
	 * @return array{regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float}
	 */
	public function splitSeventhDayHours(float $dayHours): array
	{
		$hours = max(0.0, $dayHours);

		$ot1_5 = min(8.0, $hours);
		$ot2_0 = max(0.0, $hours - 8.0);

		return [
			'regular_hours' => 0.0,
			'overtime_hours_1_5' => $ot1_5,
			'overtime_hours_2_0' => $ot2_0,
		];
	}

	/**
	 * California weekly combination rule (v3):
	 * 1) Split each day into regular/1.5/2.0 buckets (daily + 7th day logic).
	 * 2) Then apply weekly OT: if total hours > 40, convert ONLY remaining regular hours
	 *    into weekly overtime @ 1.5x until the weekly excess is covered.
	 *
	 * @param array<string, float|int> $dayHoursByDate
	 * @return array{total_hours: float, regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float}
	 */
	public function splitWeekFromDays(array $dayHoursByDate): array
	{
		// Normalize and sort by date key (Y-m-d).
		$normalized = [];
		foreach ($dayHoursByDate as $date => $hours) {
			$normalized[(string) $date] = max(0.0, (float) $hours);
		}
		ksort($normalized);

		$dates = array_keys($normalized);
		$total = 0.0;
		$regular = 0.0;
		$ot1_5 = 0.0;
		$ot2_0 = 0.0;

		$hasSevenDays = count($dates) === 7;
		$allWorkingDays = $hasSevenDays;
		if ($hasSevenDays) {
			foreach ($normalized as $hours) {
				if ($hours <= 0.0) {
					$allWorkingDays = false;
					break;
				}
			}
		} else {
			$allWorkingDays = false;
		}

		$seventhDayKey = $allWorkingDays ? (string) end($dates) : null;

		foreach ($normalized as $date => $dayHours) {
			$total += $dayHours;
			$split = ($seventhDayKey !== null && $date === $seventhDayKey)
				? $this->splitSeventhDayHours($dayHours)
				: $this->splitDayHours($dayHours);

			$regular += (float) $split['regular_hours'];
			$ot1_5 += (float) $split['overtime_hours_1_5'];
			$ot2_0 += (float) $split['overtime_hours_2_0'];
		}

		$weeklyExcess = max(0.0, $total - $this->overtimeThresholdHours());
		$convert = min($weeklyExcess, $regular);
		$regular -= $convert;
		$ot1_5 += $convert;

		return [
			'total_hours' => $total,
			'regular_hours' => $regular,
			'overtime_hours_1_5' => $ot1_5,
			'overtime_hours_2_0' => $ot2_0,
		];
	}
}
