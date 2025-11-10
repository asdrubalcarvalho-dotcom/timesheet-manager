Excellent — here’s the English version of the full document, rewritten to sound natural, precise, and fully aligned with a professional AI-assisted development workflow.

The file name clearly signals that it defines requirements for implementing the Planning Gantt system, so you can use it with GitHub Copilot, ChatGPT, or any local AI tool.

📄 AI_REQUIREMENTS_PLANNING_GANTT.md

⸻

AI_REQUIREMENTS_PLANNING_GANTT.md

🧩 Version & Compatibility — Automatic Validation

Purpose: Ensure that any AI-generated code (ChatGPT, Copilot, Codex, etc.) is compatible with the current Laravel project version and environment before generation.

🔍 Mandatory Pre-Checks for Any AI or Script
	1.	Check project versions:
	•	Read composer.json → "laravel/framework" version
	•	Read .env or use php artisan --version
	•	Read package.json for JavaScript dependencies
	2.	If the project version is older than required:
	•	❌ Do not generate code.
	•	Respond with a message such as:
“⚠️ The project version is older than the supported one. Please upgrade Laravel and dependencies before continuing.”
	3.	If the project version is newer than tested:
	•	✅ Validate backward compatibility (Eloquent, Routing, Vite, Model casting).
	•	If breaking changes are detected, list affected files and stop before generating code.
	4.	Only after compatibility is confirmed, the AI may generate migrations, controllers, views, or routes.

⸻

📋 Reference Versions

Component	Minimum Supported	Tested Up To	Notes
Laravel	11.0	11.x	Uses Vite and auto-namespaced routes
PHP	8.3	8.3.x	Requires typed properties
Node.js	18.x	22.x	Needed for Vite & frappe-gantt
frappe-gantt	0.6.1	0.6.x	MIT License
axios	1.7	1.7.x	For REST communication
MySQL / MariaDB	8.0 / 10.6	8.3	Full JSON + FK support
Composer	2.7	2.8	PHP 8.3+ required
NPM	9	10	Vite build support


⸻

🎯 Goal

Implement a Project Planning and Scheduling System in Laravel 11 with:
	•	Projects
	•	Tasks
	•	Resources (people, teams, machines)
	•	Locations
	•	Interactive Gantt view (frappe-gantt)
	•	Full REST API for CRUD and planning synchronization.

⸻

🧱 Stack & Conventions
	•	Backend: Laravel 11, PHP ≥ 8.3
	•	Frontend: Vite, frappe-gantt (MIT), axios
	•	ORM: Eloquent
	•	Coding Style: PSR-12, models in singular (Project, Task, etc.)
	•	Date Format: ISO YYYY-MM-DD
	•	Database: MySQL or PostgreSQL
	•	Environment: Offline-ready, fully on-prem compatible

⸻

📦 Expected Directory Structure

app/
  Http/Controllers/
    GanttController.php
    PlanningController.php
  Models/
    Project.php
    Task.php
    Resource.php
    Location.php

database/
  migrations/
  seeders/

resources/
  views/gantt.blade.php
  js/gantt.js

routes/
  api.php
  web.php


⸻

🧩 Database Schema
	1.	Projects
	•	id, name (string), timestamps
	2.	Tasks
	•	id, project_id (FK)
	•	name (string), start_date (date), end_date (date)
	•	progress (tinyInteger 0–100)
	•	dependencies (nullable, comma-separated IDs)
	•	timestamps
	3.	Resources + Project-Resource (pivot)
	•	resources: id, name, type (nullable), meta (json), timestamps
	•	project_resource: project_id, resource_id, unique pair
	4.	Locations + Location-Task (pivot)
	•	locations: id, name, country, timezone, meta (json)
	•	location_task: location_id, task_id, unique pair
	5.	(Optional) Resource-Task (pivot)
	•	id, resource_id, task_id, allocation (0–100%)

All FKs must use cascadeOnDelete().
Models should include proper casts for JSON and date attributes.

⸻

🧠 Model Relationships
	•	Project hasMany Task
	•	Project belongsToMany Resource
	•	Task belongsTo Project
	•	Task belongsToMany Location
	•	Task belongsToMany Resource (with pivot allocation)
	•	Resource belongsToMany Project
	•	Resource belongsToMany Task
	•	Location belongsToMany Task

⸻

🔌 REST API Routes

API – routes/api.php

GET    /api/tasks?project_id={id}
PATCH  /api/tasks/{task}
GET    /api/projects
GET    /api/projects/{project}/plan
POST   /api/projects/{project}/resources
DELETE /api/projects/{project}/resources/{resource}
POST   /api/tasks/{task}/locations
DELETE /api/tasks/{task}/locations/{location}
POST   /api/tasks/{task}/resources
PATCH  /api/tasks/{task}/resources/{resource}
DELETE /api/tasks/{task}/resources/{resource}

WEB – routes/web.php

GET /gantt → gantt.blade.php


⸻

🧾 API Contracts

GET /api/tasks

{
  "project": 1,
  "tasks": [
    {
      "id": "3",
      "name": "API Payroll – MVP",
      "start": "2025-11-07",
      "end": "2025-11-14",
      "progress": 10,
      "dependencies": "1"
    }
  ]
}

PATCH /api/tasks/{id}

{
  "name": "API Payroll – MVP",
  "start": "2025-11-08",
  "end": "2025-11-15",
  "progress": 40,
  "dependencies": "1,2"
}

Response:

{ "ok": true }

GET /api/projects

[
  { "id": 1, "name": "Payroll Offline – Sprint 1", "tasks_count": 5, "resources_count": 3 }
]

GET /api/projects/{project}/plan

{
  "project": { "id": 1, "name": "Payroll Offline – Sprint 1" },
  "resources": [
    { "id": 7, "name": "Ana Silva", "type": "person" },
    { "id": 8, "name": "Backend Team", "type": "team" }
  ],
  "tasks": [
    {
      "id": "3",
      "name": "Gantt UI Integration",
      "start": "2025-11-12",
      "end": "2025-11-18",
      "progress": 0,
      "dependencies": "1,2",
      "locations": [
        { "id": 4, "name": "Lisbon HQ" },
        { "id": 5, "name": "Porto DC" }
      ],
      "resources": [
        { "id": 7, "name": "Ana Silva", "allocation": 60 }
      ]
    }
  ]
}


⸻

🖼️ Front-End (Gantt)
	•	Blade: resources/views/gantt.blade.php
	•	Script: resources/js/gantt.js
	•	Libraries: frappe-gantt, axios

Core JS flow:
	1.	Fetch GET /api/tasks?project_id=...
	2.	Initialize:

new Gantt('#gantt', tasks, { view_mode: 'Week' });


	3.	On date change → PATCH /api/tasks/{id}
	4.	On progress change → PATCH /api/tasks/{id}
	5.	Log click events for debugging.

⸻

🧪 Demo Seeders

GanttDemoSeeder

Creates a base project with three linked tasks.

PlanningDemoSeeder

Adds resources, locations, and connects them to the project and tasks.

Run:

php artisan migrate --seed --class=GanttDemoSeeder
php artisan db:seed --class=PlanningDemoSeeder


⸻

✅ AI Development Rules (ChatGPT / Copilot / Codex)
	1.	Always confirm framework versions first.
	2.	If below minimum → stop and alert.
	3.	If above → check for breaking changes.
	4.	Never rename models, tables, or columns listed here.
	5.	Validate inputs (date, integer 0–100, after_or_equal).
	6.	Follow JSON response contracts exactly.
	7.	Keep dependencies as a comma-separated string.
	8.	Use axios for all frontend API calls.
	9.	Avoid CSS frameworks; use plain styles.
	10.	When adding new attributes, update this document accordingly.
	11.	Prefer FormRequest validation classes for complex inputs.

⸻

🧩 Future Enhancements (for AI Tasks)
	•	Filters in Gantt (by resource or location)
	•	Task locking on national holidays (using holiday_list.json)
	•	Resource capacity and overbooking alerts
	•	PDF/PNG Gantt export
	•	Full offline compatibility with Payroll AI Offline

⸻

⚙️ Optional Pre-Check Script

Add this shell script to validate environment before builds or AI actions:

# check_ai_compat.sh
#!/bin/bash
LARAVEL=$(php artisan --version | grep -oE '[0-9]+\.[0-9]+')
PHPV=$(php -v | head -n1 | grep -oE '[0-9]+\.[0-9]+')
if (( $(echo "$LARAVEL < 11.0" | bc -l) )); then
  echo "⚠️ Laravel $LARAVEL is below 11.0 — update before generating AI code."
  exit 1
fi
if (( $(echo "$PHPV < 8.3" | bc -l) )); then
  echo "⚠️ PHP $PHPV is below 8.3 — incompatible with this guide."
  exit 1
fi
echo "✅ Compatible versions detected: Laravel $LARAVEL / PHP $PHPV"

Run manually or as a pre-commit hook:

bash check_ai_compat.sh


⸻

📘 Final Notes

This file acts as the single source of truth for AI-assisted development of the Planning Gantt System.

Before generating code, any AI must:
	1.	Read this guide.
	2.	Validate environment versions.
	3.	Confirm compatibility.
	4.	Follow this structure and contracts precisely.