# 📋 RELATÓRIO COMPLETO: Sistema de Chamadas API do Frontend

**TimePerk Cortex - Timesheet Manager**  
**Data:** 20 de Novembro de 2025  
**Versão:** 1.0

---

## 📑 Índice

1. [Definição do API_URL Final](#1-definição-do-api_url-final)
2. [Como o /api é Adicionado](#2-como-o-api-é-adicionado)
3. [Configuração e Envio do X-Tenant](#3-configuração-e-envio-do-x-tenant)
4. [Chamadas Especiais](#4-chamadas-especiais)
5. [Middleware, Interceptors e Wrappers](#5-middleware-interceptors-e-wrappers)
6. [Chamadas Fetch Directas](#6-chamadas-fetch-directas)
7. [Diagrama de Arquitectura](#7-diagrama-de-arquitectura-completo)
8. [Problemas Detectados](#8-problemas-detectados)
9. [Padrões Correctos](#9-padrões-correctos-identificados)
10. [Ficheiros-Chave](#10-ficheiros-chave-referência-rápida)

---

## 1️⃣ Definição do API_URL Final

### **Localização Principal:**
📁 `frontend/src/services/api.ts` (linhas 17-24)

```typescript
/**
 * API ROOT (sem /api no fim - será adicionado nas rotas)
 * - PROD:  https://api.vendaslive.com
 * - DEV (Docker): http://webserver   (via VITE_API_URL)
 * - DEV (fora de Docker): http://api.localhost
 */
export const API_URL =
  import.meta.env.VITE_API_URL || 'http://api.localhost';
```

### **Fontes de Configuração:**

| Ambiente | Ficheiro | Variável | Valor Actual |
|----------|----------|----------|--------------|
| **Produção** | `frontend/.env.production` | `VITE_API_URL` | `http://api.localhost` ⚠️ |
| **Produção (Exemplo)** | `frontend/.env.production.example` | `VITE_API_URL` | `https://api.yourdomain.com` |
| **Dev (Docker)** | `docker-compose.yml` | `VITE_API_URL` | `http://api.localhost` |
| **Fallback** | Código hardcoded | - | `http://api.localhost` |

⚠️ **PROBLEMA DETECTADO:** O `.env.production` actual ainda tem `http://api.localhost` em vez de `https://api.vendaslive.com`

### **Como o Vite Processa:**

📁 `frontend/vite.config.ts`:
- **NÃO** há proxy configurado
- **NÃO** há rewrite de URLs
- **NÃO** há middleware que altere requests
- O Vite passa `VITE_API_URL` directamente para `import.meta.env`

**Configuração Actual do Vite:**
```typescript
export default defineConfig({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 3000,
    watch: {
      usePolling: true,
    },
    allowedHosts: ['app.vendaslive.com'],
  },
  // NÃO há proxy configurado
})
```

---

## 2️⃣ Como o `/api` é Adicionado

### **A) Instância Axios (PADRÃO)**

📁 `frontend/src/services/api.ts` (linhas 26-32)

```typescript
// Axios instance - baseURL SEM /api (adicionado em cada rota)
const api = axios.create({
  baseURL: API_URL,  // ← SEM /api aqui
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});
```

**Então cada método API adiciona `/api` manualmente:**

```typescript
// Exemplos (linhas 143-379):
techniciansApi.getAll() → api.get('/api/technicians')
projectsApi.getAll()    → api.get('/api/projects')
timesheetsApi.create()  → api.post('/api/timesheets', data)
expensesApi.getById()   → api.get(`/api/expenses/${id}`)
dashboardApi.getStats() → api.get('/api/dashboard/statistics')
tenantApi.register()    → api.post('/api/tenants/register')
```

**URL Final Construída:**
```
baseURL                      +  path
─────────────────────────       ─────────────────
https://api.vendaslive.com  +  /api/timesheets
                                ↓
https://api.vendaslive.com/api/timesheets
```

**Métodos de API Exportados:**

| API | Métodos | Exemplo |
|-----|---------|---------|
| `techniciansApi` | getAll, getById, create, update, delete | `api.get('/api/technicians')` |
| `projectsApi` | getAll, getForCurrentUser, getById, create, update, delete | `api.get('/api/projects')` |
| `timesheetsApi` | getAll, getById, create, update, delete, submit, approve, reject, getValidation, getManagerView, getPendingCounts | `api.post('/api/timesheets', data)` |
| `expensesApi` | getAll, getById, create, update, delete, submit, approve, reject | `api.post('/api/expenses', formData)` |
| `tasksApi` | getAll, getById, getByProject | `api.get('/api/tasks')` |
| `locationsApi` | getAll, getActive, getById | `api.get('/api/locations')` |
| `dashboardApi` | getStatistics, getTopProjects | `api.get('/api/dashboard/statistics')` |
| `tenantApi` | register, list, get | `api.post('/api/tenants/register')` |

---

### **B) Wrapper `fetchWithAuth()` (PARA FETCH)**

📁 `frontend/src/services/api.ts` (linhas 90-106)

```typescript
/**
 * Use native fetch with the same auth + tenant headers as axios instance
 */
export const fetchWithAuth = async (input: RequestInfo, init: RequestInit = {}) => {
  const headers = {
    ...(init.headers || {}),
    ...getAuthHeaders(),  // Adiciona Authorization + X-Tenant
  } as HeadersInit;

  const finalInit: RequestInit = {
    ...init,
    headers,
    // DO NOT use credentials: 'include' - auth via Bearer token in header
  };

  return fetch(input, finalInit);
};
```

**Chamadas que usam `fetchWithAuth()`:**
- `ApprovalManager.tsx`: `fetchWithAuth(`${API_URL}/api/expenses/pending`)`
- `ExpenseManager.tsx`: `fetchWithAuth(`${API_URL}/api/expenses`)`
- `ExpenseManager.tsx`: `fetchWithAuth(`${API_URL}/api/projects?my_projects=true`)`
- Operações de aprovação (approve, reject, mark-paid)
- Download de attachments

**Componentes que Usam:**
```typescript
// ApprovalManager.tsx (linha 212)
const response = await fetchWithAuth(`${API_URL}/api/expenses/pending`);

// ApprovalManager.tsx (linha 388)
const response = await fetchWithAuth(`${API_URL}/api/expenses/${id}/approve`, {
  method: 'PATCH',
  body: JSON.stringify({ notes })
});

// ExpenseManager.tsx (linha 99)
const response = await fetchWithAuth(`${API_URL}/api/expenses`);
```

---

### **C) Fetch Directo (LEGACY - 5 locais)**

| Ficheiro | Linha | Endpoint | Razão |
|----------|-------|----------|-------|
| `AuthContext.tsx` | 144 | `${API_URL}/api/user` | Auth check inicial |
| `AuthContext.tsx` | 180 | `${API_URL}/api/login` | Login (sem interceptor) |
| `ExpenseManager.tsx` | 220 | `${API_URL}/api/expenses/${id}` | Upload de ficheiros (FormData) |
| `ExpenseManager.tsx` | 229 | `${API_URL}/api/expenses` | Upload de ficheiros (FormData) |
| `ResetDataDialog.tsx` | 38 | `${API_URL}/api/admin/reset-data` | Operação crítica de reset |

**Todos adicionam `/api` manualmente na string.**

**Exemplo de Upload de Ficheiro:**
```typescript
// ExpenseManager.tsx (linhas 215-232)
if (selectedExpense?.id) {
  formData.append('_method', 'PUT');
  const headers = getAuthHeaders();
  delete (headers as any)['Content-Type']; // Let browser set multipart boundary
  response = await fetch(`${API_URL}/api/expenses/${selectedExpense.id}`, {
    method: 'POST',
    headers,
    body: formData
  });
}
```

---

## 3️⃣ Configuração e Envio do `X-Tenant`

### **A) Obtenção do Tenant Slug**

📁 `frontend/src/services/api.ts` (linhas 37-51)

```typescript
/**
 * Extract tenant slug from subdomain or localStorage
 * Order: subdomain > localStorage
 */
const getTenantSlug = (): string | null => {
  // Try subdomain first (e.g., "acme" from "acme.app.timeperk.com")
  const host = window.location.hostname;
  const parts = host.split('.');
  
  // If subdomain exists and it's not "app" or "www", use it as tenant
  if (parts.length > 2 && parts[0] !== 'app' && parts[0] !== 'www') {
    return parts[0];
  }
  
  // Fall back to localStorage (set during login)
  return localStorage.getItem('tenant_slug');
};
```

**Ordem de Prioridade:**
1. **Subdomain** (e.g., `demo.vendaslive.com` → `demo`)
2. **localStorage** (`tenant_slug` key)

**Exemplos de Extracção:**
| URL | Resultado |
|-----|-----------|
| `demo.vendaslive.com` | `demo` |
| `acme.app.timeperk.com` | `acme` |
| `app.vendaslive.com` | `null` (ignora "app") |
| `www.vendaslive.com` | `null` (ignora "www") |
| `localhost:3000` | `null` → usa localStorage |

---

### **B) Injecção Automática via Axios Interceptor**

📁 `frontend/src/services/api.ts` (linhas 109-124)

```typescript
// Add authentication + tenant interceptor
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  
  // Inject X-Tenant header for tenant-scoped requests
  const tenantSlug = getTenantSlug();
  if (tenantSlug) {
    config.headers['X-Tenant'] = tenantSlug;
    console.log('[API] X-Tenant header set to:', tenantSlug);
  } else {
    console.warn('[API] No tenant slug found! Checking localStorage:', localStorage.getItem('tenant_slug'));
  }
  
  return config;
});
```

**Todas as chamadas via `api.*` recebem automaticamente:**
- `Authorization: Bearer {token}`
- `X-Tenant: {slug}`

**Headers Finais de uma Request Axios:**
```http
GET /api/timesheets HTTP/1.1
Host: api.vendaslive.com
Content-Type: application/json
Accept: application/json
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
X-Tenant: demo
```

---

### **C) Injecção Manual em Fetch**

📁 `frontend/src/services/api.ts` (linhas 68-87)

```typescript
/**
 * Get headers for fetch requests (includes Authorization + X-Tenant)
 * Use this helper when using native fetch() instead of axios api instance
 */
export const getAuthHeaders = (): HeadersInit => {
  const headers: HeadersInit = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };

  const token = localStorage.getItem('auth_token');
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const tenantSlug = getTenantSlug();
  if (tenantSlug) {
    headers['X-Tenant'] = tenantSlug;
  }

  return headers;
};
```

**Usado em:**
- `fetchWithAuth()` wrapper (linha 95)
- `ExpenseManager.tsx` (file uploads via `getAuthHeaders()`)
- `ResetDataDialog.tsx` (operação crítica)

**Exemplo de Uso:**
```typescript
const headers = getAuthHeaders();
delete (headers as any)['Content-Type']; // Para FormData
const response = await fetch(`${API_URL}/api/expenses`, {
  method: 'POST',
  headers,
  body: formData
});
```

---

### **D) Gestão do Tenant Slug no LocalStorage**

| Operação | Ficheiro | Linha | Função | Quando |
|----------|----------|-------|--------|--------|
| **Set** | `api.ts` | 57 | `setTenantSlug(slug)` | Helper exportado |
| **Get** | `api.ts` | 50 | `localStorage.getItem('tenant_slug')` | getTenantSlug() fallback |
| **Remove** | `api.ts` | 64 | `clearTenantSlug()` | Helper exportado |
| **Set (Login)** | `AuthContext.tsx` | 203 | Após login bem-sucedido | `localStorage.setItem('tenant_slug', tenantSlug)` |
| **Set (Register)** | `TenantRegistration.tsx` | 204 | Após registo | `localStorage.setItem('tenant_slug', response.data.tenant)` |
| **Remove (Logout)** | `AuthContext.tsx` | 224 | Durante logout | `localStorage.removeItem('tenant_slug')` |
| **Remove (Reset)** | `ResetDataDialog.tsx` | 56 | Após reset de dados | `localStorage.removeItem('tenant_slug')` |
| **Persist (Reload)** | `main.tsx` | 14-19 | Preservar após hot-reload | Backup e restore durante dev |

**Código de Persistência (main.tsx):**
```typescript
// Don't clear auth_token and tenant_slug
const token = localStorage.getItem('auth_token');
const tenant = localStorage.getItem('tenant_slug');
if (token) localStorage.setItem('auth_token', token);
if (tenant) localStorage.setItem('tenant_slug', tenant);
```

---

### **E) Tenant Guard Hook**

📁 `frontend/src/hooks/useTenantGuard.ts`

```typescript
/**
 * Guard hook to ensure tenant_slug exists in localStorage
 * Redirects to login if missing
 */
export const useTenantGuard = () => {
  const navigate = useNavigate();

  useEffect(() => {
    const tenantSlug = localStorage.getItem('tenant_slug');
    
    if (!tenantSlug) {
      console.warn('[TenantGuard] No tenant slug found, redirecting to login');
      navigate('/?reason=missing-tenant');
    }
  }, [navigate]);
};
```

**Usado em:**
- `ApprovalManager.tsx` (linha 65)
- `ExpenseManager.tsx` (linha 61)
- `PlanningGantt.tsx` (linha 86)
- Outros componentes que requerem tenant context

---

## 4️⃣ Chamadas Especiais

### **A) Tenant Registration**

📁 `frontend/src/components/Auth/TenantRegistration.tsx` (linha 193)

```typescript
const response = await api.post('/api/tenants/register', {
  company_name: formData.company_name,
  slug: formData.slug,
  admin_name: formData.admin_name,
  admin_email: formData.admin_email,
  admin_password: formData.admin_password,
  admin_password_confirmation: formData.admin_password_confirmation,
  industry: formData.industry || undefined,
  country: formData.country || undefined,
  timezone: formData.timezone || 'UTC',
});
```

**Características:**
- **NÃO requer** `X-Tenant` header (endpoint central)
- Backend comentado como "Central API Routes" em `backend/routes/api.php`
- Cria novo tenant + base de dados + utilizador Owner
- Retorna token de autenticação e slug do tenant

**Response Esperada:**
```json
{
  "status": "success",
  "message": "Tenant registered successfully",
  "tenant": "demo-company",
  "database": "timesheet_01KABC123...",
  "tenant_info": {
    "id": "01KABC123...",
    "slug": "demo-company",
    "name": "Demo Company Ltd",
    "status": "active"
  },
  "admin": {
    "email": "admin@demo.com",
    "token": "eyJ0eXAiOiJKV1Qi..."
  }
}
```

---

### **B) Slug Availability Check**

📁 `frontend/src/components/Auth/TenantRegistration.tsx` (linha 125)

```typescript
const response = await api.get(`/tenants/check-slug`, {
  params: { slug: formData.slug }
});
```

⚠️ **ANOMALIA DETECTADA:** Esta chamada **NÃO** tem `/api` prefix!
- Deveria ser: `api.get('/api/tenants/check-slug')`
- Actualmente tenta acessar: `https://api.vendaslive.com/tenants/check-slug` (sem `/api`)
- **ISTO VAI FALHAR EM PRODUÇÃO!**

**Documentação do Backend:**
```php
// backend/routes/api.php (linha 40-41)
Route::get('tenants/check-slug', [TenantController::class, 'checkSlug'])
    ->middleware('throttle:30,1'); // 30 checks per minute
```

O backend espera `/api/tenants/check-slug` (com prefixo automático do Laravel).

**Código Completo do Check:**
```typescript
useEffect(() => {
  if (!formData.slug || formData.slug.length < 3) {
    setSlugAvailable(null);
    return;
  }

  const timer = setTimeout(async () => {
    setSlugChecking(true);
    try {
      const response = await api.get(`/tenants/check-slug`, { // ⚠️ FALTA /api
        params: { slug: formData.slug }
      });
      setSlugAvailable(response.data.available);
    } catch (error) {
      console.error('Slug check failed:', error);
      setSlugAvailable(null);
    } finally {
      setSlugChecking(false);
    }
  }, 500); // Debounce 500ms

  return () => clearTimeout(timer);
}, [formData.slug]);
```

---

### **C) Tenant List (Admin)**

📁 `frontend/src/services/api.ts` (linhas 366-377)

```typescript
export const tenantApi = {
  /**
   * Register a new tenant (company)
   * This endpoint does NOT require X-Tenant header
   */
  register: (data: TenantRegistrationData): Promise<TenantRegistrationResponse> =>
    api.post('/api/tenants/register', data).then(res => res.data),
  
  /**
   * List all tenants (Admin only)
   */
  list: (): Promise<any[]> =>
    api.get('/api/tenants').then(res => res.data.tenants),
  
  /**
   * Get tenant details by slug
   */
  get: (slug: string): Promise<any> =>
    api.get(`/api/tenants/${slug}`).then(res => res.data.tenant),
};
```

**Notas:**
- `register()` e `list()` são endpoints centrais (sem tenant context)
- `get(slug)` pode ser usado para verificar detalhes de um tenant específico
- Todos incluem `/api` correctamente

---

### **D) Attachment Download com Autenticação**

📁 `frontend/src/components/Approvals/ExpenseApprovalPanel.tsx` (linha 72)

```typescript
const getAttachmentUrl = (expenseId: number): string => {
  const token = localStorage.getItem('auth_token');
  const tenant = localStorage.getItem('tenant_slug');
  return `${API_URL}/api/expenses/${expenseId}/attachment?token=${token}&tenant=${tenant}`;
};
```

**Características:**
- Passa token e tenant como query params (não headers)
- Permite download directo via `<a href>` ou `window.open()`
- Backend valida token e tenant antes de retornar ficheiro

**Uso:**
```tsx
<Link
  href={getAttachmentUrl(expense.id)}
  target="_blank"
  rel="noopener noreferrer"
>
  View Attachment
</Link>
```

---

## 5️⃣ Middleware, Interceptors e Wrappers

### **Inventário Completo:**

| Tipo | Localização | Função | Modifica URLs? | Adiciona Headers? |
|------|-------------|--------|----------------|-------------------|
| **Axios Interceptor** | `api.ts:109-124` | Adiciona `Authorization` + `X-Tenant` | ❌ NÃO | ✅ SIM |
| **fetchWithAuth()** | `api.ts:90-106` | Wrapper para fetch com auth headers | ❌ NÃO | ✅ SIM |
| **getAuthHeaders()** | `api.ts:68-87` | Gera headers para fetch manual | ❌ NÃO | ✅ SIM (retorna objecto) |
| **getTenantSlug()** | `api.ts:37-51` | Extrai tenant de subdomain/localStorage | ❌ NÃO | ❌ NÃO |
| **setTenantSlug()** | `api.ts:54-58` | Guarda tenant no localStorage | ❌ NÃO | ❌ NÃO |
| **clearTenantSlug()** | `api.ts:61-65` | Remove tenant do localStorage | ❌ NÃO | ❌ NÃO |
| **useTenantGuard()** | `hooks/useTenantGuard.ts` | Redireciona se tenant ausente | ❌ NÃO | ❌ NÃO |

**Conclusão:** ✅ **Nenhum middleware modifica URLs das requests.**

---

### **Detalhes dos Helpers:**

#### **1. getTenantSlug()**
```typescript
const getTenantSlug = (): string | null => {
  const host = window.location.hostname;
  const parts = host.split('.');
  
  if (parts.length > 2 && parts[0] !== 'app' && parts[0] !== 'www') {
    return parts[0]; // Subdomain
  }
  
  return localStorage.getItem('tenant_slug'); // Fallback
};
```

**Casos de Uso:**
- Chamado por `getAuthHeaders()` e axios interceptor
- Permite multi-tenancy transparente via subdomains
- Fallback para desenvolvimento local

---

#### **2. setTenantSlug(slug)**
```typescript
export const setTenantSlug = (slug: string): void => {
  localStorage.setItem('tenant_slug', slug);
};
```

**Quando é Chamado:**
- Após login bem-sucedido
- Após registo de novo tenant
- Manualmente via código de componente

---

#### **3. clearTenantSlug()**
```typescript
export const clearTenantSlug = (): void => {
  localStorage.removeItem('tenant_slug');
};
```

**Quando é Chamado:**
- Durante logout
- Após erro de autenticação
- Reset completo de dados

---

#### **4. fetchWithAuth()**
```typescript
export const fetchWithAuth = async (input: RequestInfo, init: RequestInit = {}) => {
  const headers = {
    ...(init.headers || {}),
    ...getAuthHeaders(),
  } as HeadersInit;

  return fetch(input, { ...init, headers });
};
```

**Vantagens:**
- Consistência com axios interceptor
- Suporte para fetch API nativo
- Perfeito para file uploads (FormData)

---

## 6️⃣ Chamadas Fetch Directas (Bypass do Axios)

### **Resumo Completo:**

| Ficheiro | Linhas | Endpoints | Usa Headers? | Razão | Deveria Migrar? |
|----------|--------|-----------|--------------|-------|-----------------|
| **AuthContext.tsx** | 144, 180 | `/api/user`, `/api/login` | ✅ Manual | Auth inicial (antes do interceptor) | ⚠️ SIM (para fetchWithAuth) |
| **ExpenseManager.tsx** | 220, 229 | `/api/expenses`, `/api/expenses/{id}` | ✅ `getAuthHeaders()` | File uploads (FormData + multipart) | ✅ NÃO (FormData requer fetch) |
| **ResetDataDialog.tsx** | 38 | `/api/admin/reset-data` | ✅ `getAuthHeaders()` | Operação crítica de reset | ⚠️ OPCIONAL (poderia usar fetchWithAuth) |

**Total:** 5 chamadas fetch directas

---

### **Detalhes de Cada Caso:**

#### **A) AuthContext - User Check**
📁 `frontend/src/components/Auth/AuthContext.tsx` (linha 144)

```typescript
const response = await fetch(`${API_URL}/api/user`, {
  headers: {
    Authorization: `Bearer ${token}`,
    'X-Tenant': storedTenant,
  },
});
```

**Contexto:**
- Executado no `useEffect` de inicialização
- Verifica se token é válido
- Carrega dados do utilizador
- **Poderia** usar `fetchWithAuth()` para consistência

---

#### **B) AuthContext - Login**
📁 `frontend/src/components/Auth/AuthContext.tsx` (linha 180)

```typescript
const response = await fetch(`${API_URL}/api/login`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Tenant': tenantSlug
  },
  body: JSON.stringify({
    email,
    password,
    tenant_slug: tenantSlug
  })
});
```

**Contexto:**
- Login é especial: ainda NÃO tem token
- Constrói headers manualmente
- Recebe token no response
- **Razão válida** para não usar interceptor

---

#### **C) ExpenseManager - File Upload (Update)**
📁 `frontend/src/components/Expenses/ExpenseManager.tsx` (linha 220)

```typescript
formData.append('_method', 'PUT');
const headers = getAuthHeaders();
delete (headers as any)['Content-Type']; // Let browser set multipart boundary

response = await fetch(`${API_URL}/api/expenses/${selectedExpense.id}`, {
  method: 'POST', // POST with _method=PUT for FormData
  headers,
  body: formData
});
```

**Contexto:**
- Laravel requer `_method=PUT` para file uploads via POST
- Browser define `Content-Type: multipart/form-data` automaticamente
- **Axios não suporta bem** este pattern
- Fetch é a escolha correcta aqui

---

#### **D) ExpenseManager - File Upload (Create)**
📁 `frontend/src/components/Expenses/ExpenseManager.tsx` (linha 229)

```typescript
const headers = getAuthHeaders();
delete (headers as any)['Content-Type'];

response = await fetch(`${API_URL}/api/expenses`, {
  method: 'POST',
  headers,
  body: formData
});
```

**Contexto:**
- Similar ao update
- Criação de expense com attachment
- Fetch necessário para FormData

---

#### **E) ResetDataDialog - Critical Operation**
📁 `frontend/src/components/Admin/ResetDataDialog.tsx` (linha 38)

```typescript
const response = await fetch(`${API_URL}/api/admin/reset-data`, {
  method: 'POST',
  headers: getAuthHeaders(),
});
```

**Contexto:**
- Reset completo de dados do tenant
- Operação administrativa crítica
- **Poderia** migrar para `fetchWithAuth()` sem problemas

---

## 7️⃣ Diagrama de Arquitectura Completo

```
┌─────────────────────────────────────────────────────────────────────┐
│ VITE BUILD TIME                                                     │
│ ┌─────────────────────────────────────────────────────────────────┐ │
│ │ .env.production:                                                │ │
│ │   VITE_API_URL=http://api.localhost                             │ │
│ │   (⚠️ DEVERIA SER: https://api.vendaslive.com)                  │ │
│ └─────────────────────────────────────────────────────────────────┘ │
└────────────────────────┬────────────────────────────────────────────┘
                         │ import.meta.env.VITE_API_URL
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│ frontend/src/services/api.ts                                        │
│ ┌─────────────────────────────────────────────────────────────────┐ │
│ │ export const API_URL =                                          │ │
│ │   import.meta.env.VITE_API_URL || 'http://api.localhost'       │ │
│ └─────────────────────────────────────────────────────────────────┘ │
│                                                                     │
│ ┌─────────────────────────────────────────────────────────────────┐ │
│ │ AXIOS INSTANCE                                                  │ │
│ │ const api = axios.create({                                      │ │
│ │   baseURL: API_URL,  // SEM /api                                │ │
│ │   headers: {                                                    │ │
│ │     'Content-Type': 'application/json',                         │ │
│ │     'Accept': 'application/json'                                │ │
│ │   }                                                             │ │
│ │ });                                                             │ │
│ └─────────────────────────────────────────────────────────────────┘ │
│                                                                     │
│ ┌─────────────────────────────────────────────────────────────────┐ │
│ │ AXIOS INTERCEPTOR                                               │ │
│ │ api.interceptors.request.use((config) => {                      │ │
│ │   // 1. Add Authorization header                                │ │
│ │   const token = localStorage.getItem('auth_token');             │ │
│ │   if (token) {                                                  │ │
│ │     config.headers.Authorization = `Bearer ${token}`;           │ │
│ │   }                                                             │ │
│ │                                                                 │ │
│ │   // 2. Add X-Tenant header                                     │ │
│ │   const tenantSlug = getTenantSlug();                           │ │
│ │   if (tenantSlug) {                                             │ │
│ │     config.headers['X-Tenant'] = tenantSlug;                    │ │
│ │     console.log('[API] X-Tenant:', tenantSlug);                 │ │
│ │   }                                                             │ │
│ │                                                                 │ │
│ │   return config;                                                │ │
│ │ });                                                             │ │
│ └─────────────────────────────────────────────────────────────────┘ │
│                                                                     │
│ ┌─────────────────────────────────────────────────────────────────┐ │
│ │ TENANT SLUG DETECTION                                           │ │
│ │ getTenantSlug():                                                │ │
│ │   1. Check subdomain (demo.vendaslive.com → 'demo')            │ │
│ │   2. Fallback to localStorage('tenant_slug')                    │ │
│ │                                                                 │ │
│ │ Filters: ignores 'app' and 'www' subdomains                     │ │
│ └─────────────────────────────────────────────────────────────────┘ │
│                                                                     │
│ ┌─────────────────────────────────────────────────────────────────┐ │
│ │ EXPORTED API METHODS (todos com /api manual):                   │ │
│ │ • techniciansApi → api.get('/api/technicians')                  │ │
│ │ • projectsApi    → api.get('/api/projects')                     │ │
│ │ • timesheetsApi  → api.post('/api/timesheets', data)            │ │
│ │ • expensesApi    → api.post('/api/expenses', formData)          │ │
│ │ • tasksApi       → api.get('/api/tasks')                        │ │
│ │ • locationsApi   → api.get('/api/locations')                    │ │
│ │ • dashboardApi   → api.get('/api/dashboard/statistics')         │ │
│ │ • tenantApi      → api.post('/api/tenants/register')            │ │
│ └─────────────────────────────────────────────────────────────────┘ │
│                                                                     │
│ ┌─────────────────────────────────────────────────────────────────┐ │
│ │ FETCH WRAPPERS (para casos especiais):                          │ │
│ │ • getAuthHeaders() → { Authorization, X-Tenant, ... }          │ │
│ │ • fetchWithAuth(url, init) → fetch com headers automáticos     │ │
│ └─────────────────────────────────────────────────────────────────┘ │
└────────────────────────┬────────────────────────────────────────────┘
                         │
         ┌───────────────┴───────────────┐
         ▼                               ▼
┌──────────────────────┐      ┌────────────────────────┐
│ AXIOS CALLS (95%)    │      │ FETCH CALLS (5%)       │
│ Maioria do código    │      │ Casos especiais        │
├──────────────────────┤      ├────────────────────────┤
│ ✅ Auto headers      │      │ ⚠️ Manual headers      │
│ ✅ Interceptor       │      │ ✅ getAuthHeaders()    │
│ ✅ /api prefix       │      │ ✅ /api prefix         │
│ ✅ Consistent        │      │ ⚠️ FormData/Special    │
└────────┬─────────────┘      └──────────┬─────────────┘
         │                                │
         │  baseURL + '/api/timesheets'   │  `${API_URL}/api/expenses`
         └────────────┬───────────────────┘
                      ▼
         ┌──────────────────────────────┐
         │ NETWORK REQUEST              │
         ├──────────────────────────────┤
         │ URL:                         │
         │ https://api.vendaslive.com   │
         │       /api/timesheets        │
         ├──────────────────────────────┤
         │ Headers:                     │
         │ Authorization: Bearer XXX    │
         │ X-Tenant: demo               │
         │ Content-Type: application/   │
         │               json           │
         │ Accept: application/json     │
         └──────────────────────────────┘
                      │
                      ▼
         ┌──────────────────────────────┐
         │ BACKEND (Laravel 11)         │
         ├──────────────────────────────┤
         │ Nginx (Port 80)              │
         │   ↓                          │
         │ Laravel Router               │
         │   ↓                          │
         │ Middleware:                  │
         │ • SetSanctumTenant           │
         │ • InitializeTenancy          │
         │   ↓                          │
         │ Controller:                  │
         │ TimesheetController@index    │
         │   ↓                          │
         │ Database: timesheet_{ULID}   │
         └──────────────────────────────┘
```

---

## 8️⃣ Problemas Detectados

### **1. URL de Produção Incorrecta** ⚠️ CRÍTICO

📁 `frontend/.env.production` (linha 1)

**Problema:**
```dotenv
VITE_API_URL=http://api.localhost
```

**Deveria ser:**
```dotenv
VITE_API_URL=https://api.vendaslive.com
```

**Impacto:**
- Build de produção aponta para localhost
- API calls falham em produção
- CORS errors

**Solução:**
```bash
cd frontend
echo "VITE_API_URL=https://api.vendaslive.com" > .env.production
echo "VITE_APP_URL=https://app.vendaslive.com" >> .env.production
```

---

### **2. Chamada Sem Prefixo `/api`** ⚠️ CRÍTICO

📁 `frontend/src/components/Auth/TenantRegistration.tsx` (linha 125)

**Problema:**
```typescript
const response = await api.get(`/tenants/check-slug`, {
  params: { slug: formData.slug }
});
```

**Deveria ser:**
```typescript
const response = await api.get(`/api/tenants/check-slug`, {
  params: { slug: formData.slug }
});
```

**Impacto:**
- Tenta acessar `https://api.vendaslive.com/tenants/check-slug` (404)
- Backend espera `https://api.vendaslive.com/api/tenants/check-slug`
- Validação de slug falha no registo
- Utilizador não consegue registar novo tenant

**Solução:**
```typescript
// TenantRegistration.tsx linha 125
const response = await api.get('/api/tenants/check-slug', {
  params: { slug: formData.slug }
});
```

---

### **3. Inconsistência: Fetch vs Axios** ⚠️ MEDIUM

**Problema:**
- `AuthContext.tsx` usa fetch directo para login/user check
- Constrói headers manualmente
- Risco de inconsistência se `getAuthHeaders()` mudar

**Locais Afectados:**
- `AuthContext.tsx` linhas 144, 180
- `ResetDataDialog.tsx` linha 38

**Solução Recomendada:**
```typescript
// Migrar para fetchWithAuth()
// AuthContext.tsx linha 144
const response = await fetchWithAuth(`${API_URL}/api/user`);

// AuthContext.tsx linha 180 (especial - mantém fetch directo)
// Login é exceção válida: ainda não tem token

// ResetDataDialog.tsx linha 38
const response = await fetchWithAuth(`${API_URL}/api/admin/reset-data`, {
  method: 'POST'
});
```

---

### **4. Comentários Desactualizados** ⚠️ LOW

📁 `frontend/src/services/api.ts` (linha 20)

**Problema:**
```typescript
/**
 * - DEV (Docker): http://webserver   (via VITE_API_URL)
 */
```

**Realidade:**
- Docker usa `http://api.localhost`
- Não há `webserver` configurado

**Solução:**
Actualizar comentário para reflectir configuração real.

---

## 9️⃣ Padrões Correctos Identificados

### **✅ 1. Single Source of Truth**
- `API_URL` exportado centralmente de `services/api.ts`
- Todas as chamadas importam de um único ficheiro
- Fácil de alterar em caso de mudança de domínio

### **✅ 2. Axios Interceptor Robusto**
- Adiciona headers automaticamente
- Logging para debug (`console.log('[API] X-Tenant:...'`)
- Warnings quando tenant ausente

### **✅ 3. Tenant Detection Inteligente**
- Prioriza subdomain (produção)
- Fallback para localStorage (desenvolvimento)
- Ignora subdomains especiais ('app', 'www')

### **✅ 4. Wrapper `fetchWithAuth()` Reutilizável**
- Consistência com axios interceptor
- Suporte para casos especiais (file uploads)
- Headers automáticos

### **✅ 5. Tenant Guard Hook**
- Protecção de rotas
- Redireccionamento automático
- Feedback claro ao utilizador

### **✅ 6. Prefix Manual Explícito**
- Todas as rotas adicionam `/api` manualmente
- Fácil de auditar
- Sem magic strings ou rewrites escondidos

### **✅ 7. TypeScript Interfaces Completas**
- `TenantRegistrationData`
- `TenantRegistrationResponse`
- `TimesheetMutationResponse`
- Type safety em todas as APIs

### **✅ 8. Gestão de Estado Consistente**
- localStorage para persistência
- Helpers centralizados (set/get/clear)
- Preservação durante hot-reload (dev)

---

## 🔟 Ficheiros-Chave (Referência Rápida)

### **Configuração Principal**

| Ficheiro | Linhas Críticas | Conteúdo | Importância |
|----------|----------------|----------|-------------|
| `frontend/src/services/api.ts` | 1-379 | ⭐ Configuração principal, APIs, interceptors, helpers | **CRÍTICO** |
| `frontend/.env.production` | 1-2 | ⚠️ URL de produção (INCORRECTA) | **CRÍTICO** |
| `frontend/vite.config.ts` | 1-27 | Configuração do Vite (sem proxy) | **MÉDIO** |

---

### **Autenticação & Tenancy**

| Ficheiro | Linhas Críticas | Conteúdo | Importância |
|----------|----------------|----------|-------------|
| `frontend/src/components/Auth/AuthContext.tsx` | 144, 180, 203, 224 | Login, user check, set/clear tenant | **ALTO** |
| `frontend/src/components/Auth/TenantRegistration.tsx` | 125, 193, 204 | ⚠️ check-slug SEM `/api`, register OK | **CRÍTICO** |
| `frontend/src/hooks/useTenantGuard.ts` | 1-20 | Guard para redirecionar sem tenant | **MÉDIO** |
| `frontend/src/main.tsx` | 14-19 | Preservar tenant durante hot-reload | **BAIXO** |

---

### **Operações Especiais**

| Ficheiro | Linhas Críticas | Conteúdo | Importância |
|----------|----------------|----------|-------------|
| `frontend/src/components/Expenses/ExpenseManager.tsx` | 99, 220, 229 | File uploads via fetch + FormData | **ALTO** |
| `frontend/src/components/Admin/ResetDataDialog.tsx` | 38, 56 | Reset de dados + clear tenant | **MÉDIO** |
| `frontend/src/components/Approvals/ApprovalManager.tsx` | 212, 388, 408, 430 | Aprovações via fetchWithAuth | **ALTO** |
| `frontend/src/components/Approvals/ExpenseApprovalPanel.tsx` | 72 | Attachment URL com query params | **MÉDIO** |

---

### **Estrutura de Código**

```
frontend/
├── src/
│   ├── services/
│   │   └── api.ts                    ⭐ CONFIGURAÇÃO PRINCIPAL
│   ├── hooks/
│   │   └── useTenantGuard.ts         🛡️ Protecção de rotas
│   ├── components/
│   │   ├── Auth/
│   │   │   ├── AuthContext.tsx       🔐 Autenticação
│   │   │   └── TenantRegistration.tsx ⚠️ Bug no check-slug
│   │   ├── Expenses/
│   │   │   └── ExpenseManager.tsx    📎 File uploads
│   │   ├── Approvals/
│   │   │   ├── ApprovalManager.tsx   ✅ Aprovações
│   │   │   └── ExpenseApprovalPanel.tsx 📥 Attachments
│   │   └── Admin/
│   │       └── ResetDataDialog.tsx   🗑️ Reset crítico
│   └── main.tsx                       🔄 Hot-reload persist
├── .env.production                    ⚠️ URL INCORRECTA
├── .env.production.example            ✅ Template correcto
└── vite.config.ts                     ⚙️ Build config
```

---

## 📌 Checklist de Acções Recomendadas

### **Críticas (Fazer AGORA)**
- [ ] Corrigir `frontend/.env.production` para `https://api.vendaslive.com`
- [ ] Adicionar `/api` em `TenantRegistration.tsx` linha 125
- [ ] Testar registo de tenant em staging/produção

### **Importantes (Próxima Sprint)**
- [ ] Migrar `ResetDataDialog.tsx` para usar `fetchWithAuth()`
- [ ] Considerar migrar `AuthContext.tsx` user check para `fetchWithAuth()`
- [ ] Actualizar comentários em `api.ts` (linha 20)
- [ ] Adicionar testes E2E para tenant registration flow

### **Melhorias (Backlog)**
- [ ] Consolidar todos os fetch directos em `fetchWithAuth()`
- [ ] Adicionar retry logic ao axios interceptor
- [ ] Implementar circuit breaker para API failures
- [ ] Adicionar métricas de performance (latência de requests)

---

## 📖 Glossário

| Termo | Significado |
|-------|-------------|
| **API_URL** | Base URL da API (sem `/api` no final) |
| **Axios Interceptor** | Middleware que adiciona headers automaticamente |
| **baseURL** | Propriedade do axios.create() que define URL raiz |
| **Fetch Wrapper** | `fetchWithAuth()` - função helper para fetch nativo |
| **getTenantSlug()** | Função que extrai tenant de subdomain ou localStorage |
| **Tenant Slug** | Identificador único do tenant (e.g., 'demo', 'acme') |
| **X-Tenant** | Header HTTP que identifica o tenant na request |
| **ULID** | Universally Unique Lexicographically Sortable Identifier |
| **FormData** | API do browser para uploads de ficheiros |
| **multipart/form-data** | Content-Type para file uploads |

---

## 🔗 Referências

- **Laravel 11 Routing:** [https://laravel.com/docs/11.x/routing](https://laravel.com/docs/11.x/routing)
- **Axios Documentation:** [https://axios-http.com/docs/intro](https://axios-http.com/docs/intro)
- **Vite Env Variables:** [https://vitejs.dev/guide/env-and-mode.html](https://vitejs.dev/guide/env-and-mode.html)
- **Fetch API MDN:** [https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API](https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API)

---

**Fim do Relatório**  
**Gerado em:** 20 de Novembro de 2025  
**Versão:** 1.0  
**Autor:** AI Analysis Tool

---

# 📜 CHANGELOG (20 Nov 2025)

## ✅ 1. Corrigido: Falha no check de slug (404)
**Arquivo:** `frontend/src/components/Auth/TenantRegistration.tsx`  
**Descrição:** A verificação de disponibilidade do slug estava a chamar um endpoint sem o prefixo `/api`.

**Antes:**
```ts
api.get(`/tenants/check-slug`, { params: { slug } });
```

**Depois:**
```ts
api.get(`/api/tenants/check-slug`, { params: { slug } });
```

**Impacto Resolvido:**  
- `https://api.vendaslive.com/tenants/check-slug` → 404  
- Agora chama correctamente `https://api.vendaslive.com/api/tenants/check-slug`  
- Registo de tenant funciona novamente.

---

## ✅ 2. Backend: Base de dados central inicializada correctamente
**Descrição:** A base de dados central não tinha as tabelas necessárias (`tenants`, `domains`, etc.).  
Foi executado:

```bash
php artisan migrate --force
```

**Impacto Resolvido:**
- Login deixava de funcionar (erro `Base table or view not found: tenants`).
- Middleware de tenancy deixava de inicializar a ligação ao tenant.
- Sistema agora detecta tenants e cria tenants correctamente.

---

## ✅ 3. Confirmado: Variáveis de produção já estão correctas
**Arquivo:** `frontend/.env.production`

A análise automática do Copilot indicava que ainda existia:
```
VITE_API_URL=http://api.localhost
```

Mas foi confirmado que o ficheiro está **correcto**:

```
VITE_API_URL=https://api.vendaslive.com
VITE_APP_URL=https://vendaslive.com
VITE_ENV=production
```

**Impacto:** Nenhum. Apenas actualização da documentação.

---

## 🔧 4. Melhorias Futuras Identificadas (não urgentes)
- Migrar `ResetDataDialog.tsx` e `AuthContext` (user check) para `fetchWithAuth()`.
- Corrigir comentários desactualizados em `api.ts`.
- E2E tests para o fluxo de criação de tenant.
