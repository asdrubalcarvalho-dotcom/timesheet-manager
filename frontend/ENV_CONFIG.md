# Environment Configuration

## Files

### Development (Local)
- **`.env`** - Used by `npm run dev` (Vite dev server)
  - API: `http://api.localhost`
  - App: `http://localhost:8082`

### Production Build (Local)
- **`.env.production.local`** - Used by `npm run build` for local Docker testing
  - API: `http://api.localhost`
  - App: `http://localhost:8082`
  - ⚠️ **IGNORED BY GIT** - Only for local development

### Production Deployment
- **`.env.production.example`** - Template for production servers
  - Copy to `.env.production` on production server
  - Update URLs: `https://api.yourdomain.com`, `https://app.yourdomain.com`
  - ⚠️ **DO NOT COMMIT** real `.env.production` with production URLs

## Workflow

### Local Development
```bash
npm run dev          # Uses .env (port 3000)
```

### Local Docker Testing
```bash
npm run build        # Uses .env.production.local (localhost URLs)
docker cp dist/. timesheet_nginx_app:/usr/share/nginx/html/
docker restart timesheet_nginx_app
# Access: http://localhost:8082
```

### Production Deployment
```bash
# On production server:
cp .env.production.example .env.production
# Edit .env.production with real URLs
npm run build        # Uses .env.production
# Deploy dist/ to production
```

## Git Strategy

- ✅ **COMMITTED**: `.env.example`, `.env.production.example`
- ❌ **IGNORED**: `.env`, `.env.production`, `.env.production.local`

This way:
- Local devs use `.env.production.local` with localhost URLs
- Production uses `.env.production` with real URLs (not in git)
- No conflicts when pulling/pushing code
