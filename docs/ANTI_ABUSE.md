# Anti-Abuse v1 (EMAIL_POLICY)

This repo implements a minimal **Anti-Abuse v1** layer focused on email-based onboarding and SSO flows.

## Scope (v1)

Enforced only on:
- Tenant onboarding (central):
  - `POST /api/tenants/register`
  - `POST /api/tenants/request-signup`
  - `GET /api/tenants/verify-signup`
- SSO (public unauth):
  - `GET /auth/{provider}/redirect`
  - `GET /auth/{provider}/callback`
- Tenant invite create:
  - `POST /api/technicians` (when creating/reactivating a technician)

## Behavior

### Email policy rejection

If the email domain is considered disposable (or the email is invalid), the request is rejected with:
- HTTP `422`
- JSON body:
  - `message`: `Please use a valid business or personal email address.`

### Rate limiting (public)

Public onboarding and SSO routes use the dedicated limiter:
- `throttle:public-auth`
- Baseline: **5/min + 20/hour per IP**

Configured in `backend/app/Providers/RouteServiceProvider.php`.

### CAPTCHA (hook-only)

v1 contains an `AbuseGate` hook that only logs when a CAPTCHA would be suggested.
It does **not** block requests.

## Logging (no PII)

Email-policy rejections emit a single structured warning log:
- Message: `email_policy.rejected`
- Context (no full email):
  - `email_domain`, `ip`, `user_agent`, `tenant_slug` (when known), `provider` (SSO), `reason`, `endpoint`, `request_id` (if provided)

## Implementation

- Email policy service: `backend/app/Services/Security/EmailPolicyService.php`
- Disposable domains list: `backend/config/disposable_email_domains.php`
  - Note: if config is cached, the service falls back to loading this file directly.
- CAPTCHA hook: `backend/app/Services/Security/AbuseGate.php`

## Tests

Feature tests live in:
- `backend/tests/Feature/AntiAbuse/TenantSignupAntiAbuseTest.php`
- `backend/tests/Feature/AntiAbuse/TechnicianInviteAntiAbuseTest.php`
- Updated SSO assertions in `backend/tests/Feature/Auth/SsoAuthTest.php`

Run in Docker:

```bash
docker-compose exec -T app php artisan test --no-ansi \
  tests/Feature/AntiAbuse/TenantSignupAntiAbuseTest.php \
  tests/Feature/AntiAbuse/TechnicianInviteAntiAbuseTest.php \
  tests/Feature/Auth/SsoAuthTest.php
```
