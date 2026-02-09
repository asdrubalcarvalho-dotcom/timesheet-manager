# 📋 Development Guidelines: TimePerk Cortex
## Evitando Erros Comuns e Mantendo Consistência

### 🚨 **Problemas Comuns Identificados**

#### **1. ❌ Campos Duplicados em Models**
**Problema**: Adição de campos já existentes no `fillable` array
**Exemplo**: Campo `status` duplicado em `app/Models/Timesheet.php`

**✅ Solução**:
- Sempre verificar o array `$fillable` existente antes de adicionar campos
- Usar `grep` para procurar campos existentes: `grep -r "status" app/Models/`
- Comando de verificação: `php artisan model:show Timesheet`

#### **2. ❌ Validação Inconsistente Frontend vs Backend**
**Problema**: Frontend permite valores que backend rejeita
**Exemplo**: `task_id` e `location_id` opcionais no frontend mas obrigatórios no backend

**✅ Solução**:
- Manter validação sincronizada entre frontend e backend
- Documentar campos obrigatórios em ambos os lados
- Testar APIs com dados inválidos

#### **3. ❌ Foreign Key Constraints Problemáticas**
**Problema**: Tentativa de tornar campos NOT NULL com constraints SET NULL
**Exemplo**: Migration falhou por conflito entre NOT NULL e SET NULL constraint

**✅ Solução**:
- Sempre considerar foreign key constraints ao modificar colunas
- Padrão para campos obrigatórios: `onDelete('restrict')`
- Padrão para campos opcionais: `onDelete('set null')`

---

### 🛠️ **Padrões de Desenvolvimento**

#### **📁 Database Schema Standards**

```php
// ✅ CORRETO: Campos obrigatórios
Schema::table('timesheets', function (Blueprint $table) {
    $table->foreignId('task_id')->constrained()->onDelete('restrict');
    $table->foreignId('location_id')->constrained()->onDelete('restrict');
});

// ✅ CORRETO: Campos opcionais  
Schema::table('expenses', function (Blueprint $table) {
    $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null');
});

// ❌ INCORRETO: Conflito entre nullable e constraint
$table->foreignId('task_id')->nullable()->constrained()->onDelete('restrict');
```

#### **🔍 Validation Patterns**

```php
// ✅ BACKEND: TimesheetController.php
$validated = $request->validate([
    'project_id' => 'required|exists:projects,id',
    'task_id' => 'required|exists:tasks,id',        // Obrigatório
    'location_id' => 'required|exists:locations,id', // Obrigatório
    'hours_worked' => 'required|numeric|min:0.25|max:24',
]);

// ✅ FRONTEND: TimesheetCalendar.tsx
const handleSave = async () => {
    if (!projectId) return setError('Please select a project');
    if (!taskId) return setError('Please select a task');       // Obrigatório
    if (!locationId) return setError('Please select a location'); // Obrigatório
};
```

#### **⚛️ Frontend Component Standards**

```tsx
// ✅ CORRETO: Campos obrigatórios sem opção "None"
<TextField select label="Task *" required>
    <MenuItem value={0}>Select a task</MenuItem>
    {tasks.map((task) => (
        <MenuItem key={task.id} value={task.id}>{task.name}</MenuItem>
    ))}
</TextField>

// ❌ INCORRETO: Campo obrigatório com opção "None"
<TextField select label="Task">
    <MenuItem value={0}>No specific task</MenuItem> {/* ❌ Não usar para campos obrigatórios */}
</TextField>
```

---

### 📝 **Checklist de Desenvolvimento**

#### **🔄 Ao Adicionar Novos Campos**

```bash
# 1. Verificar se campo já existe
grep -r "campo_nome" app/Models/
grep -r "campo_nome" database/migrations/

# 2. Verificar validação existente
grep -r "campo_nome" app/Http/Controllers/

# 3. Testar migrations em ambiente limpo
php artisan migrate:fresh --seed
```

#### **🧪 Ao Modificar Validações**

```bash
# 1. Sincronizar backend e frontend
- Atualizar Controller validation rules
- Atualizar frontend form validation  
- Atualizar TypeScript interfaces

# 2. Testar com dados válidos e inválidos
curl -X POST /api/timesheets -d '{"project_id": null}' # Deve falhar
curl -X POST /api/timesheets -d '{"task_id": null}'    # Deve falhar
```

#### **🗄️ Ao Modificar Database Schema**

```bash
# 1. Considerar dados existentes
php artisan tinker
>>> Model::whereNull('campo_novo')->count(); // Verificar impacto

# 2. Considerar foreign key constraints
SHOW CREATE TABLE tabela_nome; // Ver constraints existentes

# 3. Testar rollback
php artisan migrate:rollback --step=1
php artisan migrate
```

---

### 🔧 **Ferramentas de Verificação**

#### **📊 Commands Úteis**

```bash
# Verificar estrutura de models
php artisan model:show Timesheet
php artisan model:show Expense

# Verificar migrations pendentes
php artisan migrate:status

# Verificar foreign keys
docker-compose exec database mysql -u root -p -e "
SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE 
WHERE REFERENCED_TABLE_SCHEMA = 'timesheet_db';"

# Verificar dados inconsistentes  
php artisan tinker --execute="
\$nullTasks = App\Models\Timesheet::whereNull('task_id')->count();
\$nullLocations = App\Models\Timesheet::whereNull('location_id')->count();
echo \"Null task_id: \$nullTasks, Null location_id: \$nullLocations\";
"
```

#### **🎯 Testing Patterns**

```php
// ✅ CORRETO: Test para campos obrigatórios
public function test_timesheet_requires_task_and_location()
{
    $response = $this->postJson('/api/timesheets', [
        'project_id' => 1,
        // task_id missing - deve falhar
        'location_id' => 1,
        'hours_worked' => 8,
    ]);
    
    $response->assertStatus(422)
             ->assertJsonValidationErrors(['task_id']);
}
```

---

### 📚 **Documentação de Estado Atual**

#### **🏗️ Arquitectura Confirmada**

- **Timesheets**: `task_id` e `location_id` são **OBRIGATÓRIOS**
- **Expenses**: Apenas `project_id` é obrigatório (sem task_id/location_id)
- **Tasks**: Sempre associadas a um projeto específico
- **Locations**: Globais, usadas em qualquer projeto

#### **🔗 API Endpoints Funcionais**

```bash
# ✅ Testados e funcionando
GET /api/tasks                    # Todas as tasks
GET /api/tasks?project_id=1       # Tasks do projeto 1
GET /api/projects/1/tasks         # Tasks do projeto 1 (alternativo)
GET /api/locations                # Todas as locations  
GET /api/locations/active         # Apenas locations ativas
```

#### **💾 Database Constraints**

```sql
-- Timesheets: task_id e location_id são NOT NULL
ALTER TABLE timesheets MODIFY task_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE timesheets MODIFY location_id BIGINT UNSIGNED NOT NULL;

-- Foreign Keys: RESTRICT (não permite delete se referenciado)
CONSTRAINT timesheets_task_id_foreign 
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE RESTRICT;

CONSTRAINT timesheets_location_id_foreign 
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT;
```

---

### 🚀 **Implementação de Funcionalidades Futuras**

#### **1. 🧠 Smart Auto-Complete (AI Features)**
- **Campos obrigatórios**: Sempre sugerir task_id e location_id
- **Padrões**: Usar tasks mais frequentes por projeto
- **Validação**: Nunca permitir null para campos obrigatórios

#### **2. 📊 Relatórios e Analytics**
- **Filtros**: Por projeto → tasks automaticamente filtradas  
- **KPIs**: Tempo por task_type, produtividade por location
- **Dashboards**: Sempre exibir task e location name (nunca null)

#### **3. 🔄 Import/Export**
- **CSV Import**: Validar task_id e location_id obrigatórios
- **Excel Export**: Incluir task.name e location.name sempre
- **Backup**: Manter integridade referencial

---

### ✅ **Summary: Estado Atual Corrigido**

1. **✅ Database**: task_id e location_id são NOT NULL em timesheets
2. **✅ Backend**: Validação obrigatória para task_id e location_id  
3. **✅ Frontend**: Campos marcados como required, sem opções "None"
4. **✅ API**: Endpoints funcionais para tasks por projeto
5. **✅ Data**: Entries existentes preenchidas com valores padrão

**🎯 Próximo passo**: Implementar Smart Auto-Complete seguindo estes padrões!

---

*Este documento deve ser atualizado sempre que novos padrões forem estabelecidos ou problemas identificados.*

**Versão**: 1.0  
**Data**: 5 Nov 2025  
**Status**: Padrões implementados e testados ✅
# 📋 Development Guidelines: TimePerk Cortex
## Avoid common mistakes and keep the codebase consistent

This document captures the **real issues we’ve already hit** in this repo and the **patterns we want to enforce**.

---

## ✅ MUST (Non-negotiable)

1) **Always follow tenancy boundaries**
- Central routes: `backend/routes/api.php`
- Tenant routes: `backend/routes/tenant.php`
- Multi-tenant DBs:
  - Central: `timesheet`
  - Tenant: `timesheet_{slug}` (e.g., `timesheet_test-company`)
- Tenant resolved by **subdomain** or **`X-Tenant`** header.
- Avoid `Model::on($connection)` in controllers.
- Raw queries must use the correct connection explicitly:
  - `DB::connection('tenant')` for tenant DB
  - `DB::connection('central')` only when truly central

2) **Keep frontend + backend validation aligned**
If frontend allows something that backend rejects, you create noisy bugs.

3) **Respect FK constraints when changing schema**
Don’t create contradictions between column nullability and FK delete rules.

4) **Never duplicate fields in Models (fillable/casts/etc.)**
Always check before adding.

---

## 👍 SHOULD (Preferred patterns)

- Prefer **Form Requests** in `backend/app/Http/Requests/` over inline `validate()`.
- Keep controllers thin; move business logic to Services.
- When rules change, add/update tests.

---

# 🚨 Common issues we’ve seen

## 1) ❌ Duplicate fields in Models
**Problem:** Adding fields that already exist in `$fillable`, `$casts`, etc.

✅ **Fix / Prevention**
- Always inspect the model before editing.
- Search before adding fields.

**Search commands (repo-correct paths):**
```bash
# Models
grep -R "status" backend/app/Models
grep -R "status" Modules

# Migrations
grep -R "status" backend/database/migrations
grep -R "status" Modules/*/Database/migrations

# Helpful overview
docker-compose exec app php artisan model:show Timesheet
```

---

## 2) ❌ Inconsistent validation (frontend vs backend)
**Problem:** Frontend allows values that backend rejects.

✅ **Fix / Prevention**
- Keep validation synchronized on both sides.
- Document required fields clearly.
- Test APIs with invalid payloads.

### Backend example (Laravel)
```php
// ✅ BACKEND: example validation rules
$validated = $request->validate([
    'project_id' => 'required|exists:projects,id',
    'task_id' => 'required|exists:tasks,id',          // required
    'location_id' => 'required|exists:locations,id',  // required
    'hours_worked' => 'required|numeric|min:0.25|max:24',
]);
```

### Frontend example (React)
```tsx
// ✅ FRONTEND: enforce same constraints
const handleSave = async () => {
  if (!projectId) return setError('Please select a project');
  if (!taskId) return setError('Please select a task');
  if (!locationId) return setError('Please select a location');
};
```

---

## 3) ❌ Foreign Key constraints that contradict nullability
**Problem:** Making a column NOT NULL while the FK uses `SET NULL` on delete.

✅ **Correct patterns**
```php
// ✅ REQUIRED field -> restrict delete
Schema::table('timesheets', function (Blueprint $table) {
    $table->foreignId('task_id')->constrained()->onDelete('restrict');
    $table->foreignId('location_id')->constrained()->onDelete('restrict');
});

// ✅ OPTIONAL field -> nullable + set null
Schema::table('expenses', function (Blueprint $table) {
    $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null');
});

// ❌ WRONG: nullable but restrict (usually signals design mismatch)
$table->foreignId('task_id')->nullable()->constrained()->onDelete('restrict');
```

---

# 🧭 Tenancy-aware changes (VERY IMPORTANT)

## Central vs tenant migrations
- Central migrations:
```bash
docker-compose exec app php artisan migrate
```
- Tenant migrations:
```bash
docker-compose exec app php artisan tenants:migrate <slug>
```

✅ Rule: if a table lives in tenant DB, schema changes must be done via **tenant migration**, not central.

## Where are auth tokens stored?
If tokens are tenant-scoped, check the **tenant** DB:
```bash
docker-compose exec database mysql -u timesheet -psecret -e \
"USE timesheet_<slug>; SELECT * FROM personal_access_tokens LIMIT 20;"
```

---

# 🧠 AI Timesheet Builder API

## Endpoints (tenant-scoped, auth required)

### Preview
`POST /api/ai/timesheet/preview`

Payload:
```json
{
  "prompt": "Create timesheet entries for 5 days (Mon-Fri), 09:00-18:00, lunch 12:30-13:30, project ACME, task Installation."
}
```

Response:
```json
{
  "plan": {
    "range": { "start_date": "2026-02-03", "end_date": "2026-02-07" },
    "timezone": "Europe/Lisbon",
    "days": [
      {
        "date": "2026-02-03",
        "work_blocks": [
          {
            "start_time": "09:00",
            "end_time": "18:00",
            "project": { "id": 1, "name": "ACME" },
            "task": { "id": 10, "name": "Installation" },
            "location": { "id": 3, "name": "Lisbon" }
          }
        ],
        "breaks": [{ "start_time": "12:30", "end_time": "13:30" }]
      }
    ]
  },
  "warnings": []
}
```

### Commit
`POST /api/ai/timesheet/commit`

Payload:
```json
{
  "request_id": "uuid",
  "confirmed": true,
  "plan": { "range": { "start_date": "2026-02-03", "end_date": "2026-02-07" }, "timezone": "Europe/Lisbon", "days": [] }
}
```

Response:
```json
{
  "created_ids": [1, 2, 3],
  "summary": {
    "created_count": 3,
    "totals": {
      "overall_minutes": 1440,
      "overall_hours": 24,
      "per_day": { "2026-02-03": { "minutes": 480, "hours": 8 } }
    }
  }
}
```

## Deprecated endpoints
The following routes remain temporarily for backwards compatibility:
- `POST /api/timesheets/ai/preview`
- `POST /api/timesheets/ai/apply`

Use the new `/api/ai/timesheet/*` endpoints for all new integrations.

---

# 📝 Development checklists

## When adding new fields
```bash
# 1) Check if it already exists
grep -R "field_name" backend/app/Models
grep -R "field_name" Modules
grep -R "field_name" backend/database/migrations
grep -R "field_name" Modules/*/Database/migrations

# 2) Check backend validation and usage
grep -R "field_name" backend/app/Http

# 3) Test migrations cleanly (careful: resets local DB)
docker-compose exec app php artisan migrate:fresh --seed
```

## When changing validation rules
- Update backend validation rules (Controller/Form Request)
- Update frontend form validation
- Update TypeScript types (if needed)

Smoke test invalid payloads:
```bash
curl -X POST http://api.localhost/api/timesheets -H "Accept: application/json" \
  -d '{"project_id": null}'

curl -X POST http://api.localhost/api/timesheets -H "Accept: application/json" \
  -d '{"task_id": null}'
```

## When changing DB schema
```bash
# 1) Consider existing data impact
docker-compose exec app php artisan tinker
# Then in tinker:
# Model::whereNull('new_field')->count();

# 2) Inspect existing foreign keys
docker-compose exec database mysql -u timesheet -psecret -e \
"USE timesheet; SHOW CREATE TABLE timesheets\G"

# 3) Test rollback
docker-compose exec app php artisan migrate:rollback --step=1
docker-compose exec app php artisan migrate
```

---

# 🔧 Verification tools

```bash
# Model structure
docker-compose exec app php artisan model:show Timesheet
docker-compose exec app php artisan model:show Expense

# Pending migrations
docker-compose exec app php artisan migrate:status

# Foreign key inventory (central DB example)
docker-compose exec database mysql -u timesheet -psecret -e "
SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_SCHEMA = 'timesheet'
  AND REFERENCED_TABLE_NAME IS NOT NULL;
"
```

---

# 🎯 Testing pattern example

```php
public function test_timesheet_requires_task_and_location()
{
    $response = $this->postJson('/api/timesheets', [
        'project_id' => 1,
        // task_id missing -> should fail
        'location_id' => 1,
        'hours_worked' => 8,
    ]);

    $response->assertStatus(422)
             ->assertJsonValidationErrors(['task_id']);
}
```

---

# 📚 Current confirmed state (as of 2025-11-05)

- **Timesheets**: `task_id` and `location_id` are **REQUIRED**
- **Expenses**: only `project_id` is required (no task/location requirement)
- **Tasks**: always associated with a specific project
- **Locations**: global; used across projects

### Working API endpoints
```bash
GET /api/tasks
GET /api/tasks?project_id=1
GET /api/projects/1/tasks
GET /api/locations
GET /api/locations/active
```

### DB constraints (illustrative)
```sql
-- Timesheets: task_id and location_id are NOT NULL
ALTER TABLE timesheets MODIFY task_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE timesheets MODIFY location_id BIGINT UNSIGNED NOT NULL;

-- Foreign Keys: RESTRICT
CONSTRAINT timesheets_task_id_foreign
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE RESTRICT;

CONSTRAINT timesheets_location_id_foreign
  FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT;
```

---

*Update this doc whenever we establish new standards or discover recurring issues.*

**Version**: 1.1  
**Date**: 2026-02-06  
**Status**: Updated for repo paths + tenancy clarity ✅