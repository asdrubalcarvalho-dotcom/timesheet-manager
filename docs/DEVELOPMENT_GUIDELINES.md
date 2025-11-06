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