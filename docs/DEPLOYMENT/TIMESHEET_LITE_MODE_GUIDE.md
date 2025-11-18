# 🌐 Timesheet Lite Mode — Deployment & Operation Guide
This document describes how to run the **Timesheet Module** (Timesheet + Approvals + Expenses + Travels) in **Lite Mode**, without Docker, without Redis, without AI, and using a **single shared database**.

This guide is **operational documentation only**.  
GitHub Copilot must **NOT** treat this as coding instructions or generate code based on this file.

---

# 🎯 What is Timesheet Lite Mode?
**Lite Mode** is a simplified, hosting‑friendly version of the Timesheet platform designed to run on:

- Shared hosting (cPanel)
- Low-resource VPS
- Environments without Docker
- Environments without Redis or Supervisor
- MySQL 5.7 / MariaDB 10.3+ (instead of MySQL 8)

Lite Mode preserves full functionality for:

✔ Timesheets  
✔ Approvals  
✔ Expenses  
✔ Travels  
✔ Single‑DB Multi‑Tenancy (tenant_id)  
✔ Authentication  
✔ React frontend (static build)  
✔ REST API  

It disables or replaces features that require Docker or root access.

---

# 🔧 Architecture Overview (Lite Mode)

```
[Browser: React Build]
        |
        v
[Apache/Nginx (cPanel or VPS)]
        |
        v
[Laravel API — PHP 8.2/8.3]
        |
        v
[MySQL/MariaDB — single DB]
```

No Redis.  
No containers.  
No background workers (Supervisor).  
Queues run using database + CRON.

---

# 1️⃣ Server Requirements (No Docker)

### Required:
- PHP **8.2 or 8.3**
- Composer
- MySQL 5.7+ or MariaDB 10.3+
- Apache or Nginx
- Node.js 18+ (only needed to build frontend)
- Cron Support

### Required PHP Extensions:
- pdo_mysql  
- mbstring  
- tokenizer  
- xml  
- ctype  
- openssl  
- json  
- fileinfo  
- curl  
- gd (optional)  
- zip  

Check installed modules:
```
php -m
```

---

# 2️⃣ Environment Configuration

Update `.env` for Lite Mode:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=timesheet
DB_USERNAME=your_user
DB_PASSWORD=your_pass

QUEUE_CONNECTION=database
FILESYSTEM_DISK=public
```

### Lite Mode Multi‑Tenancy:
```
TENANCY_DATABASE_MODE=single
TENANCY_ALLOW_CENTRAL_FALLBACK=true
TENANT_DB_PER_TENANT=false
```

This ensures:
- NO database‑per‑tenant creation
- All tenant data stored in one DB using `tenant_id`

---

# 3️⃣ Backend Deployment

### Install dependencies:
```
composer install --no-dev --optimize-autoloader
```

### Run migrations:
```
php artisan migrate --force
php artisan db:seed
```

### Create storage link:
```
php artisan storage:link
```

If symbolic links are not supported (some shared hosts):
- upload `storage/app/public` manually
- ensure appropriate write permissions

---

# 4️⃣ Frontend Deployment (React + Vite)

### Build frontend locally:
```
npm install
npm run build
```

This generates:

```
frontend/dist/
```

Upload contents of `/dist` to:

```
public_html/
```

Or to `/public` if using VPS/Nginx.

---

# 5️⃣ Queue Processing (No Redis)

Lite Mode uses:

### Database queues:
```
QUEUE_CONNECTION=database
```

### Cron runner:
```
* * * * * php /path/to/project/artisan queue:work --stop-when-empty
```

This runs jobs once per minute.

Not realtime, but compatible with shared hosting.

---

# 6️⃣ Apache / Nginx Configuration

### Apache (.htaccess)
Ensure `/public` is the DocumentRoot.

```
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

### Nginx (VPS only)

Example server block:
```
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

---

# 7️⃣ Features Disabled in Lite Mode

Lite Mode **does not support**:

❌ Docker or Docker Compose  
❌ Redis queues  
❌ Supervisor workers  
❌ WebSockets  
❌ Local AI (Ollama)  
❌ Multi‑Database per tenant  
❌ Automatic DNS provisioning for tenant subdomains  
❌ Multi‑container architecture  

These require VPS/Docker/EXMáquina.

---

# 8️⃣ Full Feature List — Lite Mode

### ✔ Fully Supported
- Timesheet CRUD  
- Calendar UI  
- Approvals module  
- Expenses module  
- Travels module  
- Authentication  
- Multi‑Tenancy (single database)  
- Activity Logs  
- API tokens  
- React build  
- File uploads  
- Database queues  

### ⚠ Limited
- Queue performance (cron-based)  
- Large export operations (timeout tuning recommended)  
- Subdomain routing (requires manual setup if needed)

### ❌ Unsupported
- DB-per-tenant  
- Redis  
- Supervisor  
- Ollama / AI  
- Docker  
- Multi-container deployment  

---

# 🔚 Summary

**Lite Mode** allows the Timesheet platform to run in:
- Shared hosting (cPanel)
- Low-cost hosting plans
- Simple VPS environments
- Local development without Docker

It preserves all core functionality while removing features that require containerization or privileged environments.

This guide is **deployment-only** documentation.  
Copilot must NOT generate code from this file.

---

End of document.
