<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\TrialRemainingLabel;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TrialRemainingLabelTest extends TestCase
{
    #[Test]
    public function it_returns_days_left_at_exactly_48_hours(): void
    {
        $now = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        $trialEndsAt = $now->addHours(48);

        $this->assertSame('2 days left', TrialRemainingLabel::getTrialRemainingLabel($trialEndsAt, $now));
    }

    #[Test]
    public function it_returns_ends_tomorrow_at_exactly_24_hours(): void
    {
        $now = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        $trialEndsAt = $now->addHours(24);

        $this->assertSame('Ends tomorrow', TrialRemainingLabel::getTrialRemainingLabel($trialEndsAt, $now));
    }

    #[Test]
    public function it_returns_ends_today_at_23h59m(): void
    {
        $now = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        $trialEndsAt = $now->addHours(23)->addMinutes(59);

        $this->assertSame('Ends today', TrialRemainingLabel::getTrialRemainingLabel($trialEndsAt, $now));
    }

    #[Test]
    public function it_returns_trial_expired_when_expired_by_one_second(): void
    {
        $now = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        $trialEndsAt = $now->subSecond();

        $this->assertSame('Trial expired', TrialRemainingLabel::getTrialRemainingLabel($trialEndsAt, $now));
    }
}
