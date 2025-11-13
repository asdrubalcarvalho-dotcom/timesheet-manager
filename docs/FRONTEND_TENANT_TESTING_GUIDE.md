# 🧪 Frontend Tenant Registration - Testing Guide

**Data**: 11 de Novembro de 2025  
**Status**: Ready for Testing  
**URL**: http://localhost:3000/register

---

## 🎯 Objetivo do Teste

Validar o fluxo completo de registro de tenant via interface React:
1. ✅ Formulário carrega corretamente
2. ✅ Validação em tempo real funciona
3. ✅ Auto-slug generation a partir do nome da empresa
4. ✅ Verificação de disponibilidade de slug (API call)
5. ✅ Validação de senhas correspondentes
6. ✅ Submissão cria tenant + banco + admin user
7. ✅ Redirecionamento para login após sucesso
8. ✅ Login funciona com credenciais criadas

---

## 📋 Pré-requisitos

### Verificar Containers Rodando
```bash
docker ps --format "table {{.Names}}\t{{.Status}}"
```

**Esperado**:
```
timesheet_frontend      Up X hours
timesheet_app           Up X hours
timesheet_nginx         Up X hours
timesheet_mysql         Up X hours
timesheet_redis         Up X hours
```

### Verificar Frontend Acessível
```bash
curl -I http://localhost:3000
```

**Esperado**: `HTTP/1.1 200 OK`

### Verificar Backend API
```bash
curl -I http://localhost:8080/api/health
```

---

## 🧪 Teste 1: Carregar Página de Registro

### Passos:
1. Abrir navegador em: **http://localhost:3000/register**
2. Verificar elementos da página

### Checklist Visual:
- [ ] Título "Create Your Workspace" aparece
- [ ] Card MUI com formulário centralizado
- [ ] 7 campos de input visíveis:
  - [ ] Company Name (obrigatório)
  - [ ] Workspace Slug (obrigatório)
  - [ ] Industry (opcional)
  - [ ] Country (opcional)
  - [ ] Admin Name (obrigatório)
  - [ ] Admin Email (obrigatório)
  - [ ] Password (obrigatório)
  - [ ] Confirm Password (obrigatório)
- [ ] Botão "Create Workspace"
- [ ] Link "Already have a workspace? Sign in" no rodapé

### Browser DevTools:
```javascript
// Open Console (F12) and check for errors
console.log('No errors should appear here')
```

---

## 🧪 Teste 2: Auto-Slug Generation

### Passos:
1. No campo **Company Name**, digitar: `Test Corporation Inc`
2. Observar campo **Workspace Slug**

### Comportamento Esperado:
- ✅ Slug é gerado automaticamente: `test-corporation-inc`
- ✅ Caracteres especiais removidos
- ✅ Espaços convertidos para hífens
- ✅ Tudo em minúsculas

### Casos de Teste:

| Company Name | Slug Esperado |
|--------------|---------------|
| Test Corporation | test-corporation |
| Acme & Co. | acme-co |
| Company (2025) | company-2025 |
| Múltiplos   Espaços | multiplos-espacos |
| special@#chars! | specialchars |

---

## 🧪 Teste 3: Validação de Slug Reservado

### Passos:
1. No campo **Workspace Slug**, digitar: `admin`
2. Aguardar 500ms (debounce)
3. Observar mensagem de erro

### Slugs Reservados para Testar:
```
admin, api, system, app, www, mail, ftp, localhost, central
```

### Comportamento Esperado:
- ❌ Mensagem de erro aparece abaixo do campo
- ❌ Texto: "This slug is reserved and cannot be used"
- ❌ Campo fica vermelho (error state)
- ❌ Botão "Create Workspace" desabilitado

### DevTools Network Tab:
```
GET /api/tenants/check-slug?slug=admin
Response: { "available": false, "message": "This slug is reserved..." }
Status: 200 OK
```

---

## 🧪 Teste 4: Verificação de Slug Disponível

### Passos:
1. No campo **Workspace Slug**, digitar: `qatest-frontend-001`
2. Aguardar 500ms (debounce)
3. Observar ícone de verificação

### Comportamento Esperado:
- ✅ Ícone de checkmark verde aparece
- ✅ Mensagem "Available" ou similar
- ✅ Campo fica verde (success state)
- ✅ Botão "Create Workspace" habilitado

### DevTools Network Tab:
```
GET /api/tenants/check-slug?slug=qatest-frontend-001
Response: { "available": true }
Status: 200 OK
```

---

## 🧪 Teste 5: Validação de Senhas

### Cenário A: Senhas Não Correspondem

#### Passos:
1. **Password**: `secret123`
2. **Confirm Password**: `secret456`
3. Observar mensagem de erro

#### Esperado:
- ❌ Mensagem: "Passwords do not match"
- ❌ Campo vermelho
- ❌ Botão desabilitado

### Cenário B: Senhas Correspondem

#### Passos:
1. **Password**: `secret123`
2. **Confirm Password**: `secret123`

#### Esperado:
- ✅ Sem mensagem de erro
- ✅ Campos normais
- ✅ Botão habilitado (se outros campos válidos)

---

## 🧪 Teste 6: Submissão do Formulário (CRÍTICO)

### Dados de Teste:
```json
{
  "company_name": "Frontend Test Corp",
  "slug": "qatest-frontend-001",
  "industry": "Technology",
  "country": "Portugal",
  "admin_name": "Frontend Admin",
  "admin_email": "admin@qatest-frontend-001.test",
  "admin_password": "secret123",
  "admin_password_confirmation": "secret123"
}
```

### Passos:
1. Preencher todos os campos conforme dados acima
2. Verificar que slug está disponível (ícone verde)
3. Clicar em **"Create Workspace"**
4. Observar loading state
5. Aguardar resposta (8-15 segundos)

### Comportamento Esperado Durante Submit:
- ⏳ Botão muda texto para "Creating..." ou spinner
- ⏳ Todos inputs desabilitados
- ⏳ Loading indicator visível

### DevTools Network Tab:
```
POST /api/tenants/register
Request Payload: { company_name, slug, industry, country, admin_name, admin_email, admin_password, admin_password_confirmation }
Response (201): {
  "status": "ok",
  "message": "Tenant created successfully",
  "tenant": "qatest-frontend-001",
  "database": "timesheet_01K9XXXXXXXXXXXXXX",
  "tenant_info": {
    "id": "01K9XXXXXXXXXXXXXX",
    "slug": "qatest-frontend-001",
    "name": "Frontend Test Corp",
    "domain": "qatest-frontend-001.localhost:3000",
    "status": "active",
    "trial_ends_at": "2025-11-25T..."
  },
  "admin": {
    "email": "admin@qatest-frontend-001.test",
    "token": "1|xxxxxxxxxx"
  },
  "next_steps": {
    "login_url": "http://qatest-frontend-001.localhost:3000/login",
    "api_header": "X-Tenant: qatest-frontend-001"
  }
}
```

### Após Sucesso:
- ✅ Snackbar/Alert de sucesso aparece
- ✅ Mensagem: "Tenant created successfully" ou similar
- ✅ **Redirecionamento automático para `/login` em 2-3 segundos**
- ✅ URL muda para: `http://localhost:3000/login`

---

## 🧪 Teste 7: Verificação no Banco de Dados

### Verificar Tenant Criado (Central DB):
```bash
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "
  USE timesheet;
  SELECT id, slug, name, status, owner_email, trial_ends_at 
  FROM tenants 
  WHERE slug = 'qatest-frontend-001';
"
```

**Esperado**:
```
+----------------------------+-----------------------+--------------------+--------+--------------------------------+-------------------------+
| id                         | slug                  | name               | status | owner_email                    | trial_ends_at           |
+----------------------------+-----------------------+--------------------+--------+--------------------------------+-------------------------+
| 01K9XXXXXXXXXXXXXX         | qatest-frontend-001   | Frontend Test Corp | active | admin@qatest-frontend-001.test | 2025-11-25 XX:XX:XX     |
+----------------------------+-----------------------+--------------------+--------+--------------------------------+-------------------------+
```

### Verificar Domínio Criado:
```bash
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "
  USE timesheet;
  SELECT id, domain, tenant_id 
  FROM domains 
  WHERE domain LIKE '%qatest-frontend-001%';
"
```

**Esperado**:
```
+----+----------------------------------------------+----------------------------+
| id | domain                                       | tenant_id                  |
+----+----------------------------------------------+----------------------------+
| XX | qatest-frontend-001.app.timeperk.localhost   | 01K9XXXXXXXXXXXXXX         |
+----+----------------------------------------------+----------------------------+
```

### Verificar Banco Tenant Criado:
```bash
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "
  SHOW DATABASES LIKE 'timesheet_01K9%';
"
```

**Esperado**:
```
+--------------------------------------+
| Database (timesheet_01K9%)           |
+--------------------------------------+
| timesheet_01K9XXXXXXXXXXXXXX         |
+--------------------------------------+
```

### Verificar Admin User Criado (Tenant DB):
```bash
# Substituir 01K9XXXXXXXXXXXXXX pelo ID real retornado no JSON
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "
  USE timesheet_01K9XXXXXXXXXXXXXX;
  SELECT id, name, email, role, created_at 
  FROM users;
"
```

**Esperado**:
```
+----+----------------+--------------------------------+-------+---------------------+
| id | name           | email                          | role  | created_at          |
+----+----------------+--------------------------------+-------+---------------------+
|  1 | Frontend Admin | admin@qatest-frontend-001.test | Admin | 2025-11-11 XX:XX:XX |
+----+----------------+--------------------------------+-------+---------------------+
```

### Verificar Roles Criadas:
```bash
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "
  USE timesheet_01K9XXXXXXXXXXXXXX;
  SELECT id, name, guard_name 
  FROM roles;
"
```

**Esperado**:
```
+----+------------+------------+
| id | name       | guard_name |
+----+------------+------------+
|  1 | Admin      | web        |
|  2 | Manager    | web        |
|  3 | Technician | web        |
+----+------------+------------+
```

### Verificar Role Atribuída ao Admin:
```bash
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "
  USE timesheet_01K9XXXXXXXXXXXXXX;
  SELECT mhr.model_id, mhr.role_id, r.name 
  FROM model_has_roles mhr
  JOIN roles r ON mhr.role_id = r.id
  WHERE mhr.model_type = 'App\\\\Models\\\\User';
"
```

**Esperado**:
```
+----------+---------+-------+
| model_id | role_id | name  |
+----------+---------+-------+
|        1 |       1 | Admin |
+----------+---------+-------+
```

---

## 🧪 Teste 8: Login com Credenciais Criadas

### Passos:
1. Após redirecionamento, estar em: `http://localhost:3000/login`
2. Preencher formulário de login:
   - **Tenant**: `qatest-frontend-001`
   - **Email**: `admin@qatest-frontend-001.test`
   - **Password**: `secret123`
3. Clicar em **"Sign In"**

### Comportamento Esperado:
- ⏳ Loading state no botão
- ⏳ Request para `POST /api/login` com headers `X-Tenant: qatest-frontend-001`

### DevTools Network Tab:
```
POST /api/login
Headers:
  X-Tenant: qatest-frontend-001
  Content-Type: application/json
Request:
{
  "email": "admin@qatest-frontend-001.test",
  "password": "secret123"
}
Response (200):
{
  "user": {
    "id": 1,
    "name": "Frontend Admin",
    "email": "admin@qatest-frontend-001.test",
    "role": "Admin",
    "permissions": [...],
    "managed_projects": [],
    "project_memberships": []
  },
  "token": "2|yyyyyyyyyyyy"
}
```

### Após Login Bem-Sucedido:
- ✅ Redirecionamento para `/dashboard` ou `/timesheets`
- ✅ Sidebar com menu completo aparece
- ✅ Nome do usuário no header: "Frontend Admin"
- ✅ Ícone de Admin visível
- ✅ Token salvo em `localStorage`:
  ```javascript
  localStorage.getItem('auth_token')  // "2|yyyyyyyyyyyy"
  localStorage.getItem('tenant_slug') // "qatest-frontend-001"
  ```

---

## 🧪 Teste 9: Validação de Permissões Admin

### Verificar Menu Completo:
- [ ] **Timesheets** (Calendar icon)
- [ ] **Expenses** (Receipt icon)
- [ ] **Management** (expandido):
  - [ ] Team
  - [ ] Projects
  - [ ] Tasks
- [ ] **Administration** (expandido):
  - [ ] Admin Dashboard
  - [ ] Users
  - [ ] Roles & Permissions
  - [ ] Settings

### Navegar para Admin Dashboard:
1. Clicar em **Administration → Admin Dashboard**
2. Verificar URL: `http://localhost:3000/admin`

#### Esperado:
- ✅ Página carrega sem erros
- ✅ Cards de estatísticas aparecem
- ✅ Gráficos renderizam (se houver dados)

---

## 🐛 Troubleshooting

### Problema: Formulário não carrega
**Solução**:
```bash
# Verificar logs do frontend
docker logs timesheet_frontend --tail 50

# Verificar se build foi feito
docker exec -it timesheet_frontend ls /app/dist
```

### Problema: Slug availability não funciona
**Verificar endpoint**:
```bash
curl http://localhost:8080/api/tenants/check-slug?slug=testslug
```

**Esperado**: JSON com `{ "available": true/false }`

### Problema: Submit retorna 500 error
**Verificar logs do backend**:
```bash
docker logs timesheet_app --tail 100 | grep ERROR
```

**Verificar se migrations rodaram**:
```bash
docker exec -it timesheet_app php artisan migrate:status --path=database/migrations/tenant
```

### Problema: Redirecionamento não funciona após sucesso
**Verificar no DevTools Console**:
```javascript
// Deve mostrar navigate('/login') sendo chamado
console.log('Check for navigation calls')
```

**Verificar componente TenantRegistration.tsx**:
```typescript
// Linha ~XXX - deve ter:
navigate('/login');
```

### Problema: Login falha com 401
**Verificar tenant context**:
```bash
# Verificar se domínio foi criado
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "
  SELECT * FROM timesheet.domains WHERE domain LIKE '%qatest%';
"
```

**Verificar header X-Tenant**:
- DevTools → Network → Headers → Request Headers
- Deve ter: `X-Tenant: qatest-frontend-001`

---

## ✅ Checklist Final de Validação

### Funcionalidades Testadas:
- [ ] Página de registro carrega sem erros
- [ ] Auto-slug generation funciona
- [ ] Validação de slugs reservados funciona
- [ ] API check-slug retorna respostas corretas
- [ ] Validação de senhas correspondentes funciona
- [ ] Formulário só submete quando válido
- [ ] Loading states aparecem durante submit
- [ ] Tenant criado no banco central (tenants table)
- [ ] Domínio criado (domains table)
- [ ] Banco tenant criado (timesheet_ULID)
- [ ] Migrations executadas no tenant DB
- [ ] Roles seeded (Admin, Manager, Technician)
- [ ] Admin user criado com email correto
- [ ] Role Admin atribuída ao user
- [ ] Token Sanctum gerado
- [ ] Redirecionamento para login funciona
- [ ] Login com credenciais criadas funciona
- [ ] Dashboard/menu aparece após login
- [ ] Permissões Admin funcionam (acesso total)

### Limpeza Após Testes:
```bash
# Remover tenant de teste
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "
  DROP DATABASE IF EXISTS timesheet_01K9XXXXXXXXXXXXXX;
  DELETE FROM timesheet.tenants WHERE slug = 'qatest-frontend-001';
  DELETE FROM timesheet.domains WHERE domain LIKE '%qatest-frontend-001%';
"
```

---

## 📊 Métricas de Performance

### Tempos Esperados:
- **Carregamento da página**: < 2s
- **Slug availability check**: < 500ms
- **Submit + criação tenant**: 8-15s (inclui migrations)
- **Login**: < 1s
- **Redirecionamento**: imediato

### Recursos Utilizados:
- **Migrations executadas**: 37 arquivos
- **Roles criadas**: 3 (Admin, Manager, Technician)
- **Permissions criadas**: ~17
- **Tabelas criadas**: ~30 (users, projects, timesheets, expenses, etc.)

---

**Status**: ✅ **Pronto para Teste**  
**Próximo Passo**: Executar este guia manualmente no navegador  
**Duração Estimada**: 15-20 minutos para teste completo
