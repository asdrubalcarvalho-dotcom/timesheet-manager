# SSO Architecture – Google & Microsoft (Tenant‑Scoped)

## Goal

Enable **secure Single Sign‑On (SSO)** with **Google** and **Microsoft** in a **multi‑tenant** environment, without breaking existing password‑based login. This document defines the **canonical architecture** and rules. It is the single source of truth for implementation and Copilot prompts.

---

## Scope (Phase SSO‑1)

✅ Enable Google + Microsoft login
✅ Keep email/password login active
✅ Tenant‑scoped authentication
✅ Auto‑provision users safely
❌ Do NOT disable password login
❌ Do NOT enforce SSO‑only yet

---

## Key Constraints (Non‑Negotiable)

1. **Tenant‑scoped always**
   No user may authenticate outside a tenant context.

2. **Email is the identity key**
   OAuth provider ID is secondary metadata.

3. **No cross‑tenant auto‑creation**
   Users are created **only if the email matches the tenant owner domain OR an existing invited user**.

4. **Safe fallback**
   Password login remains available for all users.

---

## High‑Level Flow

### 1. Login Screen

Frontend shows:

* Email
* Tenant slug
* Password login
* "Sign in with Google"
* "Sign in with Microsoft"

When SSO is clicked:

* Email **must be filled**
* Tenant **must be filled**

---

### 2. Redirect to Backend

Frontend redirects to:

```
/auth/{provider}/redirect?tenant={tenant}
```

Where:

* `{provider}` ∈ `google | microsoft`

Backend validates:

* Tenant exists
* Tenant is active

---

### 3. OAuth Provider Authentication

Backend uses **Laravel Socialite** to redirect to:

* Google OAuth
* Microsoft Azure OAuth

State includes:

* tenant_id
* email (hashed)

---

### 4. Callback Handling

Callback endpoint:

```
/auth/{provider}/callback
```

Backend receives:

* OAuth email
* OAuth provider ID

Validation rules:

1. OAuth email **must match** the email passed at login
2. Email **must belong to tenant**:

   * Matches tenant owner email OR
   * Matches existing invited user

If validation fails → reject login

---

### 5. User Resolution

Backend logic:

```
IF user exists (tenant_id + email)
  → login user
ELSE IF email allowed for tenant
  → create user
  → mark email_verified_at
  → store oauth_provider + oauth_id
  → login user
ELSE
  → deny
```

---

### 6. Session Creation

* Standard Laravel auth session
* Same guards as password login
* No special casing downstream

---

## Data Model

### Users table (additive only)

```sql
oauth_provider VARCHAR NULL
oauth_provider_id VARCHAR NULL
```

Rules:

* Password column remains
* OAuth users may have random password

---

## Security Rules

* OAuth email is authoritative
* Tenant must be resolved **before** OAuth redirect
* Callback must re‑validate tenant
* No silent auto‑join of tenants

---

## Feature Flags (Future‑Ready)

```env
AUTH_SSO_ENABLED=true
AUTH_SSO_ONLY=false
```

* `AUTH_SSO_ENABLED=false` → hide SSO buttons
* `AUTH_SSO_ONLY=true` → hide password login (Phase SSO‑2)

---

## Failure Modes (Explicit)

| Case              | Result          |
| ----------------- | --------------- |
| Email mismatch    | Reject          |
| Tenant inactive   | Reject          |
| Email not allowed | Reject          |
| Provider error    | Safe error page |

---

## Out of Scope (Later Phases)

* Enforcing SSO‑only
* Per‑tenant SSO policies
* SCIM / auto‑deprovisioning
* Password removal

---

## Implementation Notes

* Use `laravel/socialite`
* Microsoft provider via `socialiteproviders/microsoft-graph`
* All logic lives in backend
* Frontend only redirects

---

## Definition of Done (SSO‑1)

✅ Google login works end‑to‑end
✅ Microsoft login works end‑to‑end
✅ Existing users can login
✅ New users auto‑created safely
✅ Password login unchanged

---

**This document is canonical.**
Any implementation or Copilot prompt must follow it exactly.
