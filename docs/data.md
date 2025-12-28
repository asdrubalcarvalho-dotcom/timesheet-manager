# 📊 Associações do Tenant `upg2ai`

---

## 🗄️ Estrutura de Tabelas - Sistema de Associações

### 📊 Tabelas Principais

#### 1. **`users`** (Usuários do Sistema)
```sql
id              bigint (PK)
name            varchar(255)
email           varchar(255) UNIQUE
role            varchar(255) DEFAULT 'Technician'
password        varchar(255)
```
**Propósito**: Armazena todos os usuários do sistema (autenticação)

---

#### 2. **`technicians`** (Perfil de Trabalhador)
```sql
id              bigint (PK)
user_id         bigint (FK → users.id)
name            varchar(255)
email           varchar(255) UNIQUE
role            ENUM('technician','manager','owner')
hourly_rate     decimal(8,2)
is_active       tinyint(1)
worker_id       varchar(255) UNIQUE
```
**Propósito**: Extensão de `users` com dados profissionais  
**Relação**: 1:1 com `users` via `user_id`

---

#### 3. **`projects`** (Projetos)
```sql
id              bigint (PK)
name            varchar(255)
description     text
start_date      date
end_date        date
status          ENUM('planned','active','on_hold','completed')
manager_id      bigint (FK → users.id)
created_by      bigint (FK → users.id)
```
**Propósito**: Armazena projetos

---

#### 4. **`tasks`** (Tarefas por Projeto)
```sql
id              bigint (PK)
project_id      bigint (FK → projects.id)  ⭐
name            varchar(255)
description     text
task_type       ENUM(...)
estimated_hours decimal(8,2)
start_date      date
end_date        date
progress        tinyint (0-100)
dependencies    json
is_active       tinyint(1)
```
**Propósito**: Tarefas vinculadas a projetos  
**Relação**: N:1 com `projects` via `project_id`

---

### 🔗 Tabelas de Associação (Pivot/Junction)

#### 5. **`project_members`** ⭐⭐⭐ (TABELA CRÍTICA)
```sql
id              bigint (PK)
project_id      bigint (FK → projects.id)   ⭐
user_id         bigint (FK → users.id)      ⭐
project_role    ENUM('member','manager','none')
expense_role    ENUM('member','manager','none')
finance_role    ENUM('none','member','manager')
```
**Propósito**: **Associa workers a projetos com triple-role system**  
**Relação**: Many-to-Many entre `users` e `projects`  
**Chave Única**: `(project_id, user_id)` - um user por projeto apenas

**Esta é a tabela que permite:**
- ✅ Um worker estar em múltiplos projetos
- ✅ Um projeto ter múltiplos workers
- ✅ Cada worker ter 3 roles independentes por projeto

---

#### 6. **`timesheets`** (Registro de Trabalho)
```sql
id              bigint (PK)
technician_id   bigint (FK → technicians.id)  ⭐
project_id      bigint (FK → projects.id)     ⭐
task_id         bigint (FK → tasks.id)        ⭐
location_id     bigint (FK → locations.id)
date            date
start_time      time
end_time        time
hours_worked    decimal(5,2)
status          ENUM('draft','submitted','approved','rejected','closed')
description     text
```
**Propósito**: **Registra quem trabalhou em qual tarefa de qual projeto**  
**Relações Simultâneas**:
- N:1 com `technicians` (quem trabalhou)
- N:1 com `projects` (em qual projeto)
- N:1 com `tasks` (em qual tarefa)

---

### 🎯 Como as Associações Funcionam

#### **Workers ↔ Projects** (via `project_members`)
```sql
SELECT u.name, p.name, pm.project_role, pm.expense_role, pm.finance_role
FROM project_members pm
JOIN users u ON pm.user_id = u.id
JOIN projects p ON pm.project_id = p.id;
```
**Resultado**: Mostra quais workers estão em quais projetos e seus roles

---

#### **Projects ↔ Tasks** (via `project_id` em `tasks`)
```sql
SELECT p.name AS project, t.name AS task
FROM tasks t
JOIN projects p ON t.project_id = p.id;
```
**Resultado**: Mostra quais tarefas pertencem a cada projeto

---

#### **Workers ↔ Tasks ↔ Projects** (via `timesheets`)
```sql
SELECT 
    u.name AS worker,
    p.name AS project,
    t.name AS task,
    ts.hours_worked
FROM timesheets ts
JOIN technicians tech ON ts.technician_id = tech.id
JOIN users u ON tech.user_id = u.id
JOIN projects p ON ts.project_id = p.id
JOIN tasks tk ON ts.task_id = tk.id;
```
**Resultado**: Mostra quem trabalhou em qual tarefa de qual projeto

---

### 📐 Diagrama de Relacionamentos

```
┌─────────┐       ┌──────────────┐       ┌──────────┐
│  users  │◄──────┤ technicians  │       │ projects │
│   (PK)  │  1:1  │  (user_id)   │       │   (PK)   │
└────┬────┘       └──────────────┘       └────┬─────┘
     │                    │                    │
     │                    │                    │
     │            ┌───────┴────────┐          │
     │            │                 │          │
     └────────────┤ project_members ├──────────┘
         N        │   (user_id,     │    N
                  │   project_id)   │
                  └─────────────────┘
                  ⭐ PIVOT TABLE ⭐
                          │
                          │
                  ┌───────┴───────┐
                  │               │
         ┌────────┴────┐    ┌─────┴─────┐
         │  timesheets │    │   tasks   │
         │(tech_id,    │    │(project_id)│
         │ project_id, │    └───────────┘
         │ task_id)    │          
         └─────────────┘          
```

---

### 🔑 Resumo das Foreign Keys

| Tabela | Campo | Referência | Descrição |
|--------|-------|------------|-----------|
| **technicians** | `user_id` | `users.id` | Link entre user e perfil worker |
| **tasks** | `project_id` | `projects.id` | Tarefa pertence a projeto |
| **project_members** | `user_id` | `users.id` | Worker no projeto |
| **project_members** | `project_id` | `projects.id` | Projeto com workers |
| **timesheets** | `technician_id` | `technicians.id` | Quem trabalhou |
| **timesheets** | `project_id` | `projects.id` | Em qual projeto |
| **timesheets** | `task_id` | `tasks.id` | Em qual tarefa |

---

### ✨ Triple-Role System em `project_members`

```sql
project_role: 'member' | 'manager' | 'none'  -- Controla timesheets
expense_role: 'member' | 'manager' | 'none'  -- Controla expenses
finance_role: 'none' | 'member' | 'manager'  -- Controla aprovação financeira
```

**Exemplo Real**:
```sql
-- Carlos Ferreira no projeto "Mobile Banking App"
user_id: 7 (Carlos)
project_id: 2 (Mobile Banking)
project_role: 'member'  → pode criar timesheets
expense_role: 'member'  → pode criar expenses
finance_role: 'member'  → pode aprovar etapa finance
```

Esta arquitetura permite **flexibilidade total** nas permissões por projeto! 🎯

---
---

## 1️⃣ **PROJETOS**

| ID | Nome do Projeto | Status | Descrição |
|----|----------------|--------|-----------|
| 1 | E-Commerce Platform | active | Development of multi-tenant e-commerce platform |
| 2 | Mobile Banking App | active | iOS and Android mobile banking application |
| 3 | ERP System Migration | active | Migration from legacy ERP to SAP S/4HANA |
| 4 | Cloud Infrastructure Setup | active | AWS cloud infrastructure deployment |

---

## 2️⃣ **WORKERS (Users/Technicians)**

| User ID | Nome | Email | Tech ID |
|---------|------|-------|---------|
| 1 | Admin | asdrubalcarvalho@hotmail.com | 1 |
| 2 | Admin User | admin@upg2ai.com | 2 |
| 3 | João Silva | manager1@upg2ai.com | 3 |
| 4 | Maria Santos | manager2@upg2ai.com | 4 |
| 5 | Pedro Costa | tech1@upg2ai.com | 5 |
| 6 | Ana Rodrigues | tech2@upg2ai.com | 6 |
| 7 | Carlos Ferreira | tech3@upg2ai.com | 7 |

---

## 3️⃣ **TASKS (por Projeto)**

| Task ID | Nome da Task | Projeto | Status |
|---------|--------------|---------|--------|
| 1 | Backend API Development | E-Commerce Platform (1) | ✅ Active |
| 2 | Frontend React App | E-Commerce Platform (1) | ✅ Active |
| 3 | iOS App Development | Mobile Banking App (2) | ✅ Active |
| 4 | Android App Development | Mobile Banking App (2) | ✅ Active |
| 5 | System Analysis | ERP System Migration (3) | ✅ Active |
| 6 | AWS Environment Setup | Cloud Infrastructure Setup (4) | ✅ Active |

---

## 4️⃣ **PROJECT MEMBERS (Workers ↔ Projects)**

### **Projeto 1: E-Commerce Platform**
| Worker | Project Role | Expense Role | Finance Role |
|--------|--------------|--------------|--------------|
| Admin | 👔 manager | 👔 manager | 👔 manager |
| João Silva | 👔 manager | 👔 manager | 👔 manager |
| Pedro Costa | 👷 member | 👷 member | ❌ none |
| Ana Rodrigues | 👷 member | 👷 member | ❌ none |

### **Projeto 2: Mobile Banking App**
| Worker | Project Role | Expense Role | Finance Role |
|--------|--------------|--------------|--------------|
| Admin | 👔 manager | 👔 manager | 👔 manager |
| Maria Santos | 👔 manager | 👔 manager | 👔 manager |
| Ana Rodrigues | 👷 member | 👷 member | ❌ none |
| Carlos Ferreira | 👷 member | 👷 member | 👷 member |

### **Projeto 3: ERP System Migration**
| Worker | Project Role | Expense Role | Finance Role |
|--------|--------------|--------------|--------------|
| Admin | 👔 manager | 👔 manager | 👔 manager |
| João Silva | 👔 manager | 👔 manager | 👔 manager |
| Pedro Costa | 👷 member | 👷 member | ❌ none |
| Carlos Ferreira | 👷 member | 👷 member | ❌ none |

### **Projeto 4: Cloud Infrastructure Setup**
| Worker | Project Role | Expense Role | Finance Role |
|--------|--------------|--------------|--------------|
| Admin | 👷 member | 👷 member | ❌ none |
| Maria Santos | 👔 manager | 👔 manager | ❌ none |
| Pedro Costa | 👷 member | 👷 member | ❌ none |

---

## 5️⃣ **TRABALHO REALIZADO (Timesheets: Workers ↔ Tasks)**

| Worker | Task | Projeto |
|--------|------|---------|
| Pedro Costa | Backend API Development | E-Commerce Platform |
| Ana Rodrigues | iOS App Development | Mobile Banking App |
| Carlos Ferreira | System Analysis | ERP System Migration |

---

## 📈 **Resumo Estatístico**

- **Total de Projetos**: 4
- **Total de Workers**: 7 (2 admins + 2 managers + 3 technicians)
- **Total de Tasks**: 6
- **Total de Associações Project-Worker**: 15
- **Workers com Timesheets Registrados**: 3 (Pedro, Ana, Carlos)

---

## 🔗 **Relações Chave**

### Hierarquia do Sistema
```
Tenant (upg2ai)
  ├── Projects (4)
  │   ├── E-Commerce Platform
  │   │   ├── Tasks: Backend API Development, Frontend React App
  │   │   └── Members: Admin (manager), João Silva (manager), Pedro Costa (member), Ana Rodrigues (member)
  │   ├── Mobile Banking App
  │   │   ├── Tasks: iOS App Development, Android App Development
  │   │   └── Members: Admin (manager), Maria Santos (manager), Ana Rodrigues (member), Carlos Ferreira (member)
  │   ├── ERP System Migration
  │   │   ├── Tasks: System Analysis
  │   │   └── Members: Admin (manager), João Silva (manager), Pedro Costa (member), Carlos Ferreira (member)
  │   └── Cloud Infrastructure Setup
  │       ├── Tasks: AWS Environment Setup
  │       └── Members: Admin (member), Maria Santos (manager), Pedro Costa (member)
  └── Workers (7)
      ├── Managers: João Silva, Maria Santos
      ├── Members: Pedro Costa, Ana Rodrigues, Carlos Ferreira
      └── Admins: Admin, Admin User
```

### Triple-Role System Explicado

Cada worker tem **3 roles independentes** por projeto:

1. **project_role**: Controla timesheets (member/manager/none)
2. **expense_role**: Controla expenses (member/manager/none)
3. **finance_role**: Controla aprovação financeira (member/manager/none)

**Exemplo**: Carlos Ferreira no projeto "Mobile Banking App"
- project_role: `member` → pode criar timesheets
- expense_role: `member` → pode criar expenses
- finance_role: `member` → pode aprovar na etapa finance

---

## 📝 **Legenda**

- 👔 **manager**: Pode aprovar timesheets/expenses de members do projeto
- 👷 **member**: Trabalha no projeto mas não aprova outros
- ❌ **none**: Sem permissões nessa categoria
- ✅ **Active**: Task ativa e disponível para uso

---

**Data de Extração**: 13 de dezembro de 2025  
**Database**: `timesheet_01KCBKG5QQPCA5YC4AAB01N6CP`  
**Tenant ID**: `01KCBKG5QQPCA5YC4AAB01N6CP`
