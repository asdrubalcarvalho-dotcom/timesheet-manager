# 🚀 FULL DOCKER DEPLOY GUIDE — Timesheet SaaS  
### Complete Production-Ready Deployment Guide (VPS + Docker + GitHub CI/CD)

This document explains **exactly how to deploy the Timesheet SaaS** in **full-docker mode**, inside a **VPS**, using **GitHub Actions** for automated deployment.

---

# 1️⃣ Architecture Overview

Your production infrastructure will run:

- **Nginx (reverse proxy)**  
- **Laravel API (PHP-FPM container)**  
- **React Frontend (served by Nginx)**  
- **MySQL 8 (tenant-aware)**  
- **Redis (queues, cache)**  
- **Ollama (optional)**  
- **Caddy or Traefik (optional, for SSL automation)**

Everything is launched through:

```
docker-compose -f docker-compose.prod.yml up -d
```

---

# 2️⃣ VPS Requirements

| Resource | Recommended |
|---------|-------------|
| RAM | 2 GB minimum |
| CPU | 2 vCPU |
| Disk | 30–50 GB |
| OS | Ubuntu 22.04 LTS |
| Public IPv4 | Required |
| SSH Access | Required |

---

# 3️⃣ Install Base System on VPS

## 3.1 Update system
```
sudo apt update && sudo apt upgrade -y
```

## 3.2 Install required tools
```
sudo apt install -y git curl unzip ufw
```

---

# 4️⃣ Install Docker & Docker Compose

```
curl -fsSL https://get.docker.com | sudo bash
sudo usermod -aG docker $USER
```

Logout and login again.

Install docker compose plugin:

```
sudo apt install docker-compose-plugin -y
docker compose version
```

---

# 5️⃣ Create Deploy User (optional but recommended)

```
sudo adduser deploy
sudo usermod -aG docker deploy
```

Allow deploy user to restart services:

```
sudo visudo
```

Add:

```
deploy ALL=(ALL) NOPASSWD: /usr/bin/systemctl
```

---

# 6️⃣ Prepare Directory Structure

```
mkdir -p /var/www/timesheet
cd /var/www/timesheet
```

Clone your repo:

```
git clone https://github.com/USERNAME/timesheet-manager.git .
```

---

# 7️⃣ Copy Production ENV Files

Backend:

```
cp backend/.env.example backend/.env
```

Frontend:

```
cp frontend/.env.example frontend/.env
```

Configure ENV for:

- MySQL
- Redis
- Storage
- Central hostname
- Tenant mode

---

# 8️⃣ Build Production Docker Images

```
docker compose -f docker-compose.prod.yml build
```

---

# 9️⃣ Run the Stack

```
docker compose -f docker-compose.prod.yml up -d
```

Check containers:

```
docker ps
```

---

# 🔟 Run Migrations + Seeders

```
docker exec -it timesheet_app php artisan migrate --force
docker exec -it timesheet_app php artisan tenants:migrate
docker exec -it timesheet_app php artisan db:seed --class=CompleteTenantSeeder
```

---

# 1️⃣1️⃣ Configure SSL (Optional but recommended)

## With **Caddy** (automatic SSL):

Create:
```
/etc/caddy/Caddyfile
```

Content:
```
yourdomain.com {
    reverse_proxy localhost:8080
}
```

Start:
```
sudo systemctl restart caddy
```

---

# 1️⃣2️⃣ Configure Firewall (UFW)

```
sudo ufw allow OpenSSH
sudo ufw allow http
sudo ufw allow https
sudo ufw enable
```

---

# 1️⃣3️⃣ Create GitHub CI/CD Workflow

Create:

```
.github/workflows/deploy.yml
```

Content:

```
name: Deploy to VPS

on:
  push:
    branches: [ main ]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v3

      - name: Deploy via SSH
        uses: appleboy/ssh-action@master
        with:
          host: ${{ secrets.VPS_HOST }}
          username: ${{ secrets.VPS_USER }}
          key: ${{ secrets.VPS_SSH_KEY }}
          script: |
            cd /var/www/timesheet
            git pull
            docker compose -f docker-compose.prod.yml build
            docker compose -f docker-compose.prod.yml up -d --remove-orphans
```

Add secrets in GitHub:

| Secret Name | Description |
|-------------|-------------|
| `VPS_HOST` | VPS IP |
| `VPS_USER` | deploy user |
| `VPS_SSH_KEY` | Private key |

---

# 1️⃣4️⃣ Verify Deployment

Backend:
```
http://YOUR_IP:8080/api/health
```

Frontend:
```
http://YOUR_IP:3000
```

If using Caddy:
```
https://yourdomain.com
```

---

# 1️⃣5️⃣ Backups (Recommended)

Database dump automation:

```
docker exec timesheet_mysql mysqldump -u root -pPASSWORD --all-databases > /root/db_backup.sql
```

Use cron to automate daily backups.

---

# 🎉 Deployment Completed!

Your Timesheet SaaS is now running on:

- Dockerized production stack  
- Auto-deployed through GitHub  
- Scalable  
- Tenant-ready  
- SSL-ready  

---

If you want, I can also generate:

✅ `CADDY_WITH_SSL.md`  
✅ `DEPLOY_WITHOUT_DOMAIN.md`  
✅ `DEPLOY_WITH_DOMAIN.md`  
