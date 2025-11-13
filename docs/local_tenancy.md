Great — here’s a ready-to-paste doc for your repo:

⸻

docs/local_tenancy.md

TimePerk — Local Tenancy Fallback & Validation

This document explains how local/dev requests can resolve tenants without subdomains using a central-domain fallback (e.g., localhost), via X-Tenant header or ?tenant= query. The fallback is enabled only in non-production environments and is controlled by env/config.

⸻

1) What the fallback does
	•	If a request hits the central host (e.g., http://localhost:8080) and domain-based resolution fails, the middleware tries:
	1.	X-Tenant: <slug> header
	2.	?tenant=<slug> query param
	•	If a matching tenant is found, tenancy is initialized and the request proceeds.
	•	In production, this fallback is off by default.

⸻

2) Configuration

.env (example for local/dev):

TENANCY_ALLOW_CENTRAL_FALLBACK=true
TENANCY_FALLBACK_ENVIRONMENTS=local,development,testing

config/tenancy.php (`domains.central_fallback` array):

/*
|--------------------------------------------------------------------------
| Central-domain fallback (local/dev)
|--------------------------------------------------------------------------
| Allows header/query-based tenant resolution (X-Tenant / ?tenant=).
| Active only in environments listed in TENANCY_FALLBACK_ENVIRONMENTS.
*/
'central_fallback' => [
    'enabled' => (bool) env('TENANCY_ALLOW_CENTRAL_FALLBACK', env('APP_ENV') !== 'production'),
    'environments' => array_map(
        'trim',
        explode(',', env('TENANCY_FALLBACK_ENVIRONMENTS', 'local,development,testing'))
    ),
],

Production hardening:

TENANCY_ALLOW_CENTRAL_FALLBACK=false
TENANCY_FALLBACK_ENVIRONMENTS=production



⸻

3) Middleware & Wiring (overview)
	•	App\Support\Tenancy\CentralDomainFallback — resolver for header/query tenant hints.
	•	App\Http\Middleware\AllowCentralDomainFallback — replaces PreventAccessFromCentralDomains in non-prod; lets requests through so fallback logic can run.
	•	App\Http\Middleware\InitializeTenancyByDomainWithFallback — wraps domain resolution and, if it fails on central host, tries header/query.

Provider & bootstrap wiring lives in:
	•	app/Providers/TenancyServiceProvider.php
	•	bootstrap/app.php
	•	app/Support/Tenancy/CentralDomainFallback.php
	•	app/Http/Middleware/AllowCentralDomainFallback.php
	•	app/Http/Middleware/InitializeTenancyByDomainWithFallback.php

No code duplication; only environment-aware behavior.

⸻

4) Usage (curl & Postman)

cURL (header-based)

curl -X POST http://localhost:8080/api/login \
  -H 'Content-Type: application/json' \
  -H 'X-Tenant: demo' \
  -d '{"email":"admin@demo.test","password":"secret"}'

cURL (query-based)

curl -X POST 'http://localhost:8080/api/login?tenant=demo' \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@demo.test","password":"secret"}'

Postman
	•	Set X-Tenant: demo header or add ?tenant=demo to the URL.
	•	Keep the same JSON body as above.

⸻

5) Demo tenant & sample data

Create/refresh a demo tenant inside the app container:

docker compose exec app php artisan tenancy:bootstrap-demo

List tenants (example commands you might have):

php artisan tenants:list
php artisan tenants:show demo

Run backend tests (includes the login fallback regression):

docker compose exec app php artisan test

⸻

6) Validation checklist (local/dev)
	1.	Env: TENANCY_ALLOW_CENTRAL_FALLBACK=true, TENANCY_FALLBACK_ENVIRONMENTS includes your env.
	2.	Login works with X-Tenant or ?tenant= (see curl above).
	3.	Regression tests pass (e.g., tests/Feature/Auth/LoginTest.php).
	4.	Prod safety: Fallback is disabled in production; requests to central domain without tenant should fail.

⸻

7) Security notes
	•	Fallback exists only to improve developer experience locally.
	•	Keep it disabled in production (CI can assert this).
	•	Do not rely on header/query fallback for real users; in production use subdomain routing (or path-based tenancy with strict guards).

⸻

8) Troubleshooting

“Login returns 404/403 on localhost”
	•	Verify env flags (Section 2).
	•	Ensure the middleware is registered in the correct order (domain → fallback).
	•	Confirm the tenant slug exists (tenants:list).

“X-Tenant ignored”
	•	Check header spelling and case (X-Tenant).
	•	Confirm you are in an allowed environment.
	•	Inspect logs for domain resolution errors.

“Works locally but fails in prod”
	•	This is expected if fallback is disabled on prod. Use subdomains or your production tenant resolver.

⸻

9) Suggested CI assertions
	•	Feature test: header-based login works in local (tests/Feature/Auth/LoginTest.php).
	•	Feature test: central fallback blocked in production (force app env in the test and expect 403/404).
	•	Env guard: ensure TENANCY_ALLOW_CENTRAL_FALLBACK=false in production config.

⸻

10) Quick reference (Artisan)

# Tenants
php artisan tenants:create
php artisan tenants:list
php artisan tenants:delete demo

# Migrations
php artisan tenants:migrate
php artisan migrate  # central

# Tests
php artisan test


⸻

Owner: Platform / Backend
Last updated: 2025-11-11
Scope: Local/dev DX only; production remains domain/subdomain-based tenancy.

⸻
