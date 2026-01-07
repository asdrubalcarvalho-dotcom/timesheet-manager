Source of Truth — Access Control & Data Visibility

This document defines the canonical and final access model for all list (READ) and mutation (CRUD) endpoints.

Any code that diverges from these rules is a BUG, not an alternative implementation.

⸻

1. Core Principles

1.1 Separation of concerns (MANDATORY)

Concern	Controlled by
Endpoint access	System roles / permissions
Data visibility (READ)	Project membership & explicit rules
Record authorization	Policies + controller guards
List filtering	Controller queries (must ≥ Policy)

❗ System roles must NOT be used to scope project data
They may gate access to endpoints, never which records are returned, except where explicitly documented.

⸻

2. Canonical Project Role Model

2.1 Technician requirement

If a user has no Technician record:

→ ALL project-scoped list endpoints MUST return an empty list

EXCEPT for explicit Owner global READ (see section 3).

This prevents accidental data exposure.

⸻

2.2 Project membership (READ baseline)

A user may see project-related data ONLY IF one of the following is true:

• They are a member of the project (project_members)
• They are a system Owner (READ-only global visibility)

No other relationship grants visibility.

⸻

2.3 Canonical Project Manager Definitions (FINAL)

a) Timesheets & Travels Manager

A user is considered a Timesheets & Travels Manager of a project ONLY IF:

project_members.project_role == 'manager'

b) Expenses Manager

A user is considered an Expenses Manager of a project ONLY IF:

project_members.expense_role == 'manager'

❗ projects.manager_id is NOT authoritative
	•	It must NOT be used for access control
	•	It may exist only for legacy, display, or migration purposes

❗ System roles (Owner/Admin) DO NOT grant project authority

⸻

3. READ Visibility Rules (LIST endpoints)

3.1 Owner global READ (EXPLICIT EXCEPTION)

A system Owner:

• Can READ all records (Timesheets, Expenses, Travels)
• Across all projects
• Regardless of project membership

This exception applies to READ only.

❌ Owner status does NOT grant CRUD authority.

⸻

3.2 Non-Owner READ behavior

For non-Owner users:

• They may READ all records belonging to projects where they are members
• This includes records created by any technician in those projects
• Manager status does NOT narrow or expand READ visibility

There is no manager-vs-manager segregation in READ.

⸻

3.3 READ summary table

User Type	READ Visibility
Owner	All records, all projects
Project member	All records of their projects
Project manager	Same as member
Non-member	No records


⸻


3.4 Phase 1 — Transitional Report Visibility (COMPLETED)

Phase 1 (temporary system-role-based elevation for Reports) is now removed.

Phase 2 is implemented: Reports now follow canonical rules (see sections 2 and 3):
• Owner: global READ (all projects)
• All other users: only records from projects where they are a member (project_members)
• Project manager roles do NOT affect READ visibility
• No system role (Admin/Manager) changes data visibility
• CRUD rules unchanged
• No new endpoints added
• No email code touched
• A user without Technician record sees empty results (except Owner)

4. CRUD Authorization Rules (STRICT)

CRUD authority is always project-role-based and domain-specific.

4.1 Create / Update rules

When creating or editing a record (Timesheet, Expense, Travel):

Case A — Acting for self

technician_id == authenticated technician

→ Allowed (subject to membership)

Case B — Acting for another technician

technician_id != authenticated technician

• User MUST be:
	•	Member of the project
	•	AND have the manager role for the relevant domain:
	    – project_role == 'manager' for Timesheets and Travels
	    – expense_role == 'manager' for Expenses

Otherwise:

→ ❌ 403 Forbidden
→ Message: “Only project managers can create records for other technicians.”

Note: Even when expense_role is used for authorization, project membership remains mandatory. Expense roles never grant cross-project authority.

⸻

4.2 Project membership is mandatory

If the authenticated user is not a member of the target project:

→ ❌ 403 Forbidden
→ Message: “You are not assigned to this project.”

This applies to ALL roles, including Owner.

⸻

4.3 No silent overrides (NON-NEGOTIABLE)

❌ Controllers MUST NOT:
	•	Rewrite technician_id to self
	•	“Fix” invalid input silently
	•	Accept-and-transform unauthorized intent

Unauthorized intent must result in an explicit 403.

⸻

5. Policies vs Lists (NON-NEGOTIABLE)

• Policies define the minimum restriction
• List queries must be EQUAL or STRICTER

❌ Fetching more rows and “masking later” is forbidden.

⸻

6. Planning & Permission-Gated Endpoints

Some endpoints are intentionally permission-gated only (no project scoping):

Examples:
• Planning projects
• Planning events

This is acceptable ONLY IF:
• Access is explicitly controlled by permissions
• The behavior is documented
• No sensitive worker or financial data is exposed

If project scoping becomes required →
This document must be updated first.

⸻

7. Copilot Rules (READ BEFORE CODING)

When modifying or creating an endpoint:

• ❌ Do NOT infer authority from system roles
• ❌ Do NOT use projects.manager_id for access control
• ❌ Do NOT copy scoping logic blindly
• ✅ Always check this file first
• ✅ Align controllers with policies
• ❗ When in doubt → return less data, not more

⸻

8. Regression Rule (SEVERITY-1)

Any future change that:

• Leaks cross-project data
• Allows CRUD without project manager role
• Uses system roles for project authority
• Ignores Technician presence

→ SEVERITY-1 regression
→ Must be fixed immediately

⸻

✅ Status

This document reflects the final, validated, and implemented access model.

If behavior differs from this document →
the code is wrong, not the rules.
