# Changelog

All notable changes to TimePerk Cortex will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] - 2025-11-12

### Added
- **Multi-Tenancy Support**: Full Stancl Tenancy integration with hybrid mode
  - Subdomain-based tenant resolution for production
  - X-Tenant header support for local/development environments
  - Isolated databases per tenant (`timesheet_{slug}`)
  - Central database for tenant metadata and global migrations
  - AllowCentralDomainFallback middleware for localhost testing
  - Demo tenant `test-company` with sample data

- **Granular Rate Limiting**: Optimized throttle limits for intensive navigation
  - `read`: 200 requests/min (GET operations)
  - `create`: 30 requests/min (POST operations)
  - `edit`: 20 requests/min (PUT/PATCH operations)
  - `delete`: 10 requests/min (DELETE operations)
  - `critical`: 10 requests/min (approve/reject operations)
  - `api`: 120 requests/min (general API limit, doubled from 60)
  - All routes now have specific throttle middleware applied

### Changed
- **Demo Credentials Updated**: 
  - Old: `admin@timeperk.com`
  - New: `admin@testcompany.test` (tenant: `test-company`)
- **Login Form**: Single "Owner" demo button with test-company credentials
- **Rate Limits**: Increased global API limit from 60/min to 120/min
- **Database Configuration**: Support for multiple tenant databases

### Fixed
- **"Too Many Requests" Error**: Resolved by implementing granular rate limiting
  - Separated read operations (200/min) from write operations
  - Applied specific throttle to all GET routes (expenses, timesheets, projects, etc.)
  - Prevents rate limit errors during rapid navigation between pages

### Documentation
- Updated `.github/copilot-instructions.md` with:
  - Multi-tenancy configuration section
  - Demo credentials and tenant testing examples
  - Expanded rate limiting documentation
  - Common pitfalls updated (items 21-23)
- Updated `README.md` with:
  - Multi-Tenancy Architecture section
  - Intelligent Rate Limiting section
  - Updated demo credentials
  - Docker services description updated
  - Role-Specific Validation Rules table updated

### Technical Details
- **Environment Variables** (`.env`):
  ```env
  TENANCY_ALLOW_CENTRAL_FALLBACK=true
  TENANCY_FALLBACK_ENVIRONMENTS=local,development,testing
  CENTRAL_DOMAINS=127.0.0.1,localhost,app.timeperk.localhost
  TENANCY_HEADER=X-Tenant
  ```

- **Rate Limiter Configuration** (`RouteServiceProvider.php`):
  - Added `read` limiter (200/min)
  - Increased `api` limiter to 120/min
  - All limiters use user ID or IP for isolation

- **Route Middleware** (`routes/api.php`):
  - All GET routes: `throttle:read`
  - All POST routes: `throttle:create`
  - All PUT/PATCH routes: `throttle:edit`
  - All DELETE routes: `throttle:delete`
  - Approve/reject routes: `throttle:critical`

## [1.1.0] - 2025-11-10

### Added
- **Finance Role System**: Third independent role dimension per project
  - `finance_role` column in `project_members` table
  - Finance manager workflow for expense approval
  - Finance stage support (`finance_review`, `finance_approved`)
  - Project Members Dialog with 3-column role selection

### Changed
- **Expense Workflow**: Multi-stage approval process
  - Stage 1: Expense Manager (`submitted` → `finance_review`)
  - Stage 2: Finance Team (`finance_review` → `finance_approved`)
  - Stage 3: Payment Processing (`finance_approved` → `paid`)

### Documentation
- Added Finance Role System documentation
- Updated permission matrix

## [1.0.0] - 2025-11-06

### Added
- Initial release with core features
- Timesheet management with calendar interface
- Expense management with grid interface
- Three-tier role system (Technician, Manager, Admin)
- 17 granular permissions
- Laravel Policies for authorization
- Admin panel with CRUD operations
- Dashboard with analytics
- Docker Compose orchestration

[Unreleased]: https://github.com/asdrubalcarvalho-dotcom/timesheet-manager/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/asdrubalcarvalho-dotcom/timesheet-manager/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/asdrubalcarvalho-dotcom/timesheet-manager/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/asdrubalcarvalho-dotcom/timesheet-manager/releases/tag/v1.0.0
