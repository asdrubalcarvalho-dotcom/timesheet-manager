# 💰 TimePerk - Sistema de Expenses - Especificação Completa

## 📋 Visão Geral

Sistema completo de gestão de despesas com fluxo de aprovação em duas etapas:
1. **Expense Manager** - Valida despesas e recibos
2. **Finance Team** - Aprovação final para pagamento

## 🔄 Fluxo de Estados

```
┌─────────┐
│  DRAFT  │ ← Worker cria despesa
└────┬────┘
     │ submit()
     ▼
┌──────────┐
│SUBMITTED │ ← Aguardando Expense Manager
└────┬─────┘
     │ approveByManager() ou reject()
     ├─────────────────┐
     ▼                 ▼
┌──────────────┐  ┌──────────┐
│FINANCE_REVIEW│  │ REJECTED │ → Volta para DRAFT
└──────┬───────┘  └──────────┘
       │ approveByFinance() ou reject()
       ├─────────────────┐
       ▼                 ▼
┌────────────────┐  ┌──────────┐
│FINANCE_APPROVED│  │ REJECTED │
└───────┬────────┘  └──────────┘
        │ markAsPaid()
        ▼
   ┌──────┐
   │ PAID │
   └──────┘
```

## 📊 Estados Disponíveis

| Estado | Descrição | Próximas Ações Possíveis |
|--------|-----------|---------------------------|
| `draft` | Criada pelo worker | `submit` |
| `submitted` | Aguardando Expense Manager | `approve`, `reject` |
| `approved` | ❌ **DEPRECATED** - Não usar mais | Migrar para `finance_review` |
| `finance_review` | Finance analisando | `approveByFinance`, `reject` |
| `finance_approved` | Aprovada para pagamento | `markAsPaid` |
| `paid` | Paga | **FINAL** |
| `rejected` | Rejeitada | `submit` (recomeçar) |

## 💳 Tipos de Despesas

### 1. **Reimbursement** (Reembolso)
```typescript
{
  expense_type: 'reimbursement',
  category: 'fuel' | 'meals' | 'materials' | 'accommodation' | 'other',
  amount: 50.00,
  attachment_path: 'receipts/receipt_123.pdf', // OBRIGATÓRIO
  description: 'Combustível Lisboa-Porto'
}
```

**Características:**
- Requer recibo/fatura anexado
- Worker pagou do próprio bolso
- Valor fixo (não calculado)
- Categorias: Combustível, Refeições, Materiais, Hospedagem, Outros

### 2. **Mileage** (Quilometragem)
```typescript
{
  expense_type: 'mileage',
  distance_km: 150.50,
  rate_per_km: 0.36, // Taxa por km (definida pela empresa)
  vehicle_type: 'car' | 'motorcycle',
  amount: 54.18, // AUTO-CALCULADO: distance_km * rate_per_km
  description: 'Viagem cliente em Lisboa'
}
```

**Características:**
- Valor **auto-calculado**: `distance_km × rate_per_km`
- Não requer recibo (opcional: pode anexar comprovativo de deslocação)
- Tipos de veículo: Carro, Moto
- Taxa por km configurável (padrão: €0.36)

### 3. **Company Card** (Cartão Empresa) - **Futuro**
```typescript
{
  expense_type: 'company_card',
  card_transaction_id: 'TRX-2025-001234',
  transaction_date: '2025-11-09',
  amount: 89.90,
  category: 'software_license',
  description: 'Licença Adobe Creative Cloud'
}
```

**Características:**
- Importado automaticamente de extratos bancários
- Apenas requer validação (não reembolso)
- Worker não pagou (empresa pagou)

## 🔐 Permissões e Roles

### **Permissions:**
| Permissão | Descrição | Atribuída a |
|-----------|-----------|-------------|
| `create-expenses` | Criar despesas | Worker, Manager, Admin |
| `view-expenses` | Ver próprias despesas | Worker, Manager, Admin |
| `approve-expenses` | Aprovar despesas (Expense Manager) | Manager (expense_role), Admin |
| `review-finance-expenses` | Ver despesas para revisão Finance | Finance, Admin |
| `approve-finance-expenses` | Aprovação final Finance | Finance, Admin |
| `mark-expenses-paid` | Marcar como pago | Finance, Admin |

### **Roles:**
```php
Worker → create-expenses, view-expenses
Manager (expense_role='manager') → approve-expenses
Finance → review-finance-expenses, approve-finance-expenses, mark-expenses-paid
Admin → ALL
```

## 🎨 UI/UX - Páginas e Fluxos

### **1. Página Worker - "My Expenses"**

**Ações disponíveis por estado:**
- `draft`: Edit, Delete, Submit
- `submitted`: View (read-only)
- `finance_review`: View (read-only)
- `finance_approved`: View (read-only)
- `paid`: View (read-only) + Ver payment_reference
- `rejected`: View, Edit, Re-submit

**Formulário:**
```tsx
<ExpenseForm>
  {/* Tipo de Despesa */}
  <Select name="expense_type">
    <Option value="reimbursement">💰 Reembolso</Option>
    <Option value="mileage">🚗 Quilometragem</Option>
  </Select>

  {/* Campos condicionais por tipo */}
  {expense_type === 'reimbursement' && (
    <>
      <Select name="category">
        <Option value="fuel">⛽ Combustível</Option>
        <Option value="meals">🍽️ Refeições</Option>
        <Option value="materials">🔧 Materiais</Option>
        <Option value="accommodation">🏨 Hospedagem</Option>
        <Option value="other">📦 Outros</Option>
      </Select>
      <CurrencyInput name="amount" required />
      <FileUpload name="attachment" required accept=".pdf,.jpg,.png" />
    </>
  )}

  {expense_type === 'mileage' && (
    <>
      <NumberInput name="distance_km" label="Distância (km)" required />
      <CurrencyInput 
        name="rate_per_km" 
        label="Taxa por km" 
        value={0.36} 
        disabled 
        helperText="Taxa definida pela empresa"
      />
      <Select name="vehicle_type">
        <Option value="car">🚗 Carro</Option>
        <Option value="motorcycle">🏍️ Moto</Option>
      </Select>
      <CurrencyInput 
        name="amount" 
        label="Valor Total" 
        disabled 
        value={distance_km * rate_per_km}
        helperText="Calculado automaticamente"
      />
    </>
  )}

  <TextField name="description" multiline rows={3} required />
</ExpenseForm>
```

### **2. Página Manager - "Expense Approvals"**

**Layout:**
```
┌────────────────────────────────────────────────┐
│ Expense Approvals          [🔍 Filter] [Admin] │
├────────────────────────────────────────────────┤
│ Tabs: [SUBMITTED] [FINANCE_REVIEW] [ALL]       │
├────────────────────────────────────────────────┤
│ ┌──────────────────────────────────────────┐   │
│ │ DataGrid (NÃO SELECCIONÁVEL)             │   │
│ │ - Date                                   │   │
│ │ - Technician                             │   │
│ │ - Type (Badge: Reimbursement/Mileage)   │   │
│ │ - Category                               │   │
│ │ - Amount                                 │   │
│ │ - Status                                 │   │
│ │ - Actions: [👁️ View]                     │   │
│ └──────────────────────────────────────────┘   │
└────────────────────────────────────────────────┘
```

**Dialog de Visualização (Read-Only + Ações):**
```tsx
<ExpenseViewDialog>
  <DialogTitle>
    Expense Details - {expense.technician.name}
    <Chip label={expense.expense_type} />
    <Chip label={expense.status} color={statusColor} />
  </DialogTitle>

  <DialogContent>
    {/* Informações */}
    <Grid container>
      <Grid item xs={6}>
        <Typography>Date: {expense.date}</Typography>
        <Typography>Project: {expense.project.name}</Typography>
        <Typography>Type: {expense.expense_type}</Typography>
        
        {expense.isMileage() && (
          <>
            <Typography>Distance: {expense.distance_km} km</Typography>
            <Typography>Rate: €{expense.rate_per_km}/km</Typography>
            <Typography>Vehicle: {expense.vehicle_type}</Typography>
          </>
        )}
        
        {expense.isReimbursement() && (
          <Typography>Category: {expense.category}</Typography>
        )}
      </Grid>
      
      <Grid item xs={6}>
        <Typography variant="h6">
          Amount: €{expense.amount}
        </Typography>
        <Typography>
          Description: {expense.description}
        </Typography>
      </Grid>
    </Grid>

    {/* Preview de Recibo */}
    {expense.hasAttachment() && (
      <Card sx={{ mt: 2 }}>
        <CardHeader title="📎 Attached Receipt" />
        <CardContent>
          {expense.attachment_path.endsWith('.pdf') ? (
            <embed 
              src={expense.getAttachmentUrl()} 
              width="100%" 
              height="600px"
            />
          ) : (
            <img 
              src={expense.getAttachmentUrl()} 
              alt="Receipt"
              style={{ maxWidth: '100%' }}
            />
          )}
        </CardContent>
        <CardActions>
          <Button 
            startIcon={<Download />}
            href={expense.getAttachmentUrl()}
            download
          >
            Download Receipt
          </Button>
        </CardActions>
      </Card>
    )}
  </DialogContent>

  <DialogActions>
    {expense.status === 'submitted' && (
      <>
        <Button 
          color="error" 
          startIcon={<Close />}
          onClick={() => setRejectDialogOpen(true)}
        >
          Reject
        </Button>
        <Button 
          color="success" 
          startIcon={<Check />}
          onClick={handleApprove}
        >
          Approve → Send to Finance
        </Button>
      </>
    )}
    
    <Button onClick={onClose}>Close</Button>
  </DialogActions>
</ExpenseViewDialog>
```

**Características Importantes:**
- ❌ **SEM aprovação em massa** - cada despesa deve ser vista individualmente
- ✅ **Preview de recibo obrigatório** antes de aprovar
- ✅ **Workflow claro**: Approve → Vai direto para Finance Review
- ✅ **Reject**: Volta para draft com motivo

### **3. Página Finance - "Finance Review"**

Similar ao Manager, mas com:
- Tab adicional: `FINANCE_APPROVED` (aguardando pagamento)
- Ações diferentes:
  - `finance_review`: Approve, Reject
  - `finance_approved`: Mark as Paid

**Dialog adicional - Mark as Paid:**
```tsx
<Dialog>
  <DialogTitle>Mark Expense as Paid</DialogTitle>
  <DialogContent>
    <TextField 
      label="Payment Reference" 
      name="payment_reference"
      placeholder="TRX-2025-11-001"
      required
    />
    <TextField 
      label="Finance Notes (Optional)"
      name="finance_notes"
      multiline
      rows={2}
    />
  </DialogContent>
  <DialogActions>
    <Button onClick={handleMarkAsPaid} color="primary">
      Confirm Payment
    </Button>
  </DialogActions>
</Dialog>
```

## 🚀 Próximos Passos de Implementação

1. ✅ **Backend** (Concluído):
   - [x] Migrations com novos campos
   - [x] Model atualizado com métodos de workflow
   - [x] Permissões Finance criadas

2. **Backend - Controllers e Routes** (Próximo):
   - [ ] Atualizar ExpenseController com novos endpoints
   - [ ] Adicionar rotas Finance
   - [ ] Implementar Policies para Finance

3. **Frontend - Types** (Depois):
   - [ ] Atualizar tipos TypeScript
   - [ ] Criar enums para expense_type, vehicle_type, etc

4. **Frontend - Components** (Final):
   - [ ] Refatorar ExpenseManager
   - [ ] Criar FinanceReviewPage
   - [ ] Implementar ExpenseViewDialog com preview
   - [ ] Formulário condicional por tipo

**Vamos implementar os controllers e routes agora?** 🎯
