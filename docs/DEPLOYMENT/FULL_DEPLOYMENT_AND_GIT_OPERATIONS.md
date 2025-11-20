

# Full Deployment, Git Operations, and Release Workflow Guide

This document defines the unified development, branching, deployment, and go‑live workflow for the Timesheet / VendasLive platform.  
It also includes architecture notes, rules for production safety, and instructions so GitHub Copilot and ChatGPT can follow consistent patterns.

---

## 1. Branching Strategy (Git)

### 1.1 Main Branches
- **main** → Production branch.  
  Only receives code through Pull Requests, after test validation.
- **develop** (optional) → Integration branch for upcoming releases.
- **feature/*** → One branch per new feature or module.
  - Example: `feature/planning-gantt`
  - Example: `feature/multi-tenancy-register`
- **hotfix/*** → Urgent production fixes.
- **release/*** → Optional staging branch for high‑impact releases.

### 1.2 Rules
- Never push directly to `main`.
- All new work must be done inside feature branches.
- Features are merged into `develop` or directly into `main` only after review.
- Server must never auto‑replace production configs (.env, nginx, certs).

---

## 2. Git Workflow (Local Mac)

### 2.1 Creating a New Feature Branch
```
git fetch origin
git checkout main
git pull
git checkout -b feature/planning-gantt
```

### 2.2 Committing Changes
```
git add .
git commit -m "Add Gantt planning module scaffolding"
```

### 2.3 Sync Branch (if others also work on it)
```
git pull origin main
git push --set-upstream origin feature/planning-gantt
```

### 2.4 Finish Feature
- Push all changes
- Create Pull Request into **main**
- Merge after review and automated tests

---

## 3. Deployment Workflow (Production Server)

Production folder:
```
/opt/timesheet-manager
```

Containers:
- `timesheet_app`
- `timesheet_nginx_api`
- `timesheet_nginx_app`
- `timesheet_mysql`
- `timesheet_redis`

### 3.1 Safe Update Procedure (Go‑live)
On the server:

```
docker compose pull
docker compose down
docker compose up -d
docker exec -it timesheet_app php artisan migrate --force
```

### 3.2 Important: Never Replace Production Files
These files must **never** be overwritten during deployment:
- `/opt/timesheet-manager/backend/.env`
- `/opt/timesheet-manager/docker/nginx/api/default.conf`
- `/opt/timesheet-manager/docker/nginx/app/app.conf`
- SSL certificates under `/etc/letsencrypt/*`

### 3.3 What Gets Updated During Deploy
- Backend source code
- Frontend build
- Docker images
- Vendor dependencies (composer install)
- Node build artifacts (npm run build inside Docker)

---

## 4. Local Development Environment (Mac)

### 4.1 Start Local Environment
```
docker compose up -d
```

### 4.2 Install Dependencies (Backend)
```
docker exec -it timesheet_app composer install
```

### 4.3 Install Dependencies (Frontend)
```
cd frontend
npm install
npm run dev
```

---

## 5. Deployment Automation Rules for Copilot & AI Tools

### 5.1 Before generating backend code:
- Always check Laravel version in `composer.json`.
- Always verify if code touches multi‑tenancy flows (Stancl Tenancy).
- If migrations are generated:
  - Must be placed in `database/migrations/tenant` for tenant-level tables.
  - Must be placed in `database/migrations` for central tables.

### 5.2 Before generating frontend code:
- Always read `.env.production` values:
  - `VITE_API_URL`
  - `VITE_APP_URL`
- Never hardcode localhost values for production.

### 5.3 When suggesting new files:
- Include version compatibility notes.
- Ensure multi‑tenant support via header `X-Tenant`.

---

## 6. Go-Live Checklist (Final Validation)

### 6.1 Before Deploy
- [ ] All code merged into `main`
- [ ] Version tag created (optional)
- [ ] Docker images build successfully
- [ ] Migrations validated manually

### 6.2 After Deploy
- [ ] `php artisan migrate --force` executed
- [ ] API reachable on `https://api.vendaslive.com/api/health`
- [ ] Frontend is loading without CORS errors
- [ ] New tenant registration works
- [ ] Tenant DB created automatically
- [ ] Login works on subdomain `<tenant>.vendaslive.com`

---

## 7. Reverse Flow (Server → Local)

Sometimes production has changes not yet in Git (should be rare).  
To sync back:

### 7.1 Export Database (Tenant or Central)
```
docker exec timesheet_mysql mysqldump -u root -proot timesheet > central.sql
```

Or tenant:
```
docker exec timesheet_mysql mysqldump -u root -proot timesheet_<ID> > tenant.sql
```

### 7.2 Sync Backend Files
```
rsync -avz root@server:/opt/timesheet-manager/backend ./backend/
```

### 7.3 Sync Frontend Files
```
rsync -avz root@server:/opt/timesheet-manager/frontend ./frontend/
```

---

## 8. Planning Module (Gantt) – Branch Instructions

### Branch Name:
`feature/planning-gantt`

### AI Guidance Rules:
- Verify Laravel version before generating controllers.
- Check React version before suggesting components.
- Ensure backend endpoints follow `/api/projects/...`.
- If storing dependencies (tasks, resources), tenant DB must contain tables.
- Include migration validation:
  - If Laravel version < 11, adjust migration syntax.
  - If React > 18, use functional components and hooks.

---

## 9. Changelog Template (for Every Release)

Add this section at the end of each PR:

```
## Changelog
### Added
- New Gantt planning module

### Changed
- Updated tenancy DB creation permissions
- Improved nginx CORS handling

### Fixed
- Login redirect for tenant subdomains
- Route mismatch for /api/tenants endpoints

### Removed
- Deprecated local-only config
```

---

## 10. Final Notes

- This document must always remain inside the repository.
- All developers and AI assistants must follow this workflow.
- If breaking changes are introduced, update this file immediately.
