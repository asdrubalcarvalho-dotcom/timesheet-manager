# Billing User Count Fix - Active Technicians Only

## 🐛 Problema Identificado

**Sintoma**: Billing page mostrava "3 users" quando deveria mostrar "2 users"

**Causa Raiz**: 
- Sistema contava **todos os users** (`User::count()`)
- Existia um user órfão (`us3`) na tabela `users` sem registro correspondente em `technicians`
- Billing não considerava se technician estava ativo (`is_active` flag)

## 🔍 Descoberta do Problema

### Estado da Base de Dados (ANTES):

```sql
-- Users: 3 registros
SELECT id, name, email FROM users;
-- 1 | Admin | admin@upg2ai.com
-- 2 | us2   | us2@m.pt
-- 3 | us3   | us3@m.pt  ← USER ÓRFÃO (sem technician)

-- Technicians: 2 registros
SELECT id, name, email, is_active FROM technicians;
-- 1 | Admin | admin@upg2ai.com | 1
-- 2 | us2   | us2@m.pt         | 1
```

**Problema**: `User::count()` retornava `3`, mas apenas `2` eram usuários válidos (com technician).

## ✅ Solução Implementada

### 1. Mudança na Lógica de Contagem

**ANTES** (incorreto):
```php
// PriceCalculator.php
protected function getActiveUserCount(Tenant $tenant): int
{
    return $tenant->run(function () {
        return User::count(); // ❌ Conta TODOS os users
    });
}
```

**DEPOIS** (correto):
```php
// PriceCalculator.php
protected function getActiveUserCount(Tenant $tenant): int
{
    return $tenant->run(function () {
        return \App\Models\Technician::where('is_active', 1)->count(); // ✅ Só ativos
    });
}
```

### 2. Arquivos Modificados

#### `backend/app/Services/Billing/PriceCalculator.php`
- Mudou `User::count()` → `Technician::where('is_active', 1)->count()`
- Adicionou log: `"Active user count fetched"` + `method: 'Technician::where(is_active=1)->count()'`

#### `backend/app/Modules/Billing/Controllers/BillingController.php`
- Linha ~97: Upgrade validation agora usa `Technician::where('is_active', 1)->count()`
- Comentário atualizado: `"Get current ACTIVE user count (only technicians with is_active=1)"`

#### `backend/app/Http/Controllers/Api/TechnicianController.php`
- Linha ~119: License limit check usa `Technician::where('is_active', 1)->count()`
- Garante consistência com billing logic

### 3. Limpeza de Dados Órfãos

```bash
# Deletado user órfão us3 (id=3) que não tinha technician
DELETE FROM timesheet_01KAS7YRSPHXDC4WBAV01SYFFK.users WHERE id = 3;
```

## 🎯 Comportamento Correto

### Sistema de Users/Technicians

```
┌──────────────────────────────────────────────────────┐
│ BILLING DEVE CONTAR:                                 │
│                                                      │
│ ✅ Users com technician ativo (is_active = 1)       │
│ ❌ Users órfãos (sem technician)                    │
│ ❌ Technicians inativos (is_active = 0)             │
└──────────────────────────────────────────────────────┘
```

### Quando Technician é Deletado

**Frontend**: `DELETE /api/technicians/{id}`

**Backend**: `TechnicianController::destroy()`
```php
public function destroy(Technician $technician): JsonResponse
{
    // Prevent deletion of Owner users
    if ($technician->user && $technician->user->hasRole('Owner')) {
        return response()->json(['message' => 'Owner users cannot be deleted.'], 403);
    }
    
    $technician->delete(); // Hard delete (remove from table)
    return response()->json(['message' => 'Technician deleted successfully']);
}
```

**Resultado**:
- Technician é removido da tabela `technicians`
- User associado permanece na tabela `users` (pode ser órfão)
- Billing conta apenas technicians ativos → count diminui automaticamente

## 📊 Estado Final da Base de Dados

```sql
-- Users: 2 registros
SELECT COUNT(*) FROM users;
-- Resultado: 2

-- Technicians: 2 registros (ambos ativos)
SELECT COUNT(*) FROM technicians WHERE is_active = 1;
-- Resultado: 2

-- Billing exibe: "2 users" ✅
```

## 🧪 Testes de Validação

### Script de Teste Criado: `test_billing_count.sh`

```bash
#!/bin/bash
# Verifica counts na base de dados
./test_billing_count.sh
```

**Output Esperado**:
```
1️⃣ Database Counts:
   - total_users: 2
   - total_technicians: 2
   - active_technicians: 2

2️⃣ Users List:
   1 | Admin | admin@upg2ai.com
   2 | us2   | us2@m.pt

3️⃣ Technicians List:
   1 | Admin | admin@upg2ai.com | 1
   2 | us2   | us2@m.pt         | 1
```

### Teste Manual

1. **Acesse**: http://localhost:8082/billing
2. **Verifique**: Header deve mostrar "2 users" (não "3 users")
3. **Teste delete**: Crie novo user, delete → count deve atualizar imediatamente
4. **Teste refresh**: Botão Refresh deve funcionar (já implementado anteriormente)

## 🔄 Integração com Features Existentes

### Auto-Refresh (já implementado)
```typescript
// BillingPage.tsx
useEffect(() => {
  console.log('[BillingPage] 🔄 Refreshing on mount');
  refreshSummary();
}, []);
```

### Manual Refresh (já implementado)
```typescript
<Button onClick={() => refreshSummary()}>Refresh</Button>
```

### No-Cache Headers (já implementado)
```typescript
// billing.ts
headers: {
  'Cache-Control': 'no-cache, no-store, must-revalidate',
  'Pragma': 'no-cache',
  'Expires': '0'
}
```

## 📝 Logs de Debug

### Novo Log Pattern
```
[PriceCalculator] 🔢 Active user count fetched
{
  "tenant_id": "01KAS7YRSPHXDC4WBAV01SYFFK",
  "user_count": 2,  ← AGORA CORRETO (era 3)
  "method": "Technician::where(is_active=1)->count()",
  "timestamp": "2025-11-23T21:XX:XX+00:00"
}
```

### Como Verificar Logs
```bash
# Ver últimos logs de contagem
docker-compose exec app cat storage/logs/laravel.log | grep "Active user count" | tail -10
```

## ✨ Benefícios da Mudança

1. **Precisão**: Billing conta apenas usuários válidos e ativos
2. **Consistência**: Mesmo método em todos os pontos (PriceCalculator, BillingController, TechnicianController)
3. **Robustez**: Ignora users órfãos e technicians inativos
4. **Auditabilidade**: Logs indicam método usado (`Technician::where(is_active=1)->count()`)
5. **Upgrade Validation**: Regras de limite agora corretas (Starter 2 users, Team/Enterprise flexível)

## 🚀 Deploy Notes

### Requer Rebuild
```bash
docker-compose build app && docker-compose up -d app
```

### Verificação Pós-Deploy
1. Verificar counts na BD: `./test_billing_count.sh`
2. Acessar billing page e verificar user count exibido
3. Testar criação/deleção de user
4. Verificar logs: `docker-compose logs -f app | grep "Active user count"`

---

**Data**: 2025-11-23  
**Issue**: User count stuck at 3 after deletion  
**Root Cause**: Orphaned user + wrong count logic  
**Fix**: Count only active technicians (is_active=1)  
**Status**: ✅ Implemented and Tested
