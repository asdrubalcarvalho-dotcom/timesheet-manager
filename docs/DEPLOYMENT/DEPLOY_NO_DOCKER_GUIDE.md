

# 🚀 DEPLOYMENT GUIDE — Running the Timesheet Project WITHOUT Docker
This document explains how to deploy and run the **Timesheet (Lite Mode)** application **without Docker**, either locally or on shared hosting (such as cPanel). 

This guide contains **operational instructions only**. It MUST NOT be interpreted by GitHub Copilot as code generation tasks.

---

# ⚠️ IMPORTANT NOTICE
The full version of the Timesheet platform uses Docker to provide:
- PHP 8.3
- MySQL 8
- Redis
- Node 20
- Nginx
- Background workers
- AI local inference
- Multi-container orchestration

These are **not available** in non-Docker or shared-hosting environments.

This guide enables deployment of the **Lite Mode** version only:
- Laravel API (PHP 8.2/8.3)
- React static build
- Single database (tenant_id for multi-tenancy)
- No Redis
- No AI local
- No DB-per-tenant
- No worker supervisor

---

# 1️⃣ PREPARE SERVER REQUIREMENTS
You need:
- PHP **8.2 or 8.3**
- Composer
- MySQL 5.7+ or MariaDB 10.3+ (single database)
- Node.js 18+ (for building frontend)
- Apache or Nginx

### Required PHP Extensions
Make sure these extensions are enabled:
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

Check using:
```
php -m
```

---

# 2️⃣ CLONE THE PROJECT (NO DOCKER)
Clone repository normally:
```
git clone https://github.com/your-repo/timesheet-manager.git
cd timesheet-manager/backend
```

Remove any Docker-specific `.env` references.

---

# 3️⃣ CONFIGURE `.env`
Use local or shared-host settings:
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

### Multi-Tenancy (Lite Mode)
```
TENANCY_DATABASE_MODE=single
TENANCY_ALLOW_CENTRAL_FALLBACK=true
```

Disable DB-per-tenant features.

---

# 4️⃣ INSTALL DEPENDENCIES
Backend:
```
composer install --no-dev --optimize-autoloader
```

Frontend:
```
cd ../frontend
npm install
npm run build
```
This generates `/dist` output.

---

# 5️⃣ DATABASE SETUP
### Create database manually
```
CREATE DATABASE timesheet;
```

### Run migrations
```
php artisan migrate --force
php artisan db:seed
```
---

# 6️⃣ FILESYSTEM SETUP
Create storage symlink (if supported):
```
php artisan storage:link
```
If symlinks are blocked (shared hosting):
- Upload storage/app/public files manually
- Update filesystem paths if needed

---

# 7️⃣ QUEUES WITHOUT DOCKER
Docker mode uses Redis + Supervisor.  
Non-Docker mode must use **database queues + cron**.

### Set queue driver:
```
QUEUE_CONNECTION=database
```

### Configure cron:
```
* * * * * php /path/to/artisan queue:work --stop-when-empty
```
(This runs once per minute)

---

# 8️⃣ FRONTEND DEPLOYMENT
The frontend must be built locally using Vite:
```
npm run build
```
Upload the contents of:
```
frontend/dist
```
into:
```
/public_html or /public
```
Depending on your server.

---

# 9️⃣ APACHE / NGINX CONFIG
### Apache (.htaccess)
Ensure the `/public` folder is the DocumentRoot.  
If not possible (cPanel), put Laravel in a subfolder and point to `/public_html`.

.htaccess contents:
```
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

### Nginx example
(Not for cPanel)
```
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

---

# 🔟 FEATURES DISABLED WITHOUT DOCKER
The following features DO NOT work outside Docker:
- AI local inference (Ollama)
- Redis queues
- Supervisor workers
- MySQL 8-only features (depending on host)
- DB-per-tenant mode
- Automatic subdomain provisioning
- Centralized Docker networking

---

# 1️⃣1️⃣ LITE MODE SUMMARY
The Timesheet platform runs correctly **without Docker** when deployed in Lite Mode:

### Fully Supported
✔ Timesheets  
✔ Approvals  
✔ Expenses  
✔ Travels  
✔ Multi-tenancy (single DB)  
✔ React frontend  
✔ Authentication  
✔ REST API

### Not Supported
❌ AI local  
❌ Redis  
❌ DB-per-tenant  
❌ Supervisor  
❌ Docker container features

---

# 🎯 FINAL NOTE
This document is **operational guidance only**.  
GitHub Copilot must NOT use this file to generate or modify application code.

---
End of document.