# Anti-Abuse v2 — Adaptive CAPTCHA (Register + Login)

## Goal
Add CAPTCHA as a *progressive* (adaptive) friction layer to reduce bots/spam without hurting normal users.
CAPTCHA must not replace EmailPolicy or rate limits; it complements them.

## Scope (Phase v2)
- Register flow (tenant signup):
  - POST /api/tenants/register
  - POST /api/tenants/request-signup
  - GET  /api/tenants/verify-signup (defense-in-depth)
- Login flow (traditional password login):
  - POST /api/login
- (Optional but recommended) SSO redirect/callback:
  - GET /auth/{provider}/redirect
  - GET /auth/{provider}/callback
- (Optional) Invite create (tenant-scoped, auth):
  - POST /api/technicians (when send_invite=true)

## CAPTCHA Strategy
### Mode
- `CAPTCHA_MODE=adaptive` (default)
- `CAPTCHA_MODE=always` (emergency switch for active attack)
- `CAPTCHA_MODE=off` (local dev)

### Provider
Provider-agnostic interface. Support at least one provider (ex: Cloudflare Turnstile or Google reCAPTCHA).
Config via env:
- CAPTCHA_ENABLED=true|false
- CAPTCHA_PROVIDER=turnstile|recaptcha
- CAPTCHA_SECRET=...
- CAPTCHA_SITE_KEY=... (frontend)
- CAPTCHA_MIN_SCORE=0.5 (only if using score-based providers)

## When CAPTCHA is required (Adaptive rules)
### Register (tenant signup)
Require CAPTCHA when any of these triggers happen:
1) EmailPolicy flagged domain (disposable OR "risk" domain)
2) Throttle hit or nearing limit (public-auth limiter events)
3) No browser context (missing Accept-Language or User-Agent, or custom "X-Browser" marker absent)
4) Multiple signup attempts from same IP in short window

### Login (password)
Require CAPTCHA only after repeated failures, never on first attempt:
- After N failed logins per (IP + tenant_slug + email) within 10 minutes (N default: 3)
- Or when IP is already rate limited by login limiter

## API Contract
### Requests
Frontend submits `captcha_token` when CAPTCHA is required.
- Field name: `captcha_token`
- Sent in JSON body for POST endpoints

### Responses
If CAPTCHA required and missing/invalid:
- HTTP 422
- JSON:
  {
    "message": "Please complete the security check.",
    "code": "captcha_required",
    "captcha": { "provider": "...", "site_key": "..." }
  }

If EmailPolicy blocks (disposable):
- Keep existing generic message (422) without PII
- Still log reason

## Logging / PII Rules
- Never log full email.
- Log only: email_domain, ip, user_agent, tenant (slug/id), route, reason, captcha_required=true/false, provider
- Example log event keys:
  - abuse.captcha_required
  - abuse.captcha_failed
  - email_policy.rejected

## Backend Design
### New components
- `CaptchaService` (interface)
- `TurnstileCaptchaService` or `RecaptchaCaptchaService` (implementation)
- `CaptchaGate` (decides if captcha is required based on request context + counters)
- Optional `LoginFailureTracker` using cache/redis

### Enforcement points
- TenantController register/requestSignup/verifySignup:
  - call EmailPolicy first
  - call CaptchaGate->assertCaptchaIfRequired(...)
- AuthController@login:
  - on failed password -> increment failure counter
  - if threshold reached -> require captcha on next attempt
- SsoAuthController callback (optional):
  - If EmailPolicy risk/disposable OR suspicious -> require captcha

### Testing Requirements
Feature tests:
- Register: disposable -> blocked (existing)
- Register: risk domain -> returns captcha_required (422)
- Register: missing captcha_token when required -> 422 captcha_required
- Register: valid captcha_token -> proceeds (Http::fake for provider verify)
- Login: 3 failed attempts -> next attempt triggers captcha_required
- Login: valid captcha_token + correct credentials -> success

## Frontend Design
- Default: do NOT show CAPTCHA widget.
- If API returns `code=captcha_required`, render CAPTCHA widget and retry with `captcha_token`.
- Works for Register and Login.
- Respect provider via response payload.

## Operational Notes
- Production: set CAPTCHA_ENABLED=true and secrets.
- Start with adaptive mode.
- Keep an emergency switch: CAPTCHA_MODE=always.
- If config cached, ensure site_key response still correct.