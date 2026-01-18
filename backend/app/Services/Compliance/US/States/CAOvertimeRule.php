<?php

declare(strict_types=1);

namespace App\Services\Compliance\US\States;

use App\Services\Compliance\US\Hooks\Defaults\DefaultSeventhDayApplier;
use App\Services\Compliance\US\Hooks\Defaults\DefaultSeventhDayQualifier;
use App\Services\Compliance\US\Hooks\SeventhDayApplier;
use App\Services\Compliance\US\Hooks\SeventhDayQualifier;
use App\Services\Compliance\US\FederalOvertimeRule;

final class CAOvertimeRule extends FederalOvertimeRule
{
	public function __construct(
		private readonly ?SeventhDayQualifier $seventhDayQualifier = null,
		private readonly ?SeventhDayApplier $seventhDayApplier = null,
	) {
	}

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
		// v3.2 skeleton pipeline (must preserve v3.1 behavior):
		// normalize  daily bucketing  7th-day qualify+apply  weekly adjust  finalize
		$normalized = $this->normalizeDayHoursByDate($dayHoursByDate);
		$dailySplits = $this->bucketDailyHours($normalized);

		$seventhDayKey = $this->getSeventhDayQualifier()->seventhDayKey($normalized);
		if ($seventhDayKey !== null && array_key_exists($seventhDayKey, $normalized)) {
			$dailySplits[$seventhDayKey] = $this->getSeventhDayApplier()->apply(
				$this,
				$normalized[$seventhDayKey]
			);
		}

		$totals = $this->sumSplits($normalized, $dailySplits);
		$adjusted = $this->applyWeeklyCombinationRule($totals);

		return $this->finalizeWeekTotals($adjusted);
	}

	/**
	 * @param array<string, float|int> $dayHoursByDate
	 * @return array<string, float>
	 */
	private function normalizeDayHoursByDate(array $dayHoursByDate): array
	{
		$normalized = [];
		foreach ($dayHoursByDate as $date => $hours) {
			$normalized[(string) $date] = max(0.0, (float) $hours);
		}
		ksort($normalized);

		return $normalized;
	}

	/**
	 * @param array<string, float> $normalizedDayHoursByDate
	 * @return array<string, array{regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float}>
	 */
	private function bucketDailyHours(array $normalizedDayHoursByDate): array
	{
		$splits = [];
		foreach ($normalizedDayHoursByDate as $date => $dayHours) {
			$splits[$date] = $this->splitDayHours($dayHours);
		}

		return $splits;
	}

	/**
	 * @param array<string, float> $normalizedDayHoursByDate
	 * @param array<string, array{regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float}> $splits
	 * @return array{total_hours: float, regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float}
	 */
	private function sumSplits(array $normalizedDayHoursByDate, array $splits): array
	{
		$total = 0.0;
		$regular = 0.0;
		$ot1_5 = 0.0;
		$ot2_0 = 0.0;

		foreach ($normalizedDayHoursByDate as $date => $dayHours) {
			$total += $dayHours;
			$split = $splits[$date] ?? $this->splitDayHours($dayHours);

			$regular += (float) $split['regular_hours'];
			$ot1_5 += (float) $split['overtime_hours_1_5'];
			$ot2_0 += (float) $split['overtime_hours_2_0'];
		}

		return [
			'total_hours' => $total,
			'regular_hours' => $regular,
			'overtime_hours_1_5' => $ot1_5,
			'overtime_hours_2_0' => $ot2_0,
		];
	}

	/**
	 * @param array{total_hours: float, regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float} $totals
	 * @return array{total_hours: float, regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float}
	 */
	private function applyWeeklyCombinationRule(array $totals): array
	{
		$total = (float) $totals['total_hours'];
		$regular = (float) $totals['regular_hours'];
		$ot1_5 = (float) $totals['overtime_hours_1_5'];
		$ot2_0 = (float) $totals['overtime_hours_2_0'];

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

	/**
	 * @param array{total_hours: float, regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float} $totals
	 * @return array{total_hours: float, regular_hours: float, overtime_hours_1_5: float, overtime_hours_2_0: float}
	 */
	private function finalizeWeekTotals(array $totals): array
	{
		return [
			'total_hours' => (float) $totals['total_hours'],
			'regular_hours' => (float) $totals['regular_hours'],
			'overtime_hours_1_5' => (float) $totals['overtime_hours_1_5'],
			'overtime_hours_2_0' => (float) $totals['overtime_hours_2_0'],
		];
	}

	private function getSeventhDayQualifier(): SeventhDayQualifier
	{
		return $this->seventhDayQualifier ?? new DefaultSeventhDayQualifier();
	}

	private function getSeventhDayApplier(): SeventhDayApplier
	{
		return $this->seventhDayApplier ?? new DefaultSeventhDayApplier();
	}
}
