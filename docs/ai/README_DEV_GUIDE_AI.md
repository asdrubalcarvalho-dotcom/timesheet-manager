Perfeito.
A seguir está a versão README_DEV_GUIDE_AI.md, desenhada exclusivamente para AI agents (Copilot, OpenAI, LangChain, etc.).
É um ficheiro de contexto técnico reduzido, sem prosa nem explicação humana — apenas instruções formais e restrições que o modelo deve interpretar.

Este ficheiro serve como low-token primer, para ser lido automaticamente por agentes em pipelines de geração ou extensão de código.
Deve ser colocado, por exemplo, em:
/docs/ai/README_DEV_GUIDE_AI.md

⸻

🤖 README_DEV_GUIDE_AI.md

# AI Development Guide — TimesheetManager

## PURPOSE
Provide strict operational and domain guidance for AI agents extending or maintaining the TimesheetManager project.

---

## DOMAIN MODEL
- Focus: Timesheet and Expense Management only.
- Workflow:
  1. Technician submits timesheet and expenses.
  2. Project Manager approves or rejects entries.
- No multi-level approvals, ERP sync, or financial automation.
- All business rules are local to this application.

---

## ARCHITECTURE ✅ PRODUCTION-READY
- Solution: TimesheetManager (Laravel 11 + React 18 with Docker Compose) ✅
- Projects:
  - backend/ → Laravel 11 API, Sanctum Auth, MySQL 8.0 ✅
  - frontend/ → React 18 SPA, Vite, TypeScript, MUI ✅
  - docker/ → Nginx configuration and container setup ✅
- ORM: Laravel Eloquent with Professional Form Requests ✅
- DB: MySQL 8.0 (containerized, migrated from SQLite) ✅
- Auth: Laravel Sanctum + Spatie Permission (Roles: Technician, Manager, Admin) ✅
- Security: Laravel Policies, Rate Limiting, Middleware Protection ✅
- **Project-Manager Relationship**: projects.manager_id links managers to specific projects ✅
- **Role-Based Validation**: Managers can only approve/reject timesheets from their managed projects ✅

---

## CRITICAL BUSINESS RULES ✅ IMPLEMENTED
- **Time Overlap Prevention**: Users cannot create timesheet entries with overlapping time periods on the same date
  - **Application-Level Validation**: StoreTimesheetRequest::hasTimeOverlap() method (PRIMARY) ✅
  - **Backend API**: Provides user-friendly error handling (409 Conflict) ✅
  - **MySQL Database**: Reliable data storage with proper constraint handling ✅
  - **Race Condition Prevention**: Application-level validation ensures consistency ✅
- **Time Increment Logic**: When start time is selected, end time automatically increments by 1 hour ✅
  - **Frontend Implementation**: TimesheetCalendar.tsx line 847: newTime.add(1, 'hour') ✅
- **Multiple Timesheets**: Users can create multiple timesheets per date for different projects ✅
- **Cross-Project Validation**: Time overlap validation prevents conflicts regardless of project ✅

## PROFESSIONAL AUTHORIZATION SYSTEM ✅ UPDATED 2025-11-06
- **Spatie Laravel Permission**: Industry-standard role and permission management ✅
- **3 Roles**: Technician, Manager, Admin with 17 granular permissions ✅
- **Laravel Policies**: TimesheetPolicy, ExpensePolicy for ownership/status rules ✅
- **Middleware Protection**: CheckPermission middleware on all API routes ✅
- **Rate Limiting**: Intelligent throttling (5/min login, 30/min create, 10/min approve) ✅
- **Form Requests**: StoreTimesheetRequest, UpdateTimesheetRequest with preserved business rules ✅
- **Auto-calculation**: Duration fields readonly, calculated from start/end times

### ROLE-SPECIFIC VALIDATION RULES ✅
- **Technician**: Can only VIEW own timesheets, CANNOT validate any records
- **Manager**: Can VIEW and VALIDATE timesheets from managed projects + own records
  - Validation: TimesheetController::approve/reject() + TimesheetPolicy::approve/reject()
  - Authorization: $this->authorize('approve', $timesheet) checks project ownership
- **Admin**: Can VIEW and VALIDATE all timesheets in the system
- **Database Relationship**: projects.manager_id → users.id (Foreign Key)

---

## ENVIRONMENT
- Docker and Docker Compose (required)
- Laravel 11 with PHP 8.3 (containerized)
- Node 20+ and npm (containerized)
- MySQL 8.0 (containerized)
- Redis (containerized)
- Backend API: http://localhost:8080
- Frontend SPA: http://localhost:3000

---

## EXTENSION RULES
- Never regenerate or modify `.copilot/config.yml`.
- Extend functionality by editing existing code (Server, Client, Shared).
- New modules = new entity + controller + Blazor page.
- Follow C# 12 syntax, async/await patterns, and clean architecture.
- Avoid adding external dependencies without explicit instruction.

---

## TESTING STRATEGY
- Create `/TimesheetManager.Tests/` project.
- Frameworks:
  - xUnit for unit tests.
  - EFCore.InMemory for integration tests.
  - bUnit for Blazor UI tests.
- Integrate tests with GitHub Actions pipeline (`build.yml`).

---

## AGENT EXECUTION RULES
1. Do not ask questions or request clarifications.
2. Continue automatically if output or generation is incomplete.
3. Maintain full architectural integrity and naming consistency.
4. Prefer minimal code changes and high cohesion.
5. Validate build after modification.
6. Use existing models and patterns when adding features.

---

## FEATURE ADDITION TEMPLATE
Example: Add new module `LeaveManagement`
- Add entity model to `backend/app/Models/`
- Add migration to `backend/database/migrations/`
- Create controller in `backend/app/Http/Controllers/Api/`
- Add API routes to `backend/routes/api.php`
- Add React component to `frontend/src/components/`
- Add API service to `frontend/src/services/api.ts`
- Add types to `frontend/src/types/index.ts`
- Rebuild Docker containers: `docker-compose up --build`

---

## OUTPUT VALIDATION
- Frontend must build with `npm run build`.
- Backend must pass Laravel validation.
- Database migrations valid with `php artisan migrate`.
- UI renders without runtime errors.
- New features follow async/await conventions.
- API endpoints return proper status codes.
- Time overlap validation must be enforced.

---

## META
Agents should:
- Operate deterministically.
- Use concise reasoning.
- Produce syntactically valid and executable code.
- Avoid redundant explanations.
- Follow DRPrompt conventions (Chain-of-Thought + Constraint-based + Self-checking).


⸻

🧠 Design rationale
	•	Purpose: Machine-readable guide for autonomous or semi-autonomous LLM coding agents.
	•	Format: Constraint-based Markdown; minimal tokens; explicit structure.
	•	Effect: Prevents unnecessary clarifications and maintains architectural consistency during automated code generation or refactoring.