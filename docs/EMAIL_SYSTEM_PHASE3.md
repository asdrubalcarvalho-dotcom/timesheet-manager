# Email System – Phase 3 (Billing Lifecycle)

## Scope
Phase 3 covers all email communications related to the billing lifecycle, including renewal reminders, payment failure notifications, subscription recovery alerts, and subscription expiration or downgrade notices. This phase explicitly excludes emails related to user invitations, onboarding processes, and invoice delivery.

## Email Types

### 1. Upcoming Renewal Reminder
- Trigger: Sent 7 days before the subscription’s billing period end date.
- Recipient: Tenant owner email address.
- Conditions: Subscription must be active and set to renew; billing_period_ends_at is within the next 7 days; no prior renewal reminder sent for the current billing cycle.
- Idempotency rule: Only one renewal reminder email per subscription per billing cycle, identified by billing_period_ends_at.

### 2. Payment Failed
- Trigger: Sent immediately after a payment failure event is recorded in the system.
- Recipient: Tenant owner email address.
- Conditions: Subscription is active but payment status is failed; payment failure timestamp is within the current billing cycle; no payment failure email sent for this failure event.
- Retry logic: Emails are sent on first failure and retried up to 3 times at 24-hour intervals if payment remains failed.
- Grace period rules: Payment failure emails are only sent within the configured grace period after the failed payment date.

### 3. Subscription Recovered
- Trigger: Sent immediately after a successful payment following a failed payment event.
- Recipient: Tenant owner email address.
- Conditions: Subscription status changed from past_due or unpaid to active; a prior payment failure email was sent in the current billing cycle.
- When it must NOT send: If no prior payment failure email was sent for the current billing cycle.

### 4. Subscription Expired / Downgraded
- Trigger: Sent immediately after the subscription status changes to expired or is downgraded to a lower plan.
- Recipient: Tenant owner email address.
- Conditions: Subscription status is expired or downgraded; billing_period_ends_at is reached or passed; no prior expiration or downgrade email sent for this billing cycle.
- One-time guarantee: Only one expiration or downgrade email per subscription per billing cycle.

## Global Rules
- Tenant-scoped only: All emails are scoped to individual tenants, ensuring no cross-tenant data leakage or notifications.
- One email per event per billing cycle: Each type of email is sent only once per relevant event within a billing cycle, using billing_period_ends_at or equivalent timestamps to enforce idempotency.
- No cron inside listeners: Event listeners do not execute scheduled jobs directly; they only enqueue tasks to maintain system responsiveness and scalability.
- No DB writes inside Mailables: Mailables are strictly for email content rendering and sending, with no database side effects to avoid transactional inconsistencies.

## Non-goals
- No invoices: Invoice generation and delivery are outside the scope of Phase 3.
- No PDFs: No PDF attachments or generation will be handled in this phase.
- No cron jobs: Scheduled tasks are managed outside of the email event listeners and mailables.
- No admin notifications: Internal administrative notifications or alerts are not included in this phase.