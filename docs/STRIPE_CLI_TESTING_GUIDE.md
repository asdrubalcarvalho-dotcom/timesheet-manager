# 🧪 Guia de Testes com Stripe CLI - TimePerk Billing

**Data**: 30 de Novembro de 2025  
**Objetivo**: Testar webhooks localmente usando Stripe CLI

---

## 📋 Pré-requisitos

✅ Stripe CLI instalado (`brew install stripe/stripe-cli/stripe`)  
✅ Containers Docker rodando (`docker-compose ps`)  
✅ Endpoint webhook registrado (`POST /api/stripe/webhook`)

---

## 🚀 Workflow de Testes (3 Terminais)

### Terminal 1: Webhook Listener (SEMPRE ATIVO)

```bash
cd /Users/asdrubalcarvalho/Documents/IA_Machine_Learning/timesheet

# Primeiro login (APENAS UMA VEZ)
stripe login

# Depois, inicia o listener (DEIXA RODANDO)
stripe listen --forward-to http://api.localhost/api/stripe/webhook
```

**O que você vai ver:**
```
Ready! Your webhook signing secret is 'REMOVEDxxxxxxxxxxxxx' (^C to quit)
```

**⚠️ AÇÃO OBRIGATÓRIA**:
1. Copie o `REMOVEDxxxxxxxxxxxxx`
2. Adicione ao `.env`:
   ```bash
   STRIPE_TEST_WEBHOOK_SECRET=REMOVEDxxxxxxxxxxxxx
   ```
3. Rebuild containers:
   ```bash
   docker-compose down && docker-compose up -d --build
   ```

---

### Terminal 2: Triggers de Teste

**Aguardar Terminal 1 estar rodando**, depois executar:

#### ✅ Teste 1: Pagamento Bem-Sucedido
```bash
stripe trigger payment_intent.succeeded
```

**O que acontece:**
- Terminal 1 mostra o webhook sendo recebido
- Backend atualiza subscription → `status=active`
- Reseta `failed_renewal_attempts=0`
- Avança `billing_period_ends_at` (+1 mês se for renewal)
- Cria registro em `payments` com `status=completed`

**Verificar no banco**:
```bash
docker-compose exec app php artisan tinker --execute="
\$payment = \Modules\Billing\Models\Payment::latest()->first();
echo '✅ Último Pagamento:' . PHP_EOL;
echo 'Status: ' . \$payment->status . PHP_EOL;
echo 'Amount: €' . \$payment->amount . PHP_EOL;
echo 'Transaction ID: ' . \$payment->transaction_id . PHP_EOL;
echo 'Operation: ' . \$payment->operation . PHP_EOL;
"
```

---

#### ❌ Teste 2: Pagamento Falhado
```bash
stripe trigger payment_intent.payment_failed
```

**O que acontece:**
- Subscription → `status=past_due`
- Incrementa `failed_renewal_attempts` (exemplo: 0→1)
- Se primeira falha: `grace_period_until = now()+15 dias`
- Cria registro em `payments` com `status=failed`
- Coloca `notes` com motivo do erro

**Verificar no banco**:
```bash
docker-compose exec app php artisan tinker --execute="
\$subscription = \Modules\Billing\Models\Subscription::first();
echo '⚠️ Status da Subscription:' . PHP_EOL;
echo 'Status: ' . \$subscription->status . PHP_EOL;
echo 'Failed Attempts: ' . \$subscription->failed_renewal_attempts . PHP_EOL;
echo 'Grace Period Until: ' . \$subscription->grace_period_until . PHP_EOL;
"
```

---

#### 🔄 Teste 3: Idempotência (Evento Duplicado)
```bash
# Trigger com ID fixo
stripe trigger payment_intent.succeeded \
  --add payment_intent:id=pi_test_idempotency_123

# Trigger NOVAMENTE com mesmo ID
stripe trigger payment_intent.succeeded \
  --add payment_intent:id=pi_test_idempotency_123
```

**Resultado esperado:**
- **1ª execução**: Payment criado
- **2ª execução**: Log mostra "idempotent skip" (não cria duplicado)

**Verificar**:
```bash
docker-compose exec app php artisan tinker --execute="
\$count = \Modules\Billing\Models\Payment::where('transaction_id', 'pi_test_idempotency_123')->count();
echo '✅ Payments com ID pi_test_idempotency_123: ' . \$count . ' (deve ser 1)' . PHP_EOL;
"
```

---

### Terminal 3: Logs da Aplicação (OPCIONAL)

```bash
# Ver logs do Laravel em tempo real
docker-compose exec app tail -f storage/logs/laravel.log

# OU logs do container
docker-compose logs -f app
```

**Grep útil para filtrar logs do webhook**:
```bash
docker-compose logs -f app | grep "\[StripeWebhook\]"
```

---

## 📊 Casos de Teste Completos

### Cenário 1: Renovação Bem-Sucedida
```bash
# 1. Configurar tenant em trial ending
docker-compose exec app php artisan tinker --execute="
\$tenant = \App\Models\Tenant::where('slug', 'upg-to-ai')->first();
tenancy()->initialize(\$tenant);
\$sub = \Modules\Billing\Models\Subscription::first();
\$sub->billing_period_ends_at = now()->subDays(1); // Vencida ontem
\$sub->save();
echo 'Subscription pronta para renovação: ' . \$sub->billing_period_ends_at;
"

# 2. Trigger renovação manual (via renewal service)
docker-compose exec app php artisan billing:run-renewals

# 3. Aguardar webhook payment_intent.succeeded no Terminal 1

# 4. Verificar renovação
docker-compose exec app php artisan tinker --execute="
\$tenant = \App\Models\Tenant::where('slug', 'upg-to-ai')->first();
tenancy()->initialize(\$tenant);
\$sub = \Modules\Billing\Models\Subscription::first();
echo 'Status: ' . \$sub->status . PHP_EOL;
echo 'Period ends: ' . \$sub->billing_period_ends_at . PHP_EOL;
"
```

---

### Cenário 2: Dunning Recovery
```bash
# 1. Simular falha de pagamento
docker-compose exec app php artisan tinker --execute="
\$tenant = \App\Models\Tenant::where('slug', 'upg-to-ai')->first();
tenancy()->initialize(\$tenant);
\$sub = \Modules\Billing\Models\Subscription::first();
\$sub->status = 'past_due';
\$sub->failed_renewal_attempts = 1;
\$sub->grace_period_until = now()->addDays(14);
\$sub->save();
echo 'Subscription em past_due para teste';
"

# 2. Trigger recuperação (dunning service)
docker-compose exec app php artisan billing:run-dunning

# 3. Se pagamento suceder, webhook vai resetar failed_attempts
```

---

## 🐛 Troubleshooting

### Erro: "Invalid signature"
**Causa**: Webhook secret incorreto ou não configurado  
**Fix**:
```bash
# 1. Copiar secret do Terminal 1 (onde stripe listen está rodando)
# 2. Adicionar ao .env:
STRIPE_TEST_WEBHOOK_SECRET=REMOVEDxxxxxxxxxxxxx

# 3. Rebuild containers
docker-compose down && docker-compose up -d --build
```

---

### Erro: "Webhook secret not configured" (500)
**Causa**: `billing.stripe.webhook_secret` não carregado  
**Fix**:
```bash
# Verificar config
docker-compose exec app php artisan tinker --execute="
echo config('billing.stripe.webhook_secret') ?? 'NOT SET';
"

# Se retornar "NOT SET", verificar:
# 1. .env tem STRIPE_TEST_WEBHOOK_SECRET
# 2. Containers foram rebuilados após adicionar
# 3. Cache limpo: docker-compose exec app php artisan config:clear
```

---

### Erro: "Tenant not found"
**Causa**: PaymentIntent sem `tenant_id` no metadata  
**Fix**: Garantir que `StripeGateway->createPaymentIntent()` está sendo chamado com tenant correto

**Debug**:
```bash
# Ver metadata do último PaymentIntent criado
docker-compose exec app php artisan tinker --execute="
\$payment = \Modules\Billing\Models\Payment::latest()->first();
print_r(\$payment->metadata);
"
```

---

### Webhook não chega no backend
**Causa**: `stripe listen` parado ou URL incorreta  
**Fix**:
```bash
# Verificar se listener está ativo (Terminal 1 deve mostrar "Ready!")
# Se não estiver, reiniciar:
stripe listen --forward-to http://api.localhost/api/stripe/webhook
```

---

## 📝 Logs Úteis

### Ver todos os webhooks recebidos
```bash
docker-compose exec app php artisan tinker --execute="
\$payments = \Modules\Billing\Models\Payment::latest()->take(5)->get(['id', 'status', 'operation', 'amount', 'transaction_id', 'created_at']);
foreach (\$payments as \$p) {
    echo sprintf('[%s] %s %s - €%.2f (%s)\n', \$p->id, \$p->status, \$p->operation, \$p->amount, \$p->transaction_id);
}
"
```

### Ver subscription após webhook
```bash
docker-compose exec app php artisan tinker --execute="
\$tenant = \App\Models\Tenant::where('slug', 'upg-to-ai')->first();
tenancy()->initialize(\$tenant);
\$sub = \Modules\Billing\Models\Subscription::first();
echo '📊 Subscription State:' . PHP_EOL;
echo sprintf('  Status: %s\n', \$sub->status);
echo sprintf('  Plan: %s\n', \$sub->plan);
echo sprintf('  Failed Attempts: %d\n', \$sub->failed_renewal_attempts);
echo sprintf('  Grace Period: %s\n', \$sub->grace_period_until ?? 'NULL');
echo sprintf('  Next Renewal: %s\n', \$sub->billing_period_ends_at);
"
```

---

## ✅ Checklist de Validação

Após executar todos os testes, verificar:

- [ ] Terminal 1 (`stripe listen`) mostra eventos recebidos
- [ ] `payment_intent.succeeded` cria Payment com `status=completed`
- [ ] `payment_intent.succeeded` marca Subscription como `active`
- [ ] `payment_intent.succeeded` reseta `failed_renewal_attempts=0`
- [ ] `payment_intent.succeeded` avança billing_period (+1 mês) se renewal
- [ ] `payment_intent.payment_failed` cria Payment com `status=failed`
- [ ] `payment_intent.payment_failed` marca Subscription como `past_due`
- [ ] `payment_intent.payment_failed` incrementa `failed_renewal_attempts`
- [ ] `payment_intent.payment_failed` seta `grace_period_until` na 1ª falha
- [ ] Idempotência: mesmo PaymentIntent ID enviado 2x não cria duplicados
- [ ] Logs mostram `[StripeWebhook]` com tenant_id, subscription_id, amounts

---

## 🎓 Comandos Rápidos (Cheat Sheet)

```bash
# LOGIN (apenas uma vez)
stripe login

# INICIAR LISTENER (Terminal 1 - sempre ativo)
stripe listen --forward-to http://api.localhost/api/stripe/webhook

# TESTAR SUCESSO (Terminal 2)
stripe trigger payment_intent.succeeded

# TESTAR FALHA (Terminal 2)
stripe trigger payment_intent.payment_failed

# VER LOGS (Terminal 3)
docker-compose logs -f app | grep "\[StripeWebhook\]"

# VERIFICAR ÚLTIMO PAYMENT
docker-compose exec app php artisan tinker --execute="\Modules\Billing\Models\Payment::latest()->first()"

# VERIFICAR SUBSCRIPTION
docker-compose exec app php artisan tinker --execute="
\$tenant = \App\Models\Tenant::where('slug', 'upg-to-ai')->first();
tenancy()->initialize(\$tenant);
\Modules\Billing\Models\Subscription::first();
"
```

---

**✅ Pronto para testar!** Execute os comandos na ordem:
1. Terminal 1: `stripe listen`
2. Copiar webhook secret → `.env` → rebuild
3. Terminal 2: `stripe trigger payment_intent.succeeded`
4. Verificar banco de dados

**Documentação oficial**: https://stripe.com/docs/stripe-cli
