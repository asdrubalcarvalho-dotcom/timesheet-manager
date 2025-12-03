# Email Verification Testing Guide

## ✅ Sistema Funcionando

O sistema de verificação de email está **100% funcional**. Os emails são enviados via queue (assíncrono) para os logs do Laravel.

## 🔧 Comandos Artisan Criados

### 1. Enviar Email de Teste

```bash
docker-compose exec app php artisan test:email-verification seu-email@exemplo.com
```

**Saída:**
- Cria um pending signup
- Gera token de 64 caracteres
- Envia email (via queue)
- Mostra URL de verificação
- Mostra comando curl para testar API

### 2. Listar Pending Signups

```bash
docker-compose exec app php artisan signups:list
```

**Saída:**
```
📋 Pending Tenant Signups

+--------------+-----------------+------------------+-------------------------+----------------+------------------+------------+
| Company      | Slug            | Email            | Token (preview)         | Created        | Expires          | Status     |
+--------------+-----------------+------------------+-------------------------+----------------+------------------+------------+
| Test Company | test-1764030464 | teste@exemplo.com| yjcrXG5Cqg5ShPX0fpZS... | 45 seconds ago | 2025-11-26 00:27 | ⏳ Pending |
+--------------+-----------------+------------------+-------------------------+----------------+------------------+------------+
```

### 3. Ver Detalhes de um Token

```bash
docker-compose exec app php artisan signups:list --token=SEU_TOKEN_AQUI
```

**Saída:**
```
🔍 Token Details

+--------------------+-------------------------------------------------------------+
| Field              | Value                                                       |
+--------------------+-------------------------------------------------------------+
| Company Name       | Test Company                                                |
| Slug               | test-1764030464                                             |
| Admin Email        | teste@exemplo.com                                           |
| Verification Token | yjcrXG5Cqg5ShPX0fpZSrzHuHw6DBL8g9bkGQbR6ZeYd2M5umDhMxdZubKjM39q9 |
| Is Valid           | Yes ✅                                                      |
+--------------------+-------------------------------------------------------------+

🔗 Verification URL:
   http://localhost:8082/verify-signup?token=yjcrXG5Cqg5ShPX0fpZSrzHuHw6DBL8g9bkGQbR6ZeYd2M5umDhMxdZubKjM39q9

🧪 Test via API:
   curl -X GET 'http://localhost/api/tenants/verify-signup?token=yjcrXG5Cqg5ShPX0fpZSrzHuHw6DBL8g9bkGQbR6ZeYd2M5umDhMxdZubKjM39q9'
```

## 📧 Como Validar Emails

### Opção 1: Ver Emails nos Logs (MAIL_MAILER=log)

```bash
# Ver últimos emails enviados
docker-compose exec app tail -100 storage/logs/laravel.log | grep -A 30 "Subject:"
```

**Nota:** Como a notificação usa `ShouldQueue`, o email vai para a fila. Para ver no log, você precisa processar a fila:

```bash
# Processar fila manualmente (uma vez)
docker-compose exec app php artisan queue:work --once

# Ou manter worker rodando
docker-compose exec app php artisan queue:work
```

### Opção 2: Testar Diretamente via API

```bash
# 1. Criar pending signup
docker-compose exec app php artisan test:email-verification teste@exemplo.com

# 2. Copiar o token da saída

# 3. Testar verificação
curl -X GET 'http://localhost/api/tenants/verify-signup?token=SEU_TOKEN' \
  -H "Accept: application/json" | jq .
```

**Resposta de Sucesso:**
```json
{
  "status": "success",
  "message": "Email verified successfully! Your workspace has been created.",
  "tenant": {
    "id": "01KAW6KF4FYY81NW99H1VXPTR5",
    "slug": "test-1764030464",
    "name": "Test Company"
  },
  "login_url": "http://test-1764030464.localhost:8082/login?email=teste%40exemplo.com"
}
```

### Opção 3: Testar pelo Frontend

```bash
# 1. Listar tokens disponíveis
docker-compose exec app php artisan signups:list

# 2. Pegar token completo
docker-compose exec app php artisan signups:list --token=yjcrXG5Cqg5ShPX0fpZS...

# 3. Copiar a URL de verificação e abrir no browser
http://localhost:8082/verify-signup?token=yjcrXG5Cqg5ShPX0fpZSrzHuHw6DBL8g9bkGQbR6ZeYd2M5umDhMxdZubKjM39q9
```

## 🧪 Testar Fluxo Completo End-to-End

### 1. Registrar Novo Tenant (Frontend)

1. Acesse `http://localhost:8082`
2. Clique em "Create your workspace"
3. Preencha o formulário:
   - Company: Minha Empresa
   - Slug: minha-empresa
   - Admin Name: João Silva
   - Email: joao@exemplo.com
   - Password: senhasegura123
4. Clique "Create Workspace"
5. Veja mensagem: "Check Your Email - We've sent a verification link to joao@exemplo.com"

### 2. Pegar Token do Banco

```bash
# Listar todos os pending signups
docker-compose exec app php artisan signups:list

# Ver detalhes do signup com o email
docker-compose exec app php artisan tinker
>>> $signup = \App\Models\PendingTenantSignup::where('admin_email', 'joao@exemplo.com')->first();
>>> echo $signup->verification_token;
>>> exit
```

### 3. Verificar Email

**Opção A - Via Frontend:**
```
http://localhost:8082/verify-signup?token=COLE_O_TOKEN_AQUI
```

**Opção B - Via API:**
```bash
curl -X GET 'http://localhost/api/tenants/verify-signup?token=COLE_O_TOKEN_AQUI' \
  -H "Accept: application/json" | jq .
```

### 4. Confirmar Tenant Criado

```bash
docker-compose exec app php artisan tenants:list
```

Deve aparecer o novo tenant!

## 🔍 Validação de Token - Regras

O token é considerado **válido** quando:
- ✅ Existe no banco (`pending_tenant_signups`)
- ✅ Não está expirado (`expires_at` > agora)
- ✅ Ainda não foi verificado (`verified = false`)
- ✅ Slug ainda está disponível (não foi criado outro tenant com mesmo slug)

O token é **inválido** quando:
- ❌ Não encontrado no banco
- ❌ Expirou (mais de 24h desde criação)
- ❌ Já foi usado (`verified = true`)
- ❌ Slug já foi usado por outro tenant

## 📊 Estados do Pending Signup

| Status | Descrição | Ação |
|--------|-----------|------|
| ⏳ **Pending** | Token válido, aguardando verificação | Usuário pode clicar no link |
| ⏰ **Expired** | Token expirou (>24h) | Usuário precisa se registrar novamente |
| ✅ **Verified** | Email verificado, tenant criado | Registro concluído, pode fazer login |

## 🛠️ Troubleshooting

### Problema: "Token not found"

**Causa:** Token incorreto ou já usado.

**Solução:**
```bash
# Verificar se token existe
docker-compose exec app php artisan signups:list --token=SEU_TOKEN
```

### Problema: "Token expired"

**Causa:** Mais de 24h desde a criação.

**Solução:**
```bash
# Deletar signup expirado
docker-compose exec app php artisan tinker
>>> \App\Models\PendingTenantSignup::where('verification_token', 'TOKEN_EXPIRADO')->delete();

# Registrar novamente pelo frontend
```

### Problema: "Slug already taken"

**Causa:** Entre a criação do pending signup e a verificação, outro tenant usou o mesmo slug.

**Solução:** Usuário precisa se registrar novamente com slug diferente.

### Problema: Email não está sendo enviado

**Causa:** Queue não está sendo processada (notificação usa `ShouldQueue`).

**Solução:**
```bash
# Opção 1: Processar queue manualmente
docker-compose exec app php artisan queue:work --once

# Opção 2: Desabilitar queue (para testes)
# Remover "implements ShouldQueue" de TenantEmailVerification.php

# Opção 3: Usar sync queue para testes
# Em .env: QUEUE_CONNECTION=sync
```

## 📝 Configuração de Email para Produção

### Desenvolvimento (Logs)
```env
MAIL_MAILER=log
MAIL_FROM_ADDRESS=noreply@timeperk.com
APP_FRONTEND_URL=http://localhost:8082
```

### Produção (SendGrid)
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=SG.xxxxxxxxxxxxxxxxxxxxx
MAIL_FROM_ADDRESS=noreply@timeperk.com
MAIL_FROM_NAME="${APP_NAME}"
APP_FRONTEND_URL=https://app.timeperk.com
```

### Produção (Mailgun)
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@mg.timeperk.com
MAIL_PASSWORD=your_mailgun_password
MAIL_FROM_ADDRESS=noreply@timeperk.com
```

### Produção (AWS SES)
```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1
MAIL_FROM_ADDRESS=noreply@timeperk.com
```

## ✅ Checklist de Testes

- [ ] Criar pending signup via frontend
- [ ] Verificar token gerado corretamente (64 chars)
- [ ] Listar pending signups via `signups:list`
- [ ] Ver detalhes completos do token
- [ ] Validar token via API (curl)
- [ ] Validar token via frontend (/verify-signup)
- [ ] Confirmar tenant criado em `tenants` table
- [ ] Confirmar pending signup deletado após verificação
- [ ] Testar token expirado (>24h)
- [ ] Testar token já usado
- [ ] Testar slug duplicado

## 📚 Arquivos Relacionados

**Backend:**
- `app/Models/PendingTenantSignup.php` - Model
- `app/Notifications/TenantEmailVerification.php` - Email template
- `app/Http/Controllers/Api/TenantController.php` - Endpoints
- `database/migrations/*_create_pending_tenant_signups_table.php` - Schema
- `app/Console/Commands/TestEmailVerification.php` - Comando de teste
- `app/Console/Commands/ShowPendingSignups.php` - Comando de listagem

**Frontend:**
- `src/components/Auth/TenantRegistration.tsx` - Form de registro
- `src/components/Auth/VerifyEmail.tsx` - Página de verificação
- `src/App.tsx` - Roteamento

**Rotas API:**
- `POST /api/tenants/request-signup` - Criar pending signup
- `GET /api/tenants/verify-signup?token=XXX` - Verificar email
