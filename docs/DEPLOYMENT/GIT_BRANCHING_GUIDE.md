# Git Branching & Release Deployment Guide  
**Timesheet / VendasLive – Production Workflow**

This guide defines the official branching model, development workflow, and deployment procedure for the Timesheet platform.  
It ensures consistent, safe, and predictable deployments without overwriting production‑specific configurations.

---

## 1. Branch Structure Overview

### **main**
- The *only* branch deployed on the production server.
- Contains stable, fully‑tested code.
- Every go‑live comes from `main`.

### **feature/***
- Used for developing new features or modules.
- Example:  
  - `feature/planning-gantt`  
  - `feature/improve-login-flow`

### **hotfix/***
- Used for urgent fixes applied directly on top of production.
- Example:  
  - `hotfix/cors-error`  
  - `hotfix/fix-tenant-creation`

### **release tags**
- Each production deployment is tagged.
- Examples:  
  - `v2025.11.20`  
  - `v2025.12.01`

---

## 2. Local Development Workflow (Mac)

### **1. Ensure your main branch is up to date**
```
git checkout main
git pull origin main
```

### **2. Create a new feature branch**
```
git checkout -b feature/planning-gantt
```

### **3. Develop normally**
Commit frequently:
```
git add .
git commit -m "Implemented planning day view"
```

### **4. When feature is completed**
Merge it cleanly into main:
```
git checkout main
git pull origin main
git merge --no-ff feature/planning-gantt
git push origin main
```

---

## 3. Hotfix Workflow (Fast Fix for Production)

### **1. Create a hotfix branch from main**
```
git checkout main
git pull origin main
git checkout -b hotfix/fix-cors
```

### **2. Apply fix and commit**

### **3. Merge hotfix back into main**
```
git checkout main
git merge --no-ff hotfix/fix-cors
git push origin main
```

---

## 4. Production Deployment Workflow (Server)

### **1. SSH to the server**
```
cd /opt/timesheet-manager
```

### **2. Pull the updated main**
```
git pull --ff-only origin main
```

### **3. Rebuild containers**
```
docker compose up -d --build
```

### **4. Run database migrations**
```
docker exec -it timesheet_app php artisan migrate --force
```

### **5. (If relevant) Clear caches**
```
docker exec -it timesheet_app php artisan optimize:clear
docker exec -it timesheet_app php artisan config:cache
```

---

## 5. Creating a Release Tag (Recommended)

On your Mac:
```
git checkout main
git pull origin main
git tag -a v2025.11.20 -m "Planning Gantt release"
git push origin --tags
```

On the server (optional):
```
git checkout tags/v2025.11.20
```

This provides traceability and rollback capability.

---

## 6. Rules to Avoid Breaking Production

### **NEVER**
❌ never commit production-only configuration into Git  
❌ never develop directly on the server  
❌ never push untested code to `main`  
❌ never run migrations on feature branches in production  

### **ALWAYS**
✅ always merge features into `main` from your Mac  
✅ always test branches locally before merging  
✅ always tag releases  
✅ always backup DB before big releases  
✅ always use `--force` when migrating in production  

---

## 7. Folder Protections (Important)

Some server files must *never* be overwritten by local development:

- `docker/nginx/api/default.conf`
- `docker/nginx/app/app.conf`
- `.env` (server)
- SSL certificates in `/etc/letsencrypt/`
- Anything inside `storage/*`

Deployment rules:
- Code from `main` updates backend + frontend only.
- Server-specific configs stay local to the server.
- Git should ignore `.env` and environment configuration.

---

## 8. Typical Go‑Live Checklist

1. ✔ Confirm feature branch is merged into main  
2. ✔ Ensure there are no uncommitted changes on the server  
3. ✔ Pull latest main  
4. ✔ Rebuild Docker containers  
5. ✔ Run migrations  
6. ✔ Test login  
7. ✔ Test tenant creation  
8. ✔ Verify frontend build loads correctly  
9. ✔ Tag the release  

---

## 9. Summary

| Purpose | Branch |
|--------|--------|
| Stable production code | **main** |
| New development | **feature/*** |
| Urgent patch | **hotfix/*** |
| Release snapshot | **tags** |

---

This workflow guarantees that your production environment stays stable, isolated, and safe—while giving you fast and organised development on your Mac.
