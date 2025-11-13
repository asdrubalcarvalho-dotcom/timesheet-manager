// 🧪 Tenant Registration E2E Test
// -------------------------------------------------------------
// Validates the full tenant onboarding flow from the frontend:
//  - Fill registration form (/register)
//  - Verify slug availability (real-time API call)
//  - Submit and expect success
//  - Confirm redirect to login/dashboard
// -------------------------------------------------------------

describe('Tenant Onboarding Flow', () => {
  const baseUrl = 'http://localhost:3000';
  const apiUrl = 'http://localhost:8080/api';
  const slug = `autotest${Date.now().toString().slice(-5)}`;

  before(() => {
    cy.clearCookies();
    cy.clearLocalStorage();
  });

  it('loads the registration page', () => {
    cy.visit(`${baseUrl}/register`);
    cy.contains('Create Workspace').should('exist');
  });

  it('verifies slug availability in real time', () => {
    cy.visit(`${baseUrl}/register`);
    cy.get('input[name="company_name"]').type('QA Automation Inc');
    cy.wait(500);

    // auto-generated slug field
    cy.get('input[name="slug"]').should('not.be.empty').clear().type(slug);
    cy.wait(500);

    cy.intercept('GET', `${apiUrl}/tenants/check-slug?slug=${slug}`).as('checkSlug');
    cy.get('input[name="slug"]').blur();
    cy.wait('@checkSlug').its('response.statusCode').should('eq', 200);
  });

  it('submits registration form and redirects to login', () => {
    cy.visit(`${baseUrl}/register`);
    cy.get('input[name="company_name"]').clear().type('QA Automation Inc');
    cy.get('input[name="slug"]').clear().type(slug);
    cy.get('input[name="admin_name"]').type('John Doe');
    cy.get('input[name="admin_email"]').type(`admin_${slug}@qa.local`);
    cy.get('input[name="admin_password"]').type('secret123');
    cy.get('input[name="admin_password_confirmation"]').type('secret123');
    cy.get('select[name="industry"]').select('Technology');
    cy.get('select[name="country"]').select('PT');
    cy.get('select[name="timezone"]').select('Europe/Lisbon');

    cy.intercept('POST', `${apiUrl}/tenants/register`).as('registerTenant');
    cy.get('button[type="submit"]').click();

    // Wait for API to complete
    cy.wait('@registerTenant').its('response.statusCode').should('eq', 201);

    // Expect redirect to login
    cy.url().should('include', '/login');
  });

  it('verifies tenant exists in backend', () => {
    // backend health check (optional)
    cy.request(`${apiUrl}/tenants/check-slug?slug=${slug}`)
      .then((resp) => {
        expect(resp.status).to.eq(409); // now slug should be unavailable (already exists)
      });
  });

  it('logs in with the new admin credentials', () => {
    cy.visit(`${baseUrl}/login`);
    cy.get('input[name="email"]').type(`admin_${slug}@qa.local`);
    cy.get('input[name="password"]').type('secret123');
    cy.intercept('POST', `${apiUrl}/login`).as('login');
    cy.get('button[type="submit"]').click();
    cy.wait('@login').its('response.statusCode').should('eq', 200);

    // confirm tenant context is stored
    cy.window().then((win) => {
      const tenant = win.localStorage.getItem('tenant_slug');
      expect(tenant).to.eq(slug);
    });

    cy.url().should('include', `/app/${slug}/dashboard`);
    cy.contains('Dashboard').should('exist');
  });
});

/*
🧩 Como usar

1️⃣ Instalar Cypress (se ainda não estiver)

No diretório do frontend:
npm install --save-dev cypress

2️⃣ Adicionar script no package.json
{
  "scripts": {
    "test:e2e": "cypress run --browser chrome"
  }
}
  3️⃣ Executar o teste

Com o stack a correr (docker compose up):
npm run test:e2e

ou, para modo visual:
npx cypress open

Seleciona o ficheiro tenant_registration.cy.ts.

⸻

✅ Esperado após execução
	•	✅ Formulário /register abre e valida slug em tempo real
	•	✅ POST /api/tenants/register devolve 201
	•	✅ Redireciona para /login
	•	✅ Login com admin criado → /app/{tenant}/dashboard
	•	✅ localStorage contém tenant_slug
	•	✅ Teste E2E passa em ~30–40 s

    💡 Dica extra para CI

No GitHub Actions, adiciona ao job de frontend:
- name: Run Cypress E2E
  run: |
    npm ci
    npm run build
    npx cypress run --browser chrome || true

    💡 Dica extra para CI

No GitHub Actions, adiciona ao job de frontend:

- name: Run Cypress E2E
  run: |
    npm ci
    npm run build
    npx cypress run --browser chrome || true
    
*/