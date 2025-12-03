<file name=0 path=/Users/asdrubalcarvalho/Documents/IA_Machine_Learning/timesheet/docs/PHASE_8_RENEWAL_ENGINE.md># PHASE 8 – Automatic Monthly Renewals (Custom Engine + Stripe)

## 1. Goal

Implement a safe, automatic monthly renewal engine for tenants:

- Charge every tenant automatically at the end of each billing period
- Use existing Stripe integration and saved payment methods
- Respect scheduled plan changes (downgrades/upgrades effective on renewal)
- Keep all business rules and trial flow exactly as they are now

This phase **does not** change the checkout flow. It only adds a backend renewal job.

---

## 2. Constraints and Rules

- ✅ Keep all existing billing logic intact:
  - `PlanManager`
  - `PriceCalculator`
  - `BillingController`
  - `StripeGateway`
  - `FakeCreditCardGateway`
  - Existing endpoints: `/api/billing/*`
- ✅ Do not break trial logic:
  - Trial tenants must **not** be renewed automatically
  - Transition from trial → paid plan is still done via checkout
- ✅ Use the **current plan + addons + user count** as the source of truth
- ✅ Use **Stripe test mode** by default (based on current env config)
- ✅ Use a **Laravel scheduled command** (no external cron scripts)
- ❌ Do not introduce Stripe Subscriptions in this phase
- ❌ Do not change the BillingSummary response structure

---

## 3. Data Model – Subscriptions and Payments

We already have:

- `Modules/Billing/Models/Subscription`
  - Fields like: `plan`, `is_trial`, `trial_ends_at`, `user_limit`, etc.
- `Modules/Billing/Models/Payment` (or similar)
  - Fields: `amount`, `status`, `metadata`, `gateway`, etc.

Phase 8 may need the following **subscription fields** (validate what already exists before adding anything):

- `billing_period_started_at` (datetime)
- `billing_period_ends_at` (datetime)
- `last_renewal_at` (nullable datetime)
- `status` (e.g. `active`, `past_due`, `canceled`)
- Optional for scheduled plan changes (only if not already present):
  - `pending_plan` (nullable string)
  - `pending_user_limit` (nullable int)
  - `pending_plan_effective_at` (nullable datetime)

📌 **Important:**  
If these fields (or similar) already exist, **reuse them**.  
Do **not** create duplicates (e.g. `next_billing_at` vs `billing_period_ends_at`).

---

## 4. Renewal Engine – Backend Design

### 4.1 New Service: `BillingRenewalService`

Create a new service class, for example:

- `app/Services/Billing/BillingRenewalService.php`

Responsibilities:

1. **Find subscriptions that must be renewed today**
   - Only subscriptions that:
     - Are **not** in trial
     - Have `status = active`
     - Have `billing_period_ends_at <= now()`
   - Respect tenancy: run inside the correct tenant context.

2. **Calculate the renewal amount**
   - Use `PriceCalculator::calculate($tenant)` to get:
     - Current plan
     - User count
     - Add-ons
     - Base subtotal / totals
   - Use the **same logic** as manual upgrades (no duplication).

3. **Apply pending plan changes (if any)**
   - If the subscription has pending plan change fields (e.g. `pending_plan`):
     - Apply the pending plan and user limit **before** calculating price
     - Clear pending fields after the change
   - This aligns with the downgrade/upgrade scheduling rules.

4. **Charge via gateway**
   - Use the same payment gateway abstraction already in use:
     - `StripeGateway` in test/live mode
     - `FakeCreditCardGateway` for dev
   - For Stripe:
     - Use the saved **customer** and **default payment method**
     - Create an **off-session** PaymentIntent
     - Confirm immediately
   - Record a new `Payment` with metadata, e.g.:

     ```json
     {
       "operation": "renewal",
       "plan": "team",
       "user_count": 5,
       "addons": ["ai"],
       "billing_period_start": "2025-12-01",
       "billing_period_end": "2026-01-01"
     }
     ```

5. **Update subscription**
   - On successful payment:
     - Move `billing_period_started_at` to the previous `billing_period_ends_at`
     - Set new `billing_period_ends_at` to +1 month (or the right interval)
     - Update `last_renewal_at = now()`
     - Keep `status = active`
   - On failed payment:
     - Set `status = past_due`
     - Optionally store:
       - `failed_renewal_attempts`
       - `grace_period_until` (for future phase)
     - Do **not** immediately cancel the subscription.

6. **Logging**
   - Log each renewal attempt (success and failure) with:
     - tenant_id
     - subscription_id
     - amount
     - gateway response
     - new billing period dates

---

### 4.2 New Artisan Command

Create a new console command, for example:

- `php artisan billing:run-renewals`

Path:

- `app/Console/Commands/RunBillingRenewals.php`

Responsibilities:

- Call `BillingRenewalService` and:
  - Renew all tenants that are due today
  - Output a clean summary in the console log:
    - total subscriptions checked
    - renewals succeeded
    - renewals failed
- Make it **idempotent** (if it runs twice, it must not double-charge).

---

### 4.3 Scheduler Integration

In `app/Console/Kernel.php`, add something like:

```php
protected function schedule(Schedule $schedule): void
{
    // ...

    $schedule->command('billing:run-renewals')
        ->dailyAt('03:00')
        ->withoutOverlapping()
        ->onOneServer();
}
```

3 AM server time is a good default.

⸻

## 5. Frontend Impact (Optional in this Phase)

This phase can be backend only.

Optionally, we can later update the Billing UI to show:
	•	Next renewal date: 2025-12-01
	•	Last renewal: 2025-11-01
	•	Status: Active / Past due

These values should come from the BillingSummary API.

⸻

## 6. Copilot Implementation Prompt

Use this prompt in VS Code, inside the backend folder, with all billing files visible to Copilot:

You are a senior Laravel developer. Implement PHASE 8 – Automatic Monthly Renewals for our multi-tenant SaaS.

Context and rules:

- We already have:
  - Stripe integration (StripeGateway + FakeCreditCardGateway)
  - A custom billing engine (no Stripe Subscriptions)
  - PlanManager, PriceCalculator, BillingController
  - Payments table and Subscription model
  - Checkout flow with /api/billing/checkout/start and /api/billing/checkout/confirm
- We want: a safe backend renewal engine that:
  - Runs daily via Laravel Scheduler
  - Charges each active subscription at the end of its billing period
  - Respects scheduled plan changes (downgrades/upgrades effective on renewal)
  - Uses saved payment methods (Stripe customer + default card)
  - Does NOT break existing trial or checkout flows

IMPORTANT:

- Do NOT change the billing public API structure (BillingSummary, etc.).
- Do NOT break trial logic.
- Do NOT introduce Stripe Subscriptions.
- Prefer adding new methods / classes instead of changing existing logic.
- If Subscription already has fields for billing periods or pending plan changes, reuse them instead of creating duplicates.

Steps to implement:

1) Subscription model and migration
   - Open Modules/Billing/Models/Subscription.php and the subscription migrations.
   - If there are no fields for billing periods, add them via a new migration:
     - billing_period_started_at (nullable datetime)
     - billing_period_ends_at (nullable datetime)
     - last_renewal_at (nullable datetime)
     - status (string, default 'active')
   - If similar fields already exist, use them instead.
   - If there are pending plan fields (e.g. pending_plan, pending_plan_effective_at), reuse them. Only create them if they truly do not exist.

2) BillingRenewalService
   - Create app/Services/Billing/BillingRenewalService.php.
   - Responsibilities:
     - Find all active, non-trial subscriptions that are due for renewal:
       - status = 'active'
       - is_trial = false (or equivalent)
       - billing_period_ends_at <= now()
     - For each subscription:
       - Resolve the correct tenant context.
       - Apply any pending plan change that should take effect now
         (for example: pending_plan where pending_plan_effective_at <= now()).
       - Use PriceCalculator::calculate($tenant) to get current billing summary:
         - plan, user_count, addons, total, etc.
       - Use the current payment gateway (Stripe or fake) to create and confirm a renewal charge:
         - For Stripe, use off-session PaymentIntent with the saved customer and default payment method.
         - For FakeCreditCardGateway, simulate success as we do in checkout.
       - Create a Payment record with metadata:
         - operation: 'renewal'
         - plan, user_count, addons
         - billing_period_start and end
       - Update subscription dates:
         - last_renewal_at = now()
         - billing_period_started_at = previous billing_period_ends_at (if not null, otherwise now())
         - billing_period_ends_at = +1 month from new billing_period_started_at
       - If payment fails:
         - Do NOT throw an unhandled exception.
         - Set subscription->status = 'past_due'
         - Optionally increment failed_renewal_attempts field if it exists.
       - Log success and failure with tenant_id, subscription_id, amount and gateway.

3) Artisan command
   - Create app/Console/Commands/RunBillingRenewals.php.
   - Command name: billing:run-renewals
   - In handle():
     - Resolve BillingRenewalService.
     - Call something like ->runForDueSubscriptions().
     - Output counts: total checked, renewals succeeded, renewals failed.
   - Make it idempotent: guard against double charging (e.g. if billing_period_ends_at is updated after a successful renewal, the same subscription should not be picked again until next period).

4) Schedule in Kernel
   - Open app/Console/Kernel.php.
   - In schedule(), register:
     - $schedule->command('billing:run-renewals')
         ->dailyAt('03:00')
         ->withoutOverlapping()
         ->onOneServer();
   - Do not change existing scheduled tasks.

5) Safety and logging
   - Use clear log messages:
     - Starting renewal run
     - Subscription renewed successfully
     - Subscription renewal failed with reason
   - Make sure exceptions inside the renewal loop do NOT crash the whole job: catch per-subscription and continue with others.

6) Tests / manual validation support
   - Add clear comments at the top of BillingRenewalService describing:
     - How to manually trigger a renewal in local environment (php artisan billing:run-renewals).
     - That it uses Stripe test mode when STRIPE_MODE=test.
   - Do not add PHPUnit tests now, only make the code test-friendly.

Please implement all the above in small, consistent commits. Do NOT refactor unrelated parts of the code.

### Additional Clarifications (Required for Accurate Implementation)

**Monthly billing cycle rule**
- Billing periods are always exactly **1 month long**, based on the same day-of-month.
- Use `Carbon::addMonthNoOverflow()` to avoid issues on months with 28/29/30/31 days.

**Missing payment method behavior**
- If a tenant has no saved/default payment method:
  - Mark the subscription as `past_due`
  - Log a warning including tenant_id
  - Skip charging and continue the loop
  - Do NOT throw an exception

**Areas Copilot must NOT change**
- Do not modify:
  - `StripeGateway` off‑session logic
  - Existing checkout flow (`checkout/start`, `checkout/confirm`)
  - BillingSummary structure
  - Trial flow or trial transition rules

**Tenant context requirement**
- Every renewal must run inside the tenant context using:
  ```
  tenancy()->initialize($tenant);
  ```
  before any calculation or charge is executed.
