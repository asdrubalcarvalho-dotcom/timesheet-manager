# Email Policy & Anti-Abuse Rules

## Scope
This policy applies to:
- Tenant signup
- User invitations
- SSO onboarding (future phases)

Its goal is to prevent automated abuse while keeping friction low for legitimate users.

---

## 1. Disposable Email Blocking (Hard Rule)

### Rule
Tenant creation and user invitations MUST reject disposable / temporary email domains.

### Applies to
- Tenant signup
- Admin user invitations
- SSO first-login user provisioning

### Rationale
Disposable domains are primarily used by bots, spammers, and automated scripts.
TimePerk is a B2B product and requires stable, real email addresses.

### Enforcement
- Email domain is extracted and validated
- If domain is flagged as disposable → request is rejected
- Error message must be generic and user-friendly

Example response:
> “Please use a valid business or personal email address.”

---

## 2. Rate Limiting (Soft Rule)

### Rule
Public unauthenticated endpoints MUST be rate-limited.

### Applies to
- Signup endpoint
- Invitation acceptance
- Passwordless / SSO callbacks (future)

### Baseline limits
- 5 attempts per IP per minute
- 20 attempts per IP per hour

---

## 3. CAPTCHA (Adaptive Rule)

### Rule
CAPTCHA is required when risk is detected.

### Triggers
- Disposable email detected
- Too many attempts from same IP
- Signup without browser context (no JS)

### Strategy
- Invisible CAPTCHA (preferred)
- Only shown when needed (progressive friction)

---

## 4. Observability

- Rejected attempts are logged with:
  - email domain
  - IP
  - user agent
- No PII stored beyond what is necessary

---

## Non-Goals
- No blocking by country
- No aggressive fingerprinting
- No permanent bans in Phase 1