# Database Permissions for Multi-Tenancy

## 🔴 Problema Recorrente

**Erro comum durante tenant registration:**
```
SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied for user 'timesheet'@'%' to database 'timesheet_01XYZ...'
```

**Causa**: O user `timesheet` do MySQL não tem permissões para criar novos databases (necessário para multi-tenancy).

---

## ✅ Solução Automática (Recomendada)

### 1. Comando Artisan (Mais Rápido)

```bash
# Verificar e configurar permissões
docker-compose exec app php artisan db:setup-permissions

# Forçar reconfiguração mesmo se já existirem
docker-compose exec app php artisan db:setup-permissions --force
```

**O que o comando faz:**
- ✅ Verifica permissões atuais do user `timesheet`
- ✅ Concede `CREATE ON *.*` (criar databases)
- ✅ Concede `ALL ON timesheet_%.*` (acesso total a tenant DBs)
- ✅ Executa `FLUSH PRIVILEGES`
- ✅ Exibe relatório completo

**Output esperado:**
```
🔐 Checking database permissions for multi-tenancy...

Checking grants for user: timesheet@%
Current grants:
  • GRANT CREATE ON *.* TO `timesheet`@`%`
  • GRANT ALL PRIVILEGES ON `timesheet`.* TO `timesheet`@`%`
  • GRANT ALL PRIVILEGES ON `timesheet_%`.* TO `timesheet`@`%`

✅ All necessary permissions are already configured!
```

### 2. Script de Inicialização (Permanente)

O arquivo `docker/mysql/init.sql` é executado automaticamente quando o container MySQL é criado pela primeira vez:

```sql
-- Arquivo: docker/mysql/init.sql
GRANT CREATE ON *.* TO 'timesheet'@'%';
GRANT ALL PRIVILEGES ON `timesheet_%`.* TO 'timesheet'@'%';
FLUSH PRIVILEGES;
```

**Montado via docker-compose.yml:**
```yaml
database:
  volumes:
    - ./docker/mysql/init.sql:/docker-entrypoint-initdb.d/init.sql:ro
```

⚠️ **Atenção**: O script só roda em **primeiro setup**. Se já existe volume MySQL, precisa usar:
```bash
# Recriar database do zero (PERDE DADOS!)
docker-compose down -v
docker-compose up -d
```

---

## 🔧 Solução Manual

Se o comando Artisan falhar, execute direto no MySQL:

```bash
docker-compose exec database mysql -u root -proot -e "
  GRANT CREATE ON *.* TO 'timesheet'@'%';
  GRANT ALL PRIVILEGES ON \`timesheet_%\`.* TO 'timesheet'@'%';
  FLUSH PRIVILEGES;
"
```

---

## 📋 Verificação

### Verificar permissões atuais:

```bash
docker-compose exec database mysql -u root -proot -e "SHOW GRANTS FOR 'timesheet'@'%';"
```

**Output esperado:**
```
+------------------------------------------------------------+
| Grants for timesheet@%                                     |
+------------------------------------------------------------+
| GRANT CREATE ON *.* TO `timesheet`@`%`                     |
| GRANT ALL PRIVILEGES ON `timesheet`.* TO `timesheet`@`%`   |
| GRANT ALL PRIVILEGES ON `timesheet_%`.* TO `timesheet`@`%` |
+------------------------------------------------------------+
```

### Testar criação de tenant database:

```bash
docker-compose exec app php artisan tinker

# No Tinker:
DB::statement("CREATE DATABASE IF NOT EXISTS timesheet_test_permissions");
DB::statement("DROP DATABASE timesheet_test_permissions");
```

Se não houver erro, as permissões estão corretas! ✅

---

## 🛡️ Por Que NÃO Usar Root?

### Comparação de Segurança:

| Aspecto | Root | User `timesheet` com permissões |
|---------|------|----------------------------------|
| **Produção** | ❌ NUNCA usar | ✅ Prática correta |
| **Desenvolvimento** | ⚠️ Funciona mas arriscado | ✅ Treina boas práticas |
| **Auditoria** | ❌ Difícil rastrear ações | ✅ Logs específicos por user |
| **Princípio do Menor Privilégio** | ❌ Acesso total desnecessário | ✅ Apenas o necessário |
| **Comprometimento** | ❌ Atacante tem controle total | ✅ Limitado a tenant DBs |

### Permissões do User `timesheet`:

```sql
-- ✅ TEM (necessário):
CREATE DATABASE timesheet_01XYZ           -- Criar tenant DBs
CREATE TABLE timesheet_01XYZ.users        -- Migrations em tenant
SELECT, INSERT, UPDATE, DELETE            -- CRUD normal

-- ❌ NÃO TEM (segurança):
CREATE USER 'hacker'@'%'                  -- Criar novos users
DROP DATABASE mysql                       -- Destruir sistema
GRANT ALL ON *.* TO 'attacker'@'%'       -- Conceder privilégios
```

### Recomendação Final:

✅ **Manter user `timesheet` com permissões adequadas** (solução atual)  
❌ **Não usar root em desenvolvimento** (má prática mesmo em local)

---

## 🔄 Troubleshooting

### Erro: "Access denied to database"

**Causa**: Permissões não aplicadas ou perdidas após `docker-compose down -v`

**Solução**:
```bash
docker-compose exec app php artisan db:setup-permissions
```

### Erro: "Failed to setup permissions"

**Causa**: User `timesheet` não consegue conceder permissões a si mesmo (precisa de root)

**Solução manual**:
```bash
docker-compose exec database mysql -u root -proot < docker/mysql/init.sql
```

### Permissões perdidas após rebuild

**Causa**: `docker-compose down -v` remove volumes, incluindo permissões do MySQL

**Solução permanente**:
1. ✅ `init.sql` é remontado automaticamente no próximo `up`
2. ✅ Rodar `php artisan db:setup-permissions` após cada `down -v`
3. ✅ Evitar usar `-v` se não quer perder dados

### Database já existe mas sem permissões

```bash
# Verificar databases existentes
docker-compose exec database mysql -u root -proot -e "SHOW DATABASES LIKE 'timesheet_%';"

# Aplicar permissões retroativamente
docker-compose exec app php artisan db:setup-permissions --force
```

---

## 📚 Documentação Relacionada

- **Comando Artisan**: `backend/app/Console/Commands/SetupDatabasePermissions.php`
- **Script Init**: `docker/mysql/init.sql`
- **Docker Config**: `docker-compose.yml` (seção `database.volumes`)
- **README**: Seção "Quick Start" com comando obrigatório
- **Copilot Instructions**: `.github/copilot-instructions.md` (workflow Docker)

---

## ✅ Checklist de Setup

- [ ] Containers rodando: `docker-compose up -d`
- [ ] Permissões configuradas: `php artisan db:setup-permissions`
- [ ] Migrations rodadas: `php artisan migrate`
- [ ] Tenant criado: `php artisan tenants:create` ou via frontend
- [ ] Testar registration em `http://app.localhost:8082/register`

**Se todos os checks passarem, o sistema está pronto para multi-tenancy!** 🚀
