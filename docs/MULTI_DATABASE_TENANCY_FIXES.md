# 🔧 Multi-Database Tenancy - Correções Implementadas

**Data**: 11 de Novembro de 2025  
**Status**: ✅ 3/3 Testes Passando  
**Autor**: TimePerk Development Team

---

## 📋 Sumário Executivo

Este documento descreve as correções críticas implementadas para corrigir a arquitetura **multi-database tenancy** do TimePerk Cortex. O sistema agora funciona corretamente com bancos de dados isolados por tenant (`timesheet_{ULID}`), sem dependências de colunas `tenant_id` ou foreign keys para tabelas centrais.

### Resultados
- ✅ **3/3 testes PHPUnit passando** (30 assertions)
- ✅ **Registro de tenant via API funcionando**
- ✅ **Migrations executadas corretamente em cada tenant DB**
- ✅ **Admin user criado com role e token Sanctum**

---

## 🎯 Problema Identificado

### Arquitetura Conflitante
O código estava misturando padrões de **single-database tenancy** (com `tenant_id` FK) e **multi-database tenancy** (bancos isolados):

```
❌ ANTES:
- User model com trait BelongsToTenant (single-DB)
- Spatie Permission com teams=true (cria FK tenant_id)
- Seeder usando whereNotNull('tenant_id')
- Migrations de tenant sem tabelas essenciais (cache, tokens)

✅ DEPOIS:
- Cada tenant = banco isolado (timesheet_{ULID})
- Sem colunas tenant_id nas tabelas tenant
- Spatie Permission teams=false
- Migrations completas no tenant folder
```

---

## 🔧 Correções Implementadas

### 1. **Configuração Spatie Permission** (CRÍTICO)

**Arquivo**: `backend/config/permission.php`

```php
// BEFORE
'teams' => true,

// AFTER
'teams' => false,  // Multi-DB tenancy doesn't need tenant_id FK
```

**Motivo**: Em multi-database tenancy, a tabela `tenants` está no banco central, mas `roles` e `permissions` estão no banco tenant. A FK `tenant_id` geraria erro `1146 Table 'tenants' doesn't exist`.

**Impacto**: Elimina criação de FK constraints inválidos na migration `create_permission_tables.php`.

---

### 2. **Modelo User** (Remoção de Traits Single-DB)

**Arquivo**: `backend/app/Models/User.php`

```php
// REMOVED
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles, BelongsToTenant; // ❌

    protected $fillable = [
        'tenant_id',  // ❌
        'name',
        'email',
        'password',
    ];
    
    public function tenant(): BelongsTo  // ❌
    {
        return $this->belongsTo(Tenant::class);
    }
}

// CORRECTED
class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles;  // ✅

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];
    
    // tenant() method removed - no FK to central DB
}
```

**Motivo**: `BelongsToTenant` assume coluna `tenant_id` e FK para tabela central. Em multi-DB, cada tenant DB tem suas próprias `users` sem FK.

---

### 3. **TenantController** (Criação de Admin User)

**Arquivo**: `backend/app/Http/Controllers/Api/TenantController.php`

```php
// BEFORE
$admin = User::create([
    'name' => $request->admin_name,
    'email' => $request->admin_email,
    'password' => Hash::make($request->admin_password),
    'tenant_id' => $tenant->id,  // ❌ Coluna não existe
]);

// AFTER
$admin = User::create([
    'name' => $request->admin_name,
    'email' => $request->admin_email,
    'password' => Hash::make($request->admin_password),
    'email_verified_at' => now(),  // ✅
]);

// ADDED
$baseDomain = config('app.domain', 'localhost:3000');  // Fix undefined variable
```

**Mudanças**:
1. Removido `'tenant_id' => $tenant->id` (coluna inexistente)
2. Adicionado `'email_verified_at' => now()` para evitar verificação de email
3. Definido `$baseDomain` antes de uso na resposta JSON

---

### 4. **RolesAndPermissionsSeeder** (Context Awareness)

**Arquivo**: `backend/database/seeders/RolesAndPermissionsSeeder.php`

```php
// BEFORE
protected function assignUsersToTenantRoles(): void
{
    User::query()
        ->whereNotNull('tenant_id')  // ❌ Coluna não existe
        ->get()
        ->each(function (User $user): void {
            $this->registrar->setPermissionsTeamId($user->tenant_id);  // ❌
            
            $roleName = match ($user->role) {
                'Manager' => 'Manager',
                'Admin' => 'Admin',
                default => 'Technician',
            };

            $user->syncRoles([$roleName]);
        });

    $this->registrar->setPermissionsTeamId(null);  // ❌
}

// AFTER
protected function assignUsersToTenantRoles(): void
{
    // In multi-database tenancy, users table exists in each tenant database
    // No tenant_id column needed - context is implicit
    User::query()  // ✅ All users in current tenant DB
        ->get()
        ->each(function (User $user): void {
            // No need to set team ID in multi-database tenancy
            // Each tenant has separate database with separate roles

            $roleName = match ($user->role) {
                'Manager' => 'Manager',
                'Admin' => 'Admin',
                default => 'Technician',
            };

            $user->syncRoles([$roleName]);
        });
}
```

**Motivo**: Em multi-DB, quando `$tenant->run(closure)` é executado, o contexto já está no banco tenant. Não precisamos filtrar por `tenant_id` ou configurar `team_id`.

---

### 5. **Migrations Copiadas para Tenant Folder**

**Tabelas essenciais movidas para `backend/database/migrations/tenant/`:**

1. **`0001_01_01_000001_create_cache_table.php`**
   - **Por quê**: Spatie Permission executa `app('cache')->forget()` após migration
   - **Erro sem ela**: `1146 Table 'timesheet_{id}.cache' doesn't exist`

2. **`2025_11_03_224601_create_personal_access_tokens_table.php`**
   - **Por quê**: Laravel Sanctum precisa armazenar tokens no banco tenant
   - **Erro sem ela**: `1146 Table 'timesheet_{id}.personal_access_tokens' doesn't exist`

**Comando de cópia**:
```bash
cp backend/database/migrations/0001_01_01_000001_create_cache_table.php backend/database/migrations/tenant/
cp backend/database/migrations/2025_11_03_224601_create_personal_access_tokens_table.php backend/database/migrations/tenant/
```

---

### 6. **TenantOnboardingTest** (Adaptação para ULID)

**Arquivo**: `backend/tests/Feature/TenantOnboardingTest.php`

```php
// BEFORE
$response->assertStatus(201)->assertJsonStructure([
    'status', 'tenant', 'database', 'admin_token',  // ❌ Estrutura desatualizada
]);

$this->assertDatabaseHas('tenants', ['id' => $this->tenantSlug]);  // ❌ id != slug

Tenancy::initialize(Tenant::find($this->tenantSlug));  // ❌ find() usa id, não slug

// AFTER
$response->assertStatus(201)->assertJsonStructure([
    'status',
    'message',
    'tenant',
    'database',
    'tenant_info' => ['id', 'slug', 'name', 'domain', 'status', 'trial_ends_at'],
    'admin' => ['email', 'token'],  // ✅ Estrutura correta
    'next_steps' => ['login_url', 'api_header'],
]);

$this->assertDatabaseHas('tenants', ['slug' => $this->tenantSlug]);  // ✅

$tenant = Tenant::where('slug', $this->tenantSlug)->first();  // ✅
$actualTenantDb = 'timesheet_' . $tenant->id;  // ✅ Usa ULID real

Tenancy::initialize($tenant);  // ✅
```

**Mudanças**:
1. JSON structure atualizada para corresponder ao controller
2. Busca por `slug` em vez de `id` (ULID é gerado, não igual ao slug)
3. Nome do banco usa `$tenant->id` ULID em vez de assumir slug

---

## 📊 Estrutura de Migrations

### Central Database (`backend/database/migrations/`)
```
migrations/
├── 0001_01_01_000000_create_users_table.php       # ❌ NÃO USADO (versão central vazia)
├── 0001_01_01_000001_create_cache_table.php       # ✅ Também copiado para tenant/
├── 2025_11_03_224601_create_personal_access_tokens_table.php  # ✅ Também copiado
├── 2025_11_05_104400_create_tenants_table.php     # ✅ CENTRAL (tabela tenants)
└── 2025_11_05_104500_create_domains_table.php     # ✅ CENTRAL (tabela domains)
```

### Tenant Database (`backend/database/migrations/tenant/`)
```
tenant/
├── 0001_01_01_000001_create_cache_table.php                          # ✅ ADICIONADO
├── 2025_11_03_224601_create_personal_access_tokens_table.php         # ✅ ADICIONADO
├── 2025_11_05_130000_create_users_table.php                          # ✅ TENANT
├── 2025_11_06_141003_create_permission_tables.php                    # ✅ TENANT (teams=false)
├── 2025_11_06_150001_create_technicians_table.php                    # ✅ TENANT
├── 2025_11_06_150002_create_projects_table.php                       # ✅ TENANT
├── 2025_11_06_150003_create_tasks_table.php                          # ✅ TENANT
├── 2025_11_06_150004_create_locations_table.php                      # ✅ TENANT
├── 2025_11_06_150005_create_timesheets_table.php                     # ✅ TENANT
├── 2025_11_06_150006_create_expenses_table.php                       # ✅ TENANT
└── ... (37 migrations totais)
```

**SKIP Migrations** (movidas para raiz com prefixo `SKIP_`):
```
migrations/SKIP_2025_11_12_131100_add_tenant_id_to_core_tables.php    # ❌ Single-DB pattern
migrations/SKIP_2025_11_12_132500_enable_permission_teams.php         # ❌ teams=true incompatível
```

---

## 🧪 Validação de Testes

### Comando de Execução
```bash
docker exec -it timesheet_app php artisan test --filter=TenantOnboardingTest
```

### Resultados
```
✅ PASS  Tests\Feature\TenantOnboardingTest
  ✓ it registers a tenant and creates their database           13.51s  
  ✓ it rejects reserved slugs                                   0.24s  
  ✓ check slug endpoint returns availability                    0.23s  

Tests:    3 passed (30 assertions)
Duration: 14.50s
```

### Exemplo de Registro Via API
```bash
curl -X POST http://localhost:8080/api/tenants/register \
  -H 'Content-Type: application/json' \
  -d '{
    "company_name": "Test Corporation",
    "slug": "testcorp",
    "admin_name": "John Doe",
    "admin_email": "admin@testcorp.com",
    "admin_password": "secret123",
    "admin_password_confirmation": "secret123"
  }'
```

**Resposta**:
```json
{
  "status": "ok",
  "message": "Tenant created successfully",
  "tenant": "testcorp",
  "database": "timesheet_01K9TH3K99NZTWC58R9SECKQHP",
  "tenant_info": {
    "id": "01K9TH3K99NZTWC58R9SECKQHP",
    "slug": "testcorp",
    "name": "Test Corporation",
    "domain": "testcorp.localhost:3000",
    "status": "active",
    "trial_ends_at": "2025-11-25T22:38:12.000000Z"
  },
  "admin": {
    "email": "admin@testcorp.com",
    "token": "1|LLNlhLuBWlIGW3OQQtUM8frjq5sI6lepbbh1j9d5a067656f"
  },
  "next_steps": {
    "login_url": "http://testcorp.localhost:3000/login",
    "api_header": "X-Tenant: testcorp"
  }
}
```

### Verificação do Banco de Dados
```bash
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "
  SHOW DATABASES LIKE 'timesheet_%';
  USE timesheet_01K9TH3K99NZTWC58R9SECKQHP;
  SELECT id, name, email FROM users;
  SELECT name FROM roles;
"
```

**Resultado**:
```
+--------------------------------------+
| Database (timesheet_%)               |
+--------------------------------------+
| timesheet_01K9TH3K99NZTWC58R9SECKQHP |
+--------------------------------------+

+----+----------+--------------------+
| id | name     | email              |
+----+----------+--------------------+
|  1 | John Doe | admin@testcorp.com |
+----+----------+--------------------+

+------------+
| name       |
+------------+
| Admin      |
| Manager    |
| Technician |
+------------+
```

---

## 📚 Conceitos-Chave

### Multi-Database Tenancy vs Single-Database
| Aspecto | Single-Database | Multi-Database |
|---------|----------------|----------------|
| **Estrutura** | 1 banco + `tenant_id` em cada tabela | 1 banco central + N bancos tenant |
| **Isolamento** | Row-level (WHERE tenant_id = X) | Database-level (USE timesheet_X) |
| **Trait** | `BelongsToTenant` | Sem trait (contexto implícito) |
| **Spatie teams** | `teams = true` | `teams = false` |
| **FK constraints** | FK para tenants table | Sem FK para central DB |
| **Migrations** | Executam no banco principal | Executam em cada tenant DB |
| **Vantagens** | Simples, menos overhead | Isolamento total, backup independente |
| **Desvantagens** | Risco de data leakage | Overhead de manutenção |

### Fluxo de Registro de Tenant
```
1. POST /api/tenants/register
   ↓
2. Create Tenant record (central DB: tenants table)
   ↓
3. Create Domain record (central DB: domains table)
   ↓
4. CREATE DATABASE timesheet_{ULID}
   ↓
5. Run migrations in tenant DB (37 migrations)
   ↓
6. Seed roles/permissions (Admin, Manager, Technician)
   ↓
7. Create admin User in tenant DB
   ↓
8. Assign 'Admin' role to user
   ↓
9. Generate Sanctum token
   ↓
10. Return JSON with tenant_info + admin.token
```

---

## ⚠️ Gotchas & Armadilhas

### 1. **Não Misturar Single-DB e Multi-DB Patterns**
```php
// ❌ ERRADO - BelongsToTenant em multi-DB
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class User extends Model
{
    use BelongsToTenant;  // Adiciona tenant_id e FK
}

// ✅ CORRETO - Sem trait em multi-DB
class User extends Model
{
    // Tenant context vem de $tenant->run(closure)
}
```

### 2. **Spatie Permission Teams**
```php
// ❌ ERRADO - teams=true em multi-DB
'teams' => true,  // Cria FK roles.tenant_id → tenants.id (erro!)

// ✅ CORRETO - teams=false
'teams' => false,  // Sem FK, cada tenant DB tem próprias roles
```

### 3. **Tenant Context em Seeders**
```php
// ❌ ERRADO - Filtrar por tenant_id
User::whereNotNull('tenant_id')->get();

// ✅ CORRETO - Todos users já estão no tenant DB
User::all();  // Contexto implícito via $tenant->run()
```

### 4. **Migrations Essenciais**
```bash
# ❌ ERRADO - Esquecer tabelas de sistema
# Resultado: "Table 'cache' doesn't exist" no fim da migration

# ✅ CORRETO - Copiar todas dependências
cp migrations/cache_table.php migrations/tenant/
cp migrations/personal_access_tokens_table.php migrations/tenant/
```

### 5. **Testes com ULID**
```php
// ❌ ERRADO - Assumir id = slug
Tenant::find($slug);  // find() usa primary key (ULID)

// ✅ CORRETO - Buscar por slug
Tenant::where('slug', $slug)->first();
```

---

## 🔗 Referências

### Documentação Oficial
- [Stancl/Tenancy Multi-Database](https://tenancyforlaravel.com/docs/v3/database-multi-database)
- [Spatie Laravel-Permission Teams](https://spatie.be/docs/laravel-permission/v6/basic-usage/teams-permissions)
- [Laravel Sanctum](https://laravel.com/docs/11.x/sanctum)

### Arquivos Modificados
1. `backend/config/permission.php` - teams=false
2. `backend/app/Models/User.php` - Removido BelongsToTenant
3. `backend/app/Http/Controllers/Api/TenantController.php` - Removido tenant_id
4. `backend/database/seeders/RolesAndPermissionsSeeder.php` - Removido filtro tenant_id
5. `backend/tests/Feature/TenantOnboardingTest.php` - Adaptado para ULID
6. `backend/database/migrations/tenant/` - Adicionadas cache + tokens tables

### Comandos Úteis
```bash
# Rebuild config cache
docker exec -it timesheet_app php artisan config:cache

# Run tenant migrations manually
docker exec -it timesheet_app php artisan migrate --path=database/migrations/tenant --force

# Clean test databases
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "
  DROP DATABASE IF EXISTS timesheet_qatest1234567890;
  DELETE FROM timesheet.tenants WHERE slug LIKE 'qatest%';
  DELETE FROM timesheet.domains WHERE domain LIKE 'qatest%';
"

# List all tenant databases
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "SHOW DATABASES LIKE 'timesheet_%';"
```

---

## ✅ Checklist de Implementação

Ao implementar multi-database tenancy em novos projetos:

- [ ] Configurar `config/tenancy.php` com `template_tenant_connection => 'mysql'`
- [ ] Configurar `config/permission.php` com `teams => false`
- [ ] **NÃO** adicionar trait `BelongsToTenant` aos models tenant
- [ ] **NÃO** adicionar coluna `tenant_id` em migrations tenant
- [ ] Copiar migrations essenciais para `database/migrations/tenant/`:
  - [ ] `create_cache_table.php`
  - [ ] `create_personal_access_tokens_table.php`
  - [ ] Todas migrations de negócio (users, projects, etc.)
- [ ] Seeders devem assumir contexto implícito (sem `whereNotNull('tenant_id')`)
- [ ] Testes devem buscar tenant por `slug`, não `id`
- [ ] Verificar `$baseDomain` definido antes de uso em controllers
- [ ] Validar migrations executam sem erros de FK constraints

---

**Status**: ✅ **Implementação Completa e Validada**  
**Última Atualização**: 11 de Novembro de 2025  
**Testes**: 3/3 Passando (30 assertions)
