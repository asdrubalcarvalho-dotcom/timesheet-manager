# 🚀 Git Deployment Workflow

## Branch Strategy (Git Flow)

```
main (produção / estável) ──────────────────────────────────────────>
  ↕
feature/* (novas funcionalidades, 1–3 dias, merge em main via PR)
  ↕
hotfix/* (correções urgentes diretamente sobre main)
```

---

## 📋 Workflow Completo

### 1️⃣ **Desenvolvimento Local**

```bash
# Criar feature branch
git checkout -b feature/nome-descritivo

# Desenvolver, testar localmente
npm run build  # Frontend (usa .env.production.local)
docker-compose up --build

# Commit frequente
git add .
git commit -m "feat: descrição da mudança"

# Push para backup/colaboração
git push origin feature/nome-descritivo
```

### 2️⃣ **Integração (Pull Request)**

```bash
# Abrir PR de feature/* → main
# - Code review
# - CI/CD checks
# - Testes automáticos

# Após aprovação, merge via GitHub
# Opção: Squash and merge (limpa commits)

# Apagar branch local
git branch -d feature/nome-descritivo
git push origin --delete feature/nome-descritivo
```

### 3️⃣ **Deployment para Servidor**

#### **No Servidor de Produção:**

```bash
# SSH para servidor
ssh user@your-production-server.com

# Ir para diretório do projeto
cd /path/to/timesheet-manager

# 1. Backup atual (SEMPRE!)
tar -czf backup-$(date +%Y%m%d-%H%M%S).tar.gz \
  backend/storage backend/.env frontend/.env.production

# 2. Pull main (stable)
git fetch origin
git checkout main
git pull origin main

# 3. Verificar se .env.production existe (não vem do git)
if [ ! -f frontend/.env.production ]; then
  echo "ERRO: frontend/.env.production não existe!"
  echo "Copiar de .env.production.example e editar URLs"
  exit 1
fi

# 4. Rebuild backend
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
cd ..

# 5. Rebuild frontend
cd frontend
npm install --production
npm run build  # Usa .env.production (URLs reais)
cd ..

# 6. Deploy para Nginx
cp -r frontend/dist/* /var/www/html/timesheet/

# 7. Restart services
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx

# 8. Health check
curl https://api.yourdomain.com/api/health
```

---

## 🔒 **Proteção de Arquivos de Produção**

### **Arquivos IGNORADOS pelo Git** (não sobrescritos no pull)

```
backend/.env              # Prod database credentials
backend/.env.production   # Prod Laravel config
frontend/.env.production  # Prod API URLs
docker-compose.prod.yml   # Prod compose config
backend/storage/*         # Uploads, logs, cache
```

### **Configuração Inicial no Servidor** (uma vez)

```bash
# 1. Criar .env backend
cp backend/.env.example backend/.env
nano backend/.env
# Editar:
# - DB_HOST, DB_PASSWORD
# - APP_URL=https://yourdomain.com
# - SANCTUM_STATEFUL_DOMAINS=yourdomain.com

# 2. Criar .env.production frontend
cp frontend/.env.production.example frontend/.env.production
nano frontend/.env.production
# Editar:
# VITE_API_URL=https://api.yourdomain.com
# VITE_APP_URL=https://app.yourdomain.com

# 3. Criar docker-compose.prod.yml (se usar Docker)
cp docker-compose.yml docker-compose.prod.yml
# Editar portas, volumes, environment

# 4. Gerar APP_KEY
cd backend
php artisan key:generate
```

---

## 🔄 **Sincronização Main ↔ Server**

### **Caso 1: Main mais recente (normal)**
```bash
# No servidor
git pull origin main
# Build + deploy (ver passo 3️⃣)
```

### **Caso 2: Hotfix direto no servidor (emergência)**
```bash
# No servidor
git checkout -b hotfix/fix-urgent-bug
# Fazer fix
git commit -m "hotfix: descrição"
git push origin hotfix/fix-urgent-bug

# No local
git fetch origin
git checkout main
git merge hotfix/fix-urgent-bug
git push origin main
git branch -d hotfix/fix-urgent-bug
git push origin --delete hotfix/fix-urgent-bug
```

### **Caso 3: Server tem branch antiga (Timesheet-task-travels)**
```bash
# Server está em branch obsoleta
git branch -a  # Ver branch atual

# Opção A: Merge main (se branch tem commits únicos)
git checkout Timesheet-task-travels
git merge main
git push origin Timesheet-task-travels

# Opção B: Switch para main (recomendado)
git checkout main
git pull origin main
# Build + deploy
```

---

## 🎯 **Decisões de Hoje (18 Nov 2025)**

1. ✅ **Merged `server-sync` → `main`**
   - Deployment docs
   - Docker fixes
   - Middleware registration
   - FAB button fixes
   - .env.production.example

2. ✅ **Apagado `Timesheet-task-travels`**
   - Já estava merged (mesmo commit que main)

3. ✅ **Proteção de .env.production**
   - Adicionado ao .gitignore
   - Template .env.production.example criado

4. 🔜 **Próximos Passos**
   - Apagar `test` e `Tenant+Planning` (se não tiverem código útil)
   - Apagar `export-files-reports` (se pausado permanentemente)
   - Manter apenas `main` + `server-sync` (ou apagar server-sync)

---

## ✅ **Checklist de Deployment**

**Antes de fazer pull no servidor:**
- [ ] Backup: `tar -czf backup.tar.gz backend/storage backend/.env`
- [ ] Verificar branch: `git branch` (deve ser `main`)
- [ ] Verificar .env.production existe

**Após pull:**
- [ ] `composer install --no-dev`
- [ ] `php artisan migrate --force`
- [ ] `php artisan config:cache`
- [ ] `npm run build` (frontend)
- [ ] Deploy dist/ para Nginx
- [ ] Restart PHP-FPM + Nginx
- [ ] Health check: `curl /api/health`

**Se algo der errado:**
- [ ] Rollback: `tar -xzf backup.tar.gz`
- [ ] `git reset --hard HEAD~1`
- [ ] Rebuild + restart

---

## 📞 **Comandos Úteis**

```bash
# Ver branches remotas
git branch -a

# Ver últimos commits
git log --oneline -10

# Ver diferenças entre branches
git diff main..server-sync --stat

# Verificar qual branch está no servidor
ssh user@server "cd /path/to/project && git branch"

# Ver tags de versão
git tag -l

# Criar tag de release
git tag -a v1.0.0 -m "Release 1.0.0"
git push origin v1.0.0
```

---

## 🧾 Summary CHANGELOG

### 2025-11-19 / 2025-11-20 — First Production Release (v1.0.0)

- Docker Compose configured for production backend (Laravel), frontend (Vite/React), MySQL and Redis.
- Nginx configured to serve:
  - `vendaslive.com` → React frontend (TimePerk Cortex).
  - `api.vendaslive.com` → Laravel API (`/api/*`).
- Let's Encrypt certificates (`*.vendaslive.com`) integrated into Nginx containers.
- Unified CORS:
  - Laravel (`config/cors.php`) now accepts `https://*.vendaslive.com`.
  - Nginx handles `OPTIONS` preflight requests and reflects the allowed `Origin`.
- Production-ready multitenancy:
  - Endpoints `api/tenants/check-slug`, `api/tenants/register`, and `api/tenants/ping` fully operational.
  - Automatic creation of tenant databases `timesheet_<TENANT_ID>` with applied tenant migrations.
- Full tenant onboarding and login flow:
  - Registration creates the tenant, admin user, and dedicated tenant database.
  - Login via `https://vendaslive.com` using the `X-Tenant` header and token persisted in `localStorage`.
