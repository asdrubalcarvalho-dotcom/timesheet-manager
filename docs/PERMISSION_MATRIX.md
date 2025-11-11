# Permission Matrix - TimePerk System

## Overview
Este documento define as regras de autorização para Timesheets e Expenses no sistema TimePerk.

## Project Roles
Cada user tem um role por projeto definido na tabela `project_members`:
- **`project_role`**: Controla permissões de timesheets (`member` | `manager` | `none`)
- **`expense_role`**: Controla permissões de expenses (`member` | `manager` | `none`)

## Timesheet Permissions

### View (Visualizar)
| Role | Próprios | Members | Outros Managers | Admins |
|------|----------|---------|-----------------|--------|
| **Member** | ✅ | ❌ | ❌ | ❌ |
| **Manager** | ✅ | ✅ | ❌ | ❌ |
| **Admin** | ✅ | ✅ | ✅ | ✅ |

**Regras:**
- Members veem apenas os próprios timesheets
- Managers veem os próprios + timesheets de members do projeto
- Managers **NÃO** veem timesheets de outros managers do mesmo projeto
- Admins veem todos

### Create (Criar)
| Role | Permissão |
|------|-----------|
| **Member** | ✅ Pode criar timesheets para si próprio |
| **Manager** | ✅ Pode criar timesheets para si próprio |
| **Admin** | ✅ Pode criar timesheets para qualquer user |

### Update (Editar)
| Role | Próprios | Members | Outros Managers | Status Approved/Closed |
|------|----------|---------|-----------------|------------------------|
| **Member** | ✅ (draft/submitted/rejected) | ❌ | ❌ | ❌ |
| **Manager** | ✅ (draft/submitted/rejected) | ✅ (draft/submitted/rejected) | ❌ | ❌ |
| **Admin** | ✅ (todos) | ✅ (todos) | ✅ (todos) | ✅ |

**Regras:**
- Ownership verificado PRIMEIRO: `technician.user_id === $user->id`
- Depois verifica role do owner: se `project_role === 'manager'` e não é o próprio user, bloqueia
- Status `approved` ou `closed` não podem ser editados (exceto Admin)

### Delete (Apagar)
**Mesmas regras que Update**

### Approve (Aprovar)
| Role | Próprios | Members | Outros Managers |
|------|----------|---------|-----------------|
| **Member** | ❌ | ❌ | ❌ |
| **Manager** | ✅ | ✅ | ❌ |
| **Admin** | ✅ | ✅ | ✅ |

**Regras:**
- Managers **PODEM** aprovar os próprios timesheets
- Managers podem aprovar timesheets de members
- Managers **NÃO PODEM** aprovar timesheets de outros managers
- Apenas timesheets com status `submitted` podem ser aprovados

**Implementação:**
```php
// TimesheetPolicy::approve()
if ($timesheet->project->isUserProjectManager($user)) {
    // Se for próprio, pode aprovar
    if ($timesheet->technician && $timesheet->technician->user_id === $user->id) {
        return true;
    }
    
    // Se for de outro user, verificar role
    if ($timesheet->technician && $timesheet->technician->user) {
        $ownerProjectRole = $timesheet->project->getUserProjectRole($timesheet->technician->user);
        return $ownerProjectRole === 'member'; // Bloqueia outros managers
    }
    return true;
}
```

### Reject (Rejeitar)
**Mesmas regras que Approve**
- Status permitidos: `submitted` ou `approved`

### Close (Fechar - Payroll Processado)
| Role | Próprios | Members | Outros Managers |
|------|----------|---------|-----------------|
| **Member** | ❌ | ❌ | ❌ |
| **Manager** | ✅ | ✅ | ✅ |
| **Admin** | ✅ | ✅ | ✅ |

**Regras:**
- Apenas timesheets `approved` podem ser fechados
- Status `closed` indica que o timesheet foi processado pelo RH/Payroll
- Endpoint manual: `PUT /timesheets/{id}/close`

### Reopen (Reabrir)
| Role | Permissão |
|------|-----------|
| **Manager** | ✅ Pode reabrir timesheets aprovados para permitir edições |
| **Admin** | ✅ Pode reabrir qualquer timesheet |

---

## Expense Permissions

### Estrutura Paralela
As permissions de Expenses seguem a **mesma lógica** que Timesheets, mas usam **`expense_role`** em vez de `project_role`.

### View (Visualizar)
| Role | Próprias | Members | Outros Managers | Admins |
|------|----------|---------|-----------------|--------|
| **Member** | ✅ | ❌ | ❌ | ❌ |
| **Expense Manager** | ✅ | ✅ | ❌ | ❌ |
| **Admin** | ✅ | ✅ | ✅ | ✅ |

### Approve/Reject
| Role | Próprias | Members | Outros Managers |
|------|----------|---------|-----------------|
| **Member** | ❌ | ❌ | ❌ |
| **Expense Manager** | ✅ | ✅ | ❌ |
| **Admin** | ✅ | ✅ | ✅ |

**Implementação:**
```php
// ExpensePolicy::approve()
if ($expense->project->isUserExpenseManager($user)) {
    // Se for própria, pode aprovar
    if ($expense->technician && $expense->technician->user_id === $user->id) {
        return true;
    }
    
    // Se for de outro user, verificar expense_role
    if ($expense->technician && $expense->technician->user) {
        $ownerExpenseRole = $expense->project->getUserExpenseRole($expense->technician->user);
        return $ownerExpenseRole === 'member'; // Bloqueia outros managers
    }
    return true;
}
```

---

## Status Flow

### Timesheets & Expenses
```
draft → submitted → approved → closed (manual)
                 ↓
              rejected (pode voltar a draft)
```

**Estados:**
- **`draft`**: Rascunho, pode ser editado pelo owner
- **`submitted`**: Submetido para aprovação, aguarda manager
- **`approved`**: Aprovado por manager, não pode ser editado (exceto Admin)
- **`rejected`**: Rejeitado por manager, pode voltar a draft
- **`closed`**: Processado pelo RH/Payroll (manual via endpoint `/close`)

---

## Three-Layer Authorization

### 1. Permission Gates (routes/api.php)
```php
Route::middleware(['permission:approve-timesheets', 'throttle:critical'])
    ->put('/timesheets/{timesheet}/approve', [TimesheetController::class, 'approve']);
```

### 2. Policy Authorization (Controller)
```php
public function approve(Request $request, Timesheet $timesheet): JsonResponse {
    $this->authorize('approve', $timesheet);  // Calls TimesheetPolicy::approve()
    // ...
}
```

### 3. Policy Implementation (Business Rules)
```php
public function approve(User $user, Timesheet $timesheet): bool {
    // Verificações: permissão, status, membership, role
    // ...
}
```

---

## Key Methods

### Project Model
- `isUserMember(User $user)`: Verifica se user é membro do projeto
- `isUserProjectManager(User $user)`: Verifica se user tem `project_role='manager'`
- `isUserExpenseManager(User $user)`: Verifica se user tem `expense_role='manager'`
- `getUserProjectRole(User $user)`: Retorna `project_role` do user (`member`/`manager`/`none`)
- `getUserExpenseRole(User $user)`: Retorna `expense_role` do user

### Policy Files
- `backend/app/Policies/TimesheetPolicy.php`: Regras de timesheets
- `backend/app/Policies/ExpensePolicy.php`: Regras de expenses

---

## Common Pitfalls

### ❌ ERRADO
```php
// NÃO verificar apenas hasRole('Manager')
if ($user->hasRole('Manager')) {
    return true;  // ERRADO - permite acesso a todos os projetos
}

// NÃO permitir managers editarem outros managers
if ($timesheet->project->isUserProjectManager($user)) {
    return true;  // ERRADO - falta verificar role do owner
}
```

### ✅ CORRETO
```php
// Verificar ownership PRIMEIRO, role do owner DEPOIS
if ($timesheet->project->isUserProjectManager($user)) {
    // Próprio timesheet sempre pode
    if ($timesheet->technician && $timesheet->technician->user_id === $user->id) {
        return true;
    }
    
    // Verificar role do owner
    if ($timesheet->technician && $timesheet->technician->user) {
        $ownerProjectRole = $timesheet->project->getUserProjectRole($timesheet->technician->user);
        // Bloqueia se owner for manager e não for o próprio user
        if ($ownerProjectRole === 'manager' && $timesheet->technician->user_id !== $user->id) {
            return false;
        }
    }
    return true;  // Permite editar members
}
```

---

## Summary

### Manager Permissions (CRÍTICO)
✅ **PODEM:**
- Ver os próprios timesheets/expenses
- Ver timesheets/expenses de **members** do projeto
- Editar os próprios timesheets/expenses (draft/submitted/rejected)
- Editar timesheets/expenses de **members** (draft/submitted/rejected)
- Aprovar/rejeitar os **próprios** timesheets/expenses
- Aprovar/rejeitar timesheets/expenses de **members**
- Fechar timesheets/expenses (marcar como payroll processado)

❌ **NÃO PODEM:**
- Ver timesheets/expenses de **outros managers** do mesmo projeto
- Editar timesheets/expenses de **outros managers**
- Aprovar/rejeitar timesheets/expenses de **outros managers**
- Editar timesheets/expenses com status `approved` ou `closed` (exceto Admin)

### Implementation Order
1. **Ownership check**: `technician.user_id === $user->id` (sempre permite próprios registos)
2. **Role check**: `getUserProjectRole()` para verificar se owner é manager
3. **Block**: Se owner for manager E não for o próprio user, bloqueia

---

**Última atualização:** 10 de novembro de 2025
