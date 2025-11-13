# 🧭 TimePerk SaaS — Hybrid Tenant Resolution (Subdomain + Header Fallback)

## 🎯 Objective
Implement and maintain a **hybrid multi-tenant resolution model** in which:
- Production uses **subdomain-based tenancy** (e.g. `https://acme.app.timeperk.com`);
- Development and CI environments may also use **header-based tenancy** via `X-Tenant` for simplicity.

Copilot **must not modify this architecture** — only extend or test it according to these rules.

---

## 🧱 Core Principles

| Mode | Purpose | Active environments | Example |
|------|----------|--------------------|----------|
| **Subdomain-based** | Default & production-grade tenant resolution | `production`, `staging` | `https://demo.app.timeperk.com` |
| **Header-based (X-Tenant)** | Local/dev/testing fallback | `local`, `development`, `testing` | `http://localhost:8080` with header `X-Tenant: demo` |

---

## ⚙️ Configuration — Laravel backend

### `.env`
```env
APP_ENV=local
APP_URL=http://app.timeperk.localhost:8080

TENANCY_MODE=domain
TENANCY_BASE_DOMAIN=app.timeperk.localhost
TENANCY_DATABASE_PREFIX=timesheet_

config/tenancy.php
return [
    'bootstrappers' => [
        Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
    ],

    'database' => [
        'manager' => Stancl\Tenancy\Database\DatabaseManager::class,
        'template_tenant_connection' => 'mysql',
        'prefix' => env('TENANCY_DATABASE_PREFIX', 'timesheet_'),
        'suffix' => '',
    ],

    // Domains considered "central" (non-tenant)
    'central_domains' => [
        'app.timeperk.localhost',
        'localhost',
        '127.0.0.1',
        'app.timeperk.com', // production base
    ],
];

Middleware Fallback Logic

Create or confirm middleware AllowCentralDomainFallback.php:

public function handle($request, Closure $next)
{
    $host = $request->getHost();

    // ✅ Allow header-based fallback only for local/dev/testing
    if (app()->environment(['local', 'development', 'testing'])
        && in_array($host, ['localhost', '127.0.0.1', 'app.timeperk.localhost'])) {

        if ($slug = $request->header('X-Tenant') ?? $request->query('tenant')) {
            $tenant = \App\Models\Tenant::find($slug);
            if ($tenant) {
                tenancy()->initialize($tenant);
            }
        }
    }

    // ❌ In production, never honor X-Tenant headers
    return $next($request);
}

Local DNS Setup (macOS / Linux)

Edit /etc/hosts:

127.0.0.1  app.timeperk.localhost
127.0.0.1  demo.app.timeperk.localhost
127.0.0.1  testcorp.app.timeperk.localhost
127.0.0.1  acme.app.timeperk.localhost

This allows both:
	•	http://demo.app.timeperk.localhost:8080 → subdomain mode
	•	http://localhost:8080 with X-Tenant: demo → header mode

⸻

Testing Locally (Both Modes)

🔹 A. Header mode (simplest)

curl -X POST http://localhost:8080/api/login \
  -H "X-Tenant: demo" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@demo.test","password":"secret"}'

  Expected: 200 OK with token JSON.

🔹 B. Subdomain mode

In browser:
http://demo.app.timeperk.localhost:8080

Backend automatically resolves tenant demo by domain.

🔹 C. Frontend
	•	npm run dev
	•	Visit http://demo.app.timeperk.localhost:3000
	•	Login as demo admin
	•	Verify data isolation per tenant.

⸻

🚀 Production Rules
Rule
Description
Tenant resolution
Must rely only on subdomain hostnames
Header fallback
Disabled (X-Tenant ignored)
DNS
Wildcard DNS for *.app.timeperk.com → same host/load balancer
TLS
Use wildcard certificate *.app.timeperk.com
Security
Any request with X-Tenant in production → HTTP 403
Logging
Log tenant slug from domain (request()->getHost()) not header


Copilot / Codex Behaviour Rules
	1.	Never remove or replace the hybrid mode.
	2.	Do not auto-switch to path-based tenancy.
	3.	When generating backend routes or frontend fetch calls:
	•	Use X-Tenant only when window.location.host equals localhost or 127.0.0.1.
	•	Otherwise, rely on the current subdomain.
	4.	When writing tests:
	•	Include both a subdomain test and a header test.
	•	In CI (Docker), keep using header-based mode.
	5.	When deploying or building:
	•	Ensure .env values reflect the environment (TENANCY_BASE_DOMAIN and APP_ENV).

    Local-Dev Workflow Summary
    Step
Command / Action
Expected
Start backend
docker compose up -d
Laravel API at :8080
Start frontend
cd frontend && npm run dev
React at :3000
Create demo tenant
php artisan tenants:create demo
DB timesheet_demo created
Migrate
php artisan tenants:migrate --tenant=demo
Tables seeded
Header test
curl with X-Tenant: demo
200 OK
Subdomain test
open http://demo.app.timeperk.localhost:3000
UI loads tenant data

Recap
	•	The hybrid model stays permanent.
	•	Use subdomains for production.
	•	Keep X-Tenant header fallback active only for local/dev/testing.
	•	Copilot must not alter this policy or introduce new tenancy modes.