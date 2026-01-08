<?php

namespace Tests\Feature\Billing;

use App\Events\Billing\SubscriptionExpiredDowngraded;
use App\Events\Billing\SubscriptionPaymentFailed;
use App\Events\Billing\SubscriptionRecovered;
use App\Events\Billing\SubscriptionRenewalReminderDue;
use App\Mail\Billing\SubscriptionExpiredDowngradedMail;
use App\Mail\Billing\SubscriptionPaymentFailedMail;
use App\Mail\Billing\SubscriptionRecoveredMail;
use App\Mail\Billing\SubscriptionRenewalReminderMail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Models\Subscription;
use Tests\TenantTestCase;

class BillingPhase3EmailsTest extends TenantTestCase
{
    private Subscription $subscription;

    protected function setUp(): void
    {
        $_ENV['QUEUE_CONNECTION'] = 'sync';
        $_SERVER['QUEUE_CONNECTION'] = 'sync';
        putenv('QUEUE_CONNECTION=sync');

        parent::setUp();

        Config::set('queue.default', 'sync');

        $this->subscription = Subscription::updateOrCreate(
            ['tenant_id' => $this->tenant->id],
            [
                'plan' => 'team',
                'user_limit' => 3,
                'addons' => [],
                'status' => 'active',
                'is_trial' => false,
                'billing_period_started_at' => now()->subMonth(),
                'billing_period_ends_at' => now()->addDays(7),
                'failed_renewal_attempts' => 0,
            ]
        );
    }

    public function test_renewal_reminder_t_minus_7_queues_job_in_tenant_db_and_mail_renders(): void
    {
        Mail::fake();

        $this->subscription->update([
            'status' => 'active',
            'is_trial' => false,
            'billing_period_ends_at' => now()->addDays(7),
        ]);
        $this->subscription->refresh();

        $periodKey = $this->subscription->billing_period_ends_at?->toDateString() ?? 'unknown';
        $idempotencyKey = "subscription:{$this->subscription->id}:renewal:period_end:{$periodKey}";

        event(new SubscriptionRenewalReminderDue($this->tenant, $this->subscription, 7));

        Mail::assertQueued(SubscriptionRenewalReminderMail::class, 1);
        $this->assertSame(
            1,
            DB::connection('tenant')->table('email_idempotency_keys')->where('key', $idempotencyKey)->count()
        );

        // Duplicate event in same billing cycle must not enqueue again.
        event(new SubscriptionRenewalReminderDue($this->tenant, $this->subscription, 7));

        Mail::assertQueued(SubscriptionRenewalReminderMail::class, 1);
        $this->assertSame(
            1,
            DB::connection('tenant')->table('email_idempotency_keys')->where('key', $idempotencyKey)->count()
        );

        $html = (new SubscriptionRenewalReminderMail($this->tenant, $this->subscription, 7))->render();
        $this->assertNotEmpty($html);
    }

    public function test_payment_failed_queues_job_in_tenant_db_and_mail_renders(): void
    {
        Mail::fake();

        $this->subscription->update([
            'status' => 'past_due',
            'billing_period_ends_at' => now()->subDay(),
            'grace_period_until' => now()->addDays(7),
            'failed_renewal_attempts' => 1,
        ]);
        $this->subscription->refresh();

        $periodKey = $this->subscription->billing_period_ends_at?->toDateString() ?? 'unknown';
        $attempt = 1;
        $idempotencyKey = "subscription:{$this->subscription->id}:payment_failed:period_end:{$periodKey}:attempt:{$attempt}";

        event(new SubscriptionPaymentFailed($this->tenant, $this->subscription, 105.50, 'Card declined'));

        Mail::assertQueued(SubscriptionPaymentFailedMail::class, 1);
        $this->assertSame(
            1,
            DB::connection('tenant')->table('email_idempotency_keys')->where('key', $idempotencyKey)->count()
        );

        // Duplicate event for same attempt must not enqueue again.
        event(new SubscriptionPaymentFailed($this->tenant, $this->subscription, 105.50, 'Card declined'));

        Mail::assertQueued(SubscriptionPaymentFailedMail::class, 1);
        $this->assertSame(
            1,
            DB::connection('tenant')->table('email_idempotency_keys')->where('key', $idempotencyKey)->count()
        );

        $html = (new SubscriptionPaymentFailedMail($this->tenant, $this->subscription, 105.50, 'Card declined'))->render();
        $this->assertNotEmpty($html);
    }

    public function test_payment_failed_attempt_2_queues_second_email(): void
    {
        Mail::fake();

        $this->subscription->update([
            'status' => 'past_due',
            'billing_period_ends_at' => now()->subDay(),
            'grace_period_until' => now()->addDays(7),
            'failed_renewal_attempts' => 1,
        ]);
        $this->subscription->refresh();

        event(new SubscriptionPaymentFailed($this->tenant, $this->subscription, 105.50, 'Card declined'));

        $this->subscription->update(['failed_renewal_attempts' => 2]);
        $this->subscription->refresh();

        event(new SubscriptionPaymentFailed($this->tenant, $this->subscription, 105.50, 'Card declined'));

        Mail::assertQueued(SubscriptionPaymentFailedMail::class, 2);
    }

    public function test_payment_failed_does_not_send_after_grace_period(): void
    {
        Mail::fake();

        $this->subscription->update([
            'status' => 'past_due',
            'billing_period_ends_at' => now()->subDay(),
            'grace_period_until' => now()->subDay(),
            'failed_renewal_attempts' => 1,
        ]);
        $this->subscription->refresh();

        event(new SubscriptionPaymentFailed($this->tenant, $this->subscription, 105.50, 'Card declined'));

        Mail::assertNotQueued(SubscriptionPaymentFailedMail::class);
    }

    public function test_subscription_recovered_requires_prior_failure_in_same_cycle_and_mail_renders(): void
    {
        Mail::fake();

        $this->subscription->update([
            'status' => 'active',
            'billing_period_ends_at' => now()->addDays(7),
            'failed_renewal_attempts' => 1,
        ]);
        $this->subscription->refresh();

        // No prior failure email in this cycle => must not send.
        event(new SubscriptionRecovered($this->tenant, $this->subscription, 105.50));

        Mail::assertNotQueued(SubscriptionRecoveredMail::class);

        // Seed evidence of a payment failed email in the same cycle.
        $periodKey = $this->subscription->billing_period_ends_at?->toDateString() ?? 'unknown';
        $failureKey = "subscription:{$this->subscription->id}:payment_failed:period_end:{$periodKey}:attempt:1";
        DB::connection('tenant')->table('email_idempotency_keys')->insert([
            'key' => $failureKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        event(new SubscriptionRecovered($this->tenant, $this->subscription, 105.50));

        Mail::assertQueued(SubscriptionRecoveredMail::class, 1);

        $html = (new SubscriptionRecoveredMail($this->tenant, $this->subscription, 105.50))->render();
        $this->assertNotEmpty($html);
    }

    public function test_subscription_expired_downgraded_queues_once_per_cycle_and_mail_renders(): void
    {
        Mail::fake();

        $this->subscription->update([
            'status' => 'canceled',
            'billing_period_ends_at' => now()->subDay(),
        ]);
        $this->subscription->refresh();

        $periodKey = $this->subscription->billing_period_ends_at?->toDateString() ?? 'unknown';
        $idempotencyKey = "subscription:{$this->subscription->id}:expired:period_end:{$periodKey}";

        event(new SubscriptionExpiredDowngraded($this->tenant, $this->subscription));

        Mail::assertQueued(SubscriptionExpiredDowngradedMail::class, 1);
        $this->assertSame(
            1,
            DB::connection('tenant')->table('email_idempotency_keys')->where('key', $idempotencyKey)->count()
        );

        // Duplicate event in same billing cycle must not enqueue again.
        event(new SubscriptionExpiredDowngraded($this->tenant, $this->subscription));

        Mail::assertQueued(SubscriptionExpiredDowngradedMail::class, 1);
        $this->assertSame(
            1,
            DB::connection('tenant')->table('email_idempotency_keys')->where('key', $idempotencyKey)->count()
        );

        $html = (new SubscriptionExpiredDowngradedMail($this->tenant, $this->subscription))->render();
        $this->assertNotEmpty($html);
    }
}
