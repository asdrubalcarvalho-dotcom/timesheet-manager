

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
- Dev validation scripts:
  - `backend/test_email_system.php`
  - `backend/validate_email_system.php`

### Expected behavior

- Email is queued in tenant DB `jobs`.
- On processing, the mail is sent using the current `MAIL_MAILER`.
- In dev with `MAIL_MAILER=log`, the message appears in `storage/logs/laravel.log`.

---

## Phase 2 — Billing / Subscription Emails (DESIGN ONLY — do not implement until approved)

Phase 2 will add automated emails for subscription lifecycle. **No code should be written for Phase 2 unless this document is explicitly marked as approved for implementation.**

### Guiding principles

1. **Do not touch** the invitation system behavior.
2. **Do not invent** new subscription logic. Reuse the existing billing/subscription state.
3. **Do not** add new endpoints for emails.
4. **Always** emit events and process via queued listeners.
5. **Never** query or send across tenants. Everything runs inside `$tenant->run(...)`.

### Email categories (candidate set)

These are the only Phase 2 categories we expect to need:

- **Renewal reminders** (upcoming renewal)
- **Payment failure / dunning** (payment failed, retry scheduled)
- **Subscription expired / downgraded**
- **Receipt / invoice notifications** (only if billing system already provides the data)

> If a new category is proposed, add it here first with acceptance criteria.

### Canonical event model (proposed)

All Phase 2 emails should be generated from a small set of **billing lifecycle events**.

Proposed events (names can change, intent cannot):

- `SubscriptionRenewalUpcoming` (T-7 days, T-3 days, T-1 day)
- `SubscriptionPaymentFailed` (attempt N)
- `SubscriptionRecovered` (payment succeeded after failure)
- `SubscriptionExpired` (access change)
- `InvoiceIssued` (optional; only if invoices exist in current system)

Each event payload must include:

- `tenant_id` (or Tenant model)
- `subscription_id` (or internal identifier)
- `plan` / `status` snapshot
- `occurred_at`
- any identifiers needed to fetch details **within the same tenant**

### Who receives Phase 2 emails (rules)

Default recipients (unless existing business rules already define otherwise):

- Tenant **Owner**
- Users with explicit **Billing/Admin** permission (if such a permission exists)

Hard rules:

- Never email technicians/users who are not opted-in for billing notifications (if opt-in exists).
- Never email all tenant users by default.

### Scheduling rules (how emails get triggered)

Phase 2 requires scheduled detection of billing conditions. Two safe options:

**Option A — Scheduled command (recommended)**
- A single scheduled command runs periodically (e.g., hourly or daily).
- It iterates tenants and runs billing checks inside tenant context.
- When it detects a condition, it emits one of the Phase 2 events.

**Option B — Webhook-driven**
- If the billing provider already triggers webhooks, webhooks write billing state.
- A tenant-context handler emits the Phase 2 events.

**Important**: avoid a “global queue worker” assumption. Processing must still be tenant-scoped.

### Idempotency (must-have)

Phase 2 emails must not spam users.

For each email type, implement an idempotency key stored in tenant DB, e.g.:

- `billing_notifications` table (tenant):
  - `tenant_id`
  - `type`
  - `key` (e.g., `subscription:{id}:renewal:T-7`)
  - `sent_at`

If we already have an equivalent mechanism, reuse it.

### Phase 2 acceptance checklist

- [ ] No changes to invitation mail flow.
- [ ] No new queue driver.
- [ ] All events/listeners run in tenant context.
- [ ] Idempotency prevents duplicates.
- [ ] All emails have a deterministic template and test coverage.
- [ ] `php artisan test` passes.

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