// IMPORTANT:
// Planning Locations reuses the existing Task ↔ Locations logic from Admin.
// Do NOT introduce new CRUD or backend changes here.
/*
CONTEXT (READ CAREFULLY):

We already have LIVE production support for managing task ↔ location relationships.

DATA MODEL (ALREADY IMPLEMENTED — DO NOT CHANGE):
- Each Task belongs to ONE Project.
- A Task can be associated with MULTIPLE Locations.
- Locations are NOT owned by Tasks; they are related (many-to-many).
- This model is already live and used in Admin → Tasks.
- Planning must reuse this exact model and backend logic.

GOAL:
Extend the Planning Locations view to allow managing a task’s locations,
REUSING the existing Admin Tasks logic and endpoints.

THIS IS NOT A BACKEND TASK.
DO NOT change models, migrations, controllers, or routes.

---

## PLANNING LOCATIONS — UX REQUIREMENTS

Hierarchy in Planning Locations:
Location → Project → Task

Add ONE action on TASK rows ONLY:
- Action label: “Manage Locations”
- Trigger: icon button or context menu
- No actions on Location or Project rows

When the action is triggered:
- Open the SAME modal (or a lightweight reused version) that already exists in:
  Admin → Tasks → “Manage Locations for Task”
- The modal must:
  - Load all available locations
  - Preselect the locations already assigned to the task
  - Allow multi-select
  - Have Save / Cancel actions

On Save:
- Update the Task ↔ Locations relationship using the SAME endpoints as Admin
- Close the modal
- Refresh the Planning Locations Gantt
- The task must appear under ALL selected locations
- The task must be removed from locations that were unselected

IMPORTANT:
- Managing locations MUST NOT change the task’s project.
- The task remains under the same project everywhere.

---

## TECHNICAL CONSTRAINTS (MANDATORY)

- Reuse existing Admin endpoints for task-location management
- Reuse existing location list logic
- Reuse existing payload formats
- Refresh Gantt data after save (no full page reload)

DO NOT:
- ❌ Create new endpoints
- ❌ Modify backend controllers or models
- ❌ Introduce new CRUD for Locations
- ❌ Duplicate Admin business logic
- ❌ Add task cloning or task moving between projects
- ❌ Touch Planning Projects or Planning Users
- ❌ Refactor core Gantt logic

---

## ARCHITECTURAL PRINCIPLE

Admin defines and governs data.
Planning operates on existing data in a contextual way.

Planning Locations must reflect EXACTLY the same state as Admin Tasks.
Any change done in Planning must be immediately visible in Admin and vice versa.

---

## FINAL ACCEPTANCE CRITERIA

- A task can be linked to multiple locations.
- The same task appears under multiple locations in Planning.
- “Manage Locations” in Planning behaves the same as Admin.
- No regressions in other Planning views.
- No backend changes.
*/

import React from 'react';

import PlanningGantt from './PlanningGantt';

const PlanningGanttLocations: React.FC = () => {
  return <PlanningGantt initialView="locations" />;
};

export default PlanningGanttLocations;
