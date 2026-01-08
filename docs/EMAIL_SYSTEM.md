

# Email System

This document is the **source of truth** for how email is implemented in this repo.

## Goals

- Provide a reliable, tenant-safe email pipeline.
- Keep all email features **event-driven** and **queue-backed**.
- Prevent accidental cross-tenant data leaks.
- Make local/dev testing deterministic and repeatable.

## Non-goals

- We are **not** redesigning the auth/onboarding “verify email” flow here.
- We are **not** adding new queue drivers (Redis/Horizon) in the current phases.

---

## Architecture Overview

### Building blocks

- **Event**: domain signal emitted by the app.
- **Listener**: reacts to the event and dispatches work.
- **Mailable**: builds the email subject + HTML content.
- **Queue**: `database` queue, **tenant-scoped**.

### Tenant-scoped queue (critical)

This project uses the **database** queue and jobs are intentionally stored in the **tenant database** (e.g. `timesheet_<tenant_id>`).

Implications:

- Running `php artisan queue:work` with **no tenant context** reads from the **central** DB and **will not consume tenant jobs**.
- Any queue processing / retry / flush for app emails must run **inside the tenant context**.

See: `docs/QUEUE_SYSTEM_QUICK_REFERENCE.md`.

---

## Phase 1 — Invitations (COMPLETED)

### When it triggers

- When an admin creates a new user/technician through the Admin UI / API, the system dispatches an invitation event.

### Components

- Event: `App\Events\UserInvited`
- Listener: `App\Listeners\SendUserInvitationEmail` (queued)
- Mailable: `App\Mail\UserInvitationMail`
- Blade template: `resources/views/emails/user-invitation.blade.php`

### Queue requirements

- Tenant DB must include queue tables.
- Tenant migration exists: `database/migrations/tenant/0001_01_01_000002_create_jobs_table.php`

### Testing/validation

- Feature test: `backend/tests/Feature/UserInvitationEmailTest.php`

### Expected behavior

- Email is queued in tenant DB `jobs`.
- On processing, the mail is sent using the current `MAIL_MAILER`.
- In dev with `MAIL_MAILER=log`, the message appears in `storage/logs/laravel.log`.

---

## Phase 3 — Billing / Subscription Lifecycle Emails (IMPLEMENTED)

This repo implements billing lifecycle emails per `docs/EMAIL_SYSTEM_PHASE3.md`.

### Components

- Events:
  - `App\Events\Billing\SubscriptionRenewalUpcoming`
  - `App\Events\Billing\SubscriptionPaymentFailed`
  - `App\Events\Billing\SubscriptionRecovered`
  - `App\Events\Billing\SubscriptionExpiredDowngraded`
- Listeners (enqueue-only):
  - `App\Listeners\Billing\SendSubscriptionRenewalReminderEmail`
  - `App\Listeners\Billing\SendSubscriptionPaymentFailedEmail`
  - `App\Listeners\Billing\SendSubscriptionRecoveredEmail`
  - `App\Listeners\Billing\SendSubscriptionExpiredDowngradedEmail`
- Mailables:
  - `App\Mail\Billing\SubscriptionRenewalReminderMail`
  - `App\Mail\Billing\SubscriptionPaymentFailedMail`
  - `App\Mail\Billing\SubscriptionRecoveredMail`
  - `App\Mail\Billing\SubscriptionExpiredDowngradedMail`

### Who receives Phase 3 emails

- Tenant owner email (the billing contact in this project).

### Idempotency (tenant DB)

To prevent duplicate sends, Phase 3 uses a tenant-scoped idempotency table:

- Migration: `database/migrations/tenant/2026_01_08_000001_create_email_idempotency_keys_table.php`
- Service: `App\Services\Email\EmailIdempotencyService`

Keys are derived from `subscription_id` and `billing_period_ends_at` (billing cycle). Examples:

- Renewal reminder (T-7 only): `subscription:{id}:renewal:period_end:{billing_period_ends_at}`
- Payment failed (attempt-gated): `subscription:{id}:payment_failed:period_end:{billing_period_ends_at}:attempt:{n}`
- Expired/downgraded (once per cycle): `subscription:{id}:expired:period_end:{billing_period_ends_at}`

### Triggers (where events are emitted)

- The periodic detection of billing conditions lives in billing services/commands.
- Listeners must only enqueue mail (no cron/scheduling inside listeners).

### Testing/validation

- Run tests in Docker (recommended):

```bash
docker-compose exec app php artisan test --filter=UserInvitationEmailTest --no-ansi
docker-compose exec app php artisan test --filter=BillingPhase3EmailsTest --no-ansi
```

### Known gotchas

- Tenant queue is **not** consumed by a “global” `queue:work` (central DB vs tenant DB).
- If you run commands/tests on the host, you may hit filesystem permission issues on `storage/logs`; Docker is the supported path.
- In dev with `MAIL_MAILER=log`, inspect `storage/logs/laravel.log` for rendered emails.

---

## Copilot guardrails (important)

When asking Copilot to implement anything in the email system:

- **Do** follow this doc and existing patterns (event → queued listener → mailable → blade).
- **Do not** add “helper endpoints” for emails.
- **Do not** add or assume a global queue worker.
- **Do not** introduce new billing logic.
- **Do not** change unrelated systems (Reports, Planning, etc.).

---

## References

- Queue quick reference: `docs/QUEUE_SYSTEM_QUICK_REFERENCE.md`
- Phase 1 completion notes: `docs/EMAIL_SYSTEM_PHASE1_COMPLETE.md`