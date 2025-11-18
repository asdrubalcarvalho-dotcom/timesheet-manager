# 🧩 Deployment Compatibility Matrix — Laravel + React Timesheet Module on cPanel

This document summarizes what parts of the **Timesheet module** are compatible with **shared hosting (cPanel)**, what requires adaptations, and what is not supported.

---

## ✅ Overview
The Timesheet module uses:
- Laravel 11 (PHP 8.3)
- React (Vite + TypeScript)
- MySQL database
- Authentication (Laravel Sanctum)
- CRUD for timesheet entries
- Calendar UI + Approvals UI (React)

cPanel shared hosting has significant limitations (no Docker, no Redis, limited MySQL versions), so this matrix focuses solely on the **Timesheet functionality**.

---

## 🟦 Legend
✔️ Works as-is
⚠️ Works with modifications
❌ Not supported in cPanel shared hosting

---

# 1. Backend Compatibility (Timesheet Only)

| Feature | cPanel Support | Notes |
|--------|----------------|-------|
| Laravel 11 (PHP 8.2/8.3) | ✔️ | Supported via PHP Selector. |
| Timesheet CRUD | ✔️ | Standard Laravel controllers work fine. |
| Approvals (Timesheets) | ✔️ | Fully compatible. |
| Sanctum API authentication | ✔️ | Works with SPA frontends. |
| MySQL (shared DB) | ✔️ | Timesheet tables work fine on MySQL 5.7/MariaDB. |
| Queue workers | ⚠️ | Only via cron jobs (not realtime). |
| File uploads (if used) | ✔️ | Stored inside /storage/. |
| Docker services | ❌ | Not available in shared hosting. |

---

# 2. Frontend Compatibility (Timesheet Only)

| Feature | cPanel Support | Notes |
|--------|----------------|-------|
| React build (Vite) | ✔️ | Build locally, upload /dist to hosting. |
| Calendar UI | ✔️ | Fully functional as static assets. |
| Timesheet forms | ✔️ | Uses standard REST API calls. |
| Approvals dashboard | ✔️ | Works normally with API endpoints. |
| React dev server | ❌ | Cannot run on cPanel. Build must be pre-compiled. |

---

# 3. Database Requirements

The Timesheet module requires basic relational storage:

| Requirement | cPanel Support | Notes |
|-------------|----------------|-------|
| MySQL 5.7+ / MariaDB | ✔️ | Fully sufficient for Timesheets. |
| JSON fields | ⚠️ | Supported in MariaDB with limitations; rarely needed. |
| Window functions | ✔️ | Timesheet module does not depend on them. |

---

# 4. What Works WELL on cPanel (Timesheet Only)

✔️ Timesheet entry creation/editing/deletion  
✔️ Daily/weekly/monthly calendar views  
✔️ Timesheet approval workflow  
✔️ Authentication + API tokens  
✔️ All standard REST API endpoints  
✔️ Basic file uploads (receipts, attachments)  
✔️ React static build + Laravel served API

---

# 5. What Requires Adaptation (Timesheet Only)

⚠️ **Queues**: Approval notifications and batch operations must use `cron` instead of Supervisor.  
⚠️ **Storage symlink**: Must be created manually in cPanel or use direct storage path.  
⚠️ **Large exports**: Might require timeout adjustments in `.htaccess`.

---

# 6. Unsupported Features (Irrelevant or Optional for Timesheet)

❌ Docker-based deployment  
❌ Redis cache  
❌ Realtime queue workers (Supervisor)  
❌ WebSockets  
❌ AI integrations (Ollama or ML suggestions)

---

# 7. Recommended Folder Structure on cPanel

```
/public_html
    /dist (React static build)
    /index.php
/laravel_app
    /app
    /routes
    /vendor
    /storage
    .env
```

Use `.htaccess` to route all requests to `/public/index.php`.

---

# 8. Summary — Timesheet on cPanel

The **Timesheet module works very well on cPanel**, as it only requires:
- PHP 8.2/8.3
- A single MySQL database
- Static React build
- Standard Laravel controllers/views

```
Timesheet Full Functionality: ✔️ Supported on cPanel
Docker / Redis / Advanced Features: ❌ Not available
```

---

End of document.
