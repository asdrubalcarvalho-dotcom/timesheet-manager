# 🧪 Tenant Smoke Test Checklist

Use this quick pass after any risky tenancy/auth change. Document results in the deployment log.

1. **Tenant onboarding**
   - Hit `POST /api/register` (or UI equivalent) and ensure a new `Company`, `Tenant`, and Admin user are created.
   - Confirm onboarding email/notification (if enabled) is scoped to that tenant.
2. **Role access**
   - Login as Admin, Manager, Employee and verify dashboards show only tenant data.
   - Attempt to access another tenant via URL manipulation → expect 403/redirect.
3. **Core flows**
   - Create a project, attach employees, submit a timesheet, approve it, and export payroll.
   - Validate DB rows contain the correct `tenant_id` and no cross-tenant leakage.
4. **Cleanup**
   - Delete/suspend the tenant (sandbox only) and ensure users, caches, queues, and scheduled jobs tied to that tenant are removed or disabled.
5. **Frontend build sanity**
   - Run `npm run build` (or `vite build`) with `VITE_TENANT=<slug>` and open the preview.
   - Check both subdomain (`https://slug.localhost`) and path (`/tenant/slug`) modes render correctly.

> If any step fails, stop deployment, capture logs/artifacts, and file an issue before continuing.
