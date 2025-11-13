Perfeito 👏 — aqui está o ficheiro completo e formatado em Markdown para colocares em
/docs/tenant_onboarding_tests.md

Este documento serve como manual de QA, CI e auditoria técnica do módulo de Tenant Onboarding — junta os testes de API, E2E e SQL de verificação.

⸻


# 🧪 Tenant Onboarding — QA & Validation Guide

## 📘 Purpose
This document validates the **multitenant onboarding flow** in TimePerk SaaS.  
It ensures that each new tenant:
- is created in the **central database** (`tenants`, `domains`);
- receives a dedicated **Timesheet database** (`timesheet_<slug>`);
- includes a default **Admin user** with assigned roles;
- and can authenticate immediately via the frontend.

---

## ⚙️ Environment Prerequisites

| Component | Status |
|------------|---------|
| **Backend** | Laravel 11 + `stancl/tenancy` |
| **Frontend** | React 18 + Vite + TypeScript |
| **MySQL** | 8.x running in Docker container `timesheet_mysql` |
| **App user** | `timesheet` with full privileges (`CREATE, ALTER, DROP, INDEX, EVENT, TRIGGER`) |
| **Prefix** | `TENANCY_DATABASE_PREFIX=timesheet_` |

Ensure containers are running:
```bash
docker ps
# timesheet_app, timesheet_mysql, timesheet_frontend should be "Up"


⸻

🧩 1️⃣ API Tests (Manual via curl or Postman)

🧠 1.1 Check Slug Availability

curl -X GET "http://localhost:8080/api/tenants/check-slug?slug=testcorp"

Expected:

{ "available": true }

If already taken:

{ "available": false }


⸻

🧩 1.2 Register Tenant

curl -X POST http://localhost:8080/api/tenants/register \
  -H "Content-Type: application/json" \
  -d '{
    "company_name": "Test Corporation",
    "slug": "testcorp",
    "admin_name": "John Doe",
    "admin_email": "admin@testcorp.com",
    "admin_password": "secret123",
    "admin_password_confirmation": "secret123",
    "industry": "Technology",
    "country": "PT",
    "timezone": "Europe/Lisbon"
  }'

Expected Response (201):

{
  "status": "ok",
  "tenant": "testcorp",
  "database": "timesheet_testcorp",
  "admin_token": "xxx",
  "tenant_info": {
    "company_name": "Test Corporation",
    "country": "PT",
    "timezone": "Europe/Lisbon"
  }
}


⸻

🧾 1.3 Verify Tenant Records in DB

# Central database
docker exec -it timesheet_mysql mysql -uroot -proot -e "SELECT id, data FROM central.tenants;"

# Tenant database
docker exec -it timesheet_mysql mysql -uroot -proot -e "SHOW DATABASES LIKE 'timesheet_testcorp';"
docker exec -it timesheet_mysql mysql -uroot -proot -e "USE timesheet_testcorp; SHOW TABLES;"
docker exec -it timesheet_mysql mysql -uroot -proot -e "USE timesheet_testcorp; SELECT * FROM users WHERE email='admin@testcorp.com';"

✅ Expected Results
	•	Tenant appears in central.tenants
	•	Database timesheet_testcorp exists
	•	Contains all Timesheet tables (users, resources, timesheets, expenses, etc.)
	•	Admin record exists with proper email and role

⸻

🧱 2️⃣ Automated Backend Tests

Run PHPUnit Feature Tests

docker exec -it timesheet_app bash -lc "php artisan test --filter=TenantOnboardingTest"

✅ Expected Output

PASS  Tests\Feature\TenantOnboardingTest
✓ it registers a tenant and creates their database
✓ it rejects reserved slugs
✓ check slug endpoint returns availability
OK (3 tests, 12 assertions)

If any test fails, review:
	•	DB privileges for timesheet user
	•	TENANCY_DATABASE_PREFIX and .env consistency
	•	Laravel logs in storage/logs/laravel.log

⸻

🧭 3️⃣ Frontend E2E (Cypress)

Run the Cypress suite

cd frontend
npm run test:e2e

✅ Expected Flow
	1.	Visit /register
	2.	Fill form (auto slug generation)
	3.	Slug availability checked (✓ available)
	4.	Submit → backend returns 201
	5.	Redirect to /login
	6.	Login with created credentials
	7.	Redirect to /app/{tenant}/dashboard
	8.	Dashboard visible + token stored in localStorage

If any step fails, review:
	•	API endpoint URLs in .env or vite.config.ts
	•	Slug validation debounce (500 ms)
	•	CORS settings in Laravel (config/cors.php)

⸻

🔍 4️⃣ Security & Validation Checks

Check	Expected
Rate limiting	10/min for /register, 30/min for /check-slug
Reserved slugs	admin, api, system rejected
Password confirmation	Required
Regex	^[a-z0-9-]+$
Token auth	Laravel Sanctum enabled
Cleanup on failure	Tenant DB removed on transaction rollback


⸻

🧠 5️⃣ QA Checklist Summary

Step	Validation
✅ Slug available	/api/tenants/check-slug returns available=true
✅ Tenant registered	/api/tenants/register returns 201
✅ Central record created	Present in central.tenants
✅ Tenant DB created	Found via SHOW DATABASES LIKE 'timesheet_%'
✅ Admin user created	Exists in tenant users table
✅ Login success	/login works with created credentials
✅ Isolation verified	Only tenant’s data visible
✅ Cleanup tested	/api/tenants/delete/{slug} drops DB correctly


⸻

🧾 6️⃣ Rollback / Cleanup Commands

**Option 1: Using Artisan Command (Recommended)**

```bash
# Delete tenant with confirmation prompts
php artisan tenants:delete testcorp

# Force delete without confirmation (use with caution)
php artisan tenants:delete testcorp --force
```

**Option 2: Using Shell Script**

```bash
# Make executable (first time only)
chmod +x scripts/rollback_tenant.sh

# Run with tenant slug
./scripts/rollback_tenant.sh testcorp
```

**Option 3: Manual SQL Cleanup (Emergency Only)**

```bash
# Direct database drop
docker exec -it timesheet_mysql mysql -uroot -proot -e "DROP DATABASE IF EXISTS timesheet_testcorp;"

# Clean central records
docker exec -it timesheet_mysql mysql -uroot -proot -e "USE timesheet; DELETE FROM tenants WHERE slug='testcorp';"
```

⸻

🔍 6️⃣.1 Tenant Verification Command

**Validate tenant integrity before/after operations:**

```bash
# Basic verification
php artisan tenants:verify testcorp

# Detailed output (shows table counts, roles, etc.)
php artisan tenants:verify testcorp --detailed
```

**Checks performed:**
- ✅ Tenant exists in central database
- ✅ Tenant database physically exists  
- ✅ All required tables present (users, projects, timesheets, etc.)
- ✅ Admin user exists with proper role assignment
- ✅ Domains configured correctly

**Expected output:**
```
🔍 Verifying tenant: testcorp

✅ Central Record ............................ Found
   Tenant ID .................................. 01K9T9AYV92WCPGE190NX0FF6G
   Name ....................................... Test Corporation
   Status ..................................... active
   Owner Email ................................ admin@testcorp.com
   Created .................................... 2 hours ago

✅ Database .................................. timesheet_testcorp

🗄️  Checking tenant database tables...
✅ Tables Present ............................ 12/12

👤 Checking admin user...
✅ Admin User ................................ Found
   Email ...................................... admin@testcorp.com

🌐 Checking domains...
✅ Domains ................................... 1

✅ Tenant 'testcorp' is fully operational!
```

⸻

🧰 7️⃣ Troubleshooting

Symptom	Likely Cause	Fix
❌ “Database manager for driver not registered”	DatabaseTenancyBootstrapper not active	Enable in config/tenancy.php
❌ “Access denied for user ‘timesheet’@’%’”	Missing privileges	Run GRANT CREATE, ALTER… again
❌ 404 on /register	Route not added or cached	php artisan route:clear
❌ 500 on /api/tenants/register	Seeder/role missing	php artisan migrate:fresh --seed
❌ CORS or network error	Wrong API_URL on frontend	Check VITE_API_URL in .env


⸻

✅ 8️⃣ Final Validation Snapshot (Production readiness)
	•	Tenant onboarding flow tested (API + Frontend)
	•	MySQL permissions validated
	•	Migrations and seeders synced
	•	E2E Cypress suite passing
	•	Tenant deletion/rollback tested
	•	Docs updated with date stamp

Last validated: {{ current date }}
Tested environment: macOS + Docker Compose (timesheet stack)
