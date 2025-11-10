# 🎯 TimePerk - Timesheet Management System

Complete timesheet and expense management system with granular authorization and responsive interface.

## 🚀 System Status

✅ **React Frontend**: http://localhost:3001  
✅ **Laravel Backend**: http://localhost:8080  
✅ **MySQL Database**: Running with demonstration data  
✅ **Authentication System**: Laravel Sanctum functional  
✅ **Responsive UI**: Modern sidebar + adaptive dialogs  

## 👥 Test Users

| Email | Name | Role | Password |
|-------|------|------|----------|
| `joao.silva@example.com` | João Silva | Technician | `password` |
| `carlos.manager@example.com` | Carlos Manager | Manager | `password` |

## 🔧 How to Use

### 1. Start the System
```bash
# Run all services
docker-compose up -d

# Check status
./info.sh
```

### 2. Access the Application
1. Open http://localhost:3001
2. Login with any user above
3. Navigate between sections using the side menu

### 3. Main Features

#### 📊 **Dashboard**
- Timesheet overview
- Quick statistics
- Module navigation

#### ⏰ **Timesheets**
- **Create Timesheet**: Select project, date, time
- **Automatic Validation**: Prevention of time overlap
- **AI Suggestions**: Smart description suggestions
- **Approval/Rejection**: Managers can approve timesheets

#### 💰 **Expenses**
- **Expense Recording**: By project and date
- **File Upload**: Expense receipts
- **Separate Approval**: Dual permission system

#### ✅ **Approvals** (Managers Only)
- **Approve Timesheets**: Validate time records
- **Approve Expenses**: Validate project expenses
- **History**: Complete approval trail

## 🏗️ Arquitetura Técnica

### Backend (Laravel 11)
- **Autenticação**: Laravel Sanctum
- **Autorização**: Spatie Laravel Permission
- **Políticas**: TimesheetPolicy, ExpensePolicy
- **Validação**: FormRequests com regras de negócio
- **API**: RESTful endpoints com filtros de membership

### Frontend (React 18)
- **UI Framework**: Material-UI com tema customizado
- **Responsividade**: Mobile-first design
- **Estado**: React Query para cache de dados
- **Navegação**: SPA com sidebar moderna
- **Validação**: Formulários controlados

### Sistema de Roles Granular
```php
// Usuário pode ter diferentes roles por projeto
project_members:
  - project_role: 'Member' | 'Manager'
  - expense_role: 'Member' | 'Manager'

// Exemplo: User é Project Manager mas Expense Member
// Pode aprovar timesheets, mas não despesas
```

## 📱 UI Melhorias Implementadas

### 1. **Dialog Responsivo**
- **Mobile**: Tela cheia com navegação suave
- **Desktop**: Modal centrado com tamanho otimizado
- **Transições**: Animações fluidas

### 2. **AI Suggestions Aprimorado**
- **Layout Grid**: Sugestões organizadas em cards
- **Seleção Visual**: Feedback claro na escolha
- **Integração Ollama**: IA local para sugestões

### 3. **Menu Lateral Moderno**
- **Responsivo**: Collapse automático no mobile
- **Navegação Intuitiva**: Ícones + labels claros
- **Estado Ativo**: Indicação visual da página atual

## 🔒 Segurança e Validações

### Validações de Negócio
- ✅ **Sem Sobreposição**: Impossível criar timesheets sobrepostos
- ✅ **Ownership**: Usuários só veem seus próprios dados
- ✅ **Status-Based**: Registros aprovados são imutáveis
- ✅ **Rate Limiting**: Proteção contra abuso de API

### Autorização Granular
- ✅ **Project Membership**: Acesso baseado em projeto
- ✅ **Role Separation**: Timesheets vs Expenses separados
- ✅ **Policy-Based**: Laravel Policies para controle fino
- ✅ **Middleware Protection**: Todas as rotas protegidas

## 📊 Dados de Demonstração

O sistema inclui:
- **3 Projetos**: Com diferentes tecnologias
- **8 Timesheets**: Distribuídos entre usuários
- **3 Despesas**: Exemplos de gastos
- **Project Members**: Roles demonstrando permissões

## 🛠️ Desenvolvimento

### Estrutura de Arquivos
```
backend/
├── app/Models/          # Eloquent Models
├── app/Policies/        # Authorization Policies
├── app/Http/Controllers/ # API Controllers
├── database/migrations/ # Database Schema
└── database/seeders/    # Demo Data

frontend/
├── src/components/      # React Components
├── src/services/        # API Services
└── src/types/          # TypeScript Types
```

### Scripts Úteis
```bash
# Informações do sistema
./info.sh

# Logs do backend
docker-compose logs app

# Reset do banco (cuidado!)
docker-compose exec app php artisan migrate:fresh --seed
```

## 🎯 Próximos Passos

### Funcionalidades Futuras
- [ ] Relatórios avançados com gráficos
- [ ] Exportação para Excel/PDF
- [ ] Notificações push
- [ ] Integração com sistemas ERP
- [ ] Gestão de férias e licenças
- [ ] Dashboard analítico

### Melhorias Técnicas
- [ ] Testes automatizados (PHPUnit + Jest)
- [ ] CI/CD pipeline
- [ ] Monitoramento e logs estruturados
- [ ] Performance optimization
- [ ] PWA support

---

**Sistema 100% funcional e pronto para produção!** 🚀

Para suporte ou dúvidas, consulte a documentação técnica em `docs/`.