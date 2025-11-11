# 🧮 Timesheet Validation Specification — TimePerk + AI Cortex  
_Version 1.3 • Updated: 2025‑11‑08_

This playbook documents the **current** validation rules implemented in the Laravel API and React frontend, plus the roadmap for AI‑assisted checks. It should be read as a pragmatic reference, **not** a wish list—everything below mirrors what the system enforces today, with clear flags for future work.

---

## 🧱 1️⃣ Form Fields & Current Requirements

| Field | Type | Required | Validation (Today) | UI Notes |
|---|---|---|---|---|
| **Technician (Worker)** | Dropdown | ✅ (auto filled) | `technicians.id` exists **and** belongs to the project (unless admin). | Tech users locked to themselves; managers/admins can switch. |
| **Date** | Datepicker | ✅ | Valid date, ≤ today recommended (policy layer), ≥ project start (TODO). | Show warning chip when outside project window. |
| **Start Time** | Timepicker | ⚠️ Optional | Must be `< end_time` when both exist. | Used for overlap detection & AI context. |
| **End Time** | Timepicker | ⚠️ Optional | Must be `after:start_time`. | Auto nudged +15/60 min in UI to avoid invalid combos. |
| **Hours Worked** | Number | ✅ | `numeric|min:0.25|max:24`. | Main driver for duration; slider + numeric input recommended. |
| **Project** | Dropdown | ✅ | `projects.id` exists, status = `active`, technician is member (or admin override). | Chips display TS/EXP membership. |
| **Task** | Dropdown | ✅ | `tasks.project_id = selected project`. | Filtered list; disables until project chosen. |
| **Location** | Dropdown | ✅ | Must exist & be active. | Shows city/country + coordinates if available. |
| **Description** | Textarea | ✅ | `string|min:3|max:1000`. | Live counter; AI suggestions inserted here. |
| **Billable** | Checkbox | ❌ | Boolean (future). | Stub for upcoming billing module. |
| **Overtime** | Checkbox | ❌ | Boolean (future). | Appears when `hours_worked > 8h`. |
| **AI Warnings** | Banner | – | Populated by AI Cortex service (future). | Collapsible warning box + confirm checkbox. |

---

## 🧩 2️⃣ Backend Business Rules (Laravel)

### Deterministic (Implemented)
- `hours_worked` must be between **0.25** and **24** hours.  
- `start_time < end_time` when both are supplied.  
- **No overlapping** entries per technician/date (`StoreTimesheetRequest::hasTimeOverlap`).  
- Project must be **active** and the technician must be a member (unless admin).  
- Task must belong to the selected project; location must exist.  
- Only `draft` or `rejected` entries can be edited; only `draft/rejected` can be submitted.  
- Only managers/admins (with policy permission) can approve, reject, close, or reopen.  
- `TimesheetPolicy` guards submission/approval/reopen sequences.

### Laravel Snippet (current request)
```php
$request->validate([
    'technician_id' => 'nullable|exists:technicians,id',
    'project_id'    => 'required|exists:projects,id',
    'task_id'       => 'required|exists:tasks,id',
    'location_id'   => 'required|exists:locations,id',
    'date'          => 'required|date',
    'start_time'    => 'nullable|date_format:H:i',
    'end_time'      => 'nullable|date_format:H:i|after:start_time',
    'hours_worked'  => 'required|numeric|min:0.25|max:24',
    'description'   => 'required|string|min:3|max:1000',
]);
```

---

## 🧠 3️⃣ Cognitive Validation (Planned Integration)

| Stage | Description |
|---|---|
| 1. Deterministic pass | Entry clears Laravel validation + overlap guard. |
| 2. AI call | `TimesheetAIService` produces anomaly score, task/project consistency, temporal deviation. |
| 3. Feedback | API returns `{ ai_score, ai_flagged, ai_feedback[] }`. |
| 4. User decision | Dialog shows ⚠️ banner + “Confirm anyway” checkbox. |
| 5. Persistence | If user proceeds, `ai_flagged=true` stored for audits. |

Example payload:
```json
{
  "anomaly_score": 0.82,
  "issues": [
    "Start time deviates from technician's typical schedule.",
    "Task rarely mapped to this project."
  ]
}
```

---

## 🧭 4️⃣ Frontend Validation (TimesheetCalendar.tsx)

- Technician selection locked unless manager/admin.  
- Date/Time pickers auto-adjust to avoid invalid combos.  
- Project/Task cascading selects with membership chips.  
- Description character count & AI suggestion chips.  

### UX Boosters (Recommended)
1. **Hours widget**: dual control (slider + numeric) auto-syncing with start/end.  
2. **Daily total badge**: updates instantly (`⏰ Today: 6.5h / 12h`).  
3. **Overlap toast**: light warning before hitting API.  
4. **AI banner toggle**: collapsed by default; badge shows count of flagged hints.

---

## 🔐 5️⃣ Status Lifecycle (Current)

| Status | Allowed Actions | Roles | Notes |
|---|---|---|---|
| `draft` | Edit / Delete / Submit | Technician | Default state. |
| `submitted` | Approve / Reject | Manager/Admin (project membership) | Locked for technician. |
| `approved` | Close | Manager/Admin | Technician read-only. |
| `rejected` | Edit / Resubmit | Technician | Must address manager feedback. |
| `closed` | Reopen | Admin | Requires justification. |
| `ai_flagged`* | Review | Manager + AI Cortex | Stored metadata once AI lands. |

\*AI status is conceptual until fields are added.

---

## 🔄 6️⃣ Visual Feedback Cheat‑Sheet

- 🟢 **Green** — passes all checks.  
- 🟡 **Yellow** — AI warning, user can override.  
- 🔴 **Red** — hard validation error (block save).  
- 🧠 **Tooltip** — “Auto-validated by AI Cortex” when no anomalies detected.  
- ⏰ **Badge** — displays accumulated hours for the selected date.

---

## 💾 7️⃣ Database Snapshot

| Field | Type | Notes |
|---|---|---|
| `technician_id` | FK | Required. |
| `project_id` | FK | Required. |
| `task_id` | FK | Required. |
| `location_id` | FK | Required. |
| `date` | Date | Required. |
| `start_time`, `end_time` | Time (nullable) | Optional clocks. |
| `hours_worked` | Decimal(5,2) | Required. |
| `lunch_break` | Integer | Defaults to 30 min. |
| `hour_type` | Enum | `working|travel|standby|rest|on_scope|off_scope`. |
| `check_out_time` | Time | Optional. |
| `machine_status` | Enum | `online|offline`. |
| `job_status` | Enum | `completed|ongoing`. |
| `status` | Enum | `draft|submitted|approved|rejected|closed`. |
| `ai_flagged`* | Boolean | Planned column. |
| `ai_score`* | Decimal(3,2) | Planned column. |
| `ai_feedback`* | JSON | Planned column. |

---

## 🚀 8️⃣ Implementation Checklist
1. Run `StoreTimesheetRequest` + policy checks.  
2. Persist record (status defaults to `draft`).  
3. Use `TimesheetValidationService::summarize($timesheet)` to attach the validation snapshot to API responses (`store`, `update`, `show`, and `/timesheets/{id}/validation`).  
4. Require user confirmation when AI raises warnings.  
5. Store `ai_*` metadata when confirmation occurs.  
6. Show AI + validation badges consistently across calendar/list views.  

---

## 📚 9️⃣ Future Enhancements
- Incremental AI training from confirmed entries.  
- AI Quality dashboard (per technician/project anomaly ratio).  
- Adaptive limits (daily max based on historic patterns).  
- Billable/overtime auto defaults from task metadata.  
- Geo-fence validation (location vs. project coordinates).

---

## ⚙️ 🔟 Suggested “Validation Snapshot” Object

To keep validation **fast** and user-friendly (many checks per day), share a compact object between UI and API:

```ts
type TimesheetValidationSnapshot = {
  technicianId: number;
  projectId: number;
  taskId: number;
  locationId: number;
  date: string;            // ISO yyyy-mm-dd
  startTime?: string;      // HH:mm
  endTime?: string;
  hoursWorked: number;
  dailyTotalHours: number;
  overlapRisk: 'ok' | 'warn' | 'block';
  membershipOk: boolean;
  aiWarnings?: string[];
};
```

**Flow**
1. **Client** recomputes snapshot locally on every change (instant badges/toasts).  
2. Snapshot is sent alongside the payload; **server** recalculates authoritative values and returns diffs if something changed (e.g., “dailyTotalHours exceeded 12h while saving”).  
3. The same structure can batch-validate multiple rows (bulk approvals) without heavy recomputation.  

**Benefits**
- ⚡ **Speed**: Users see overlap/hour/membership hints immediately.  
- 🤝 **Consistency**: One schema drives UI, API, logs, and AI prompts.  
- 📈 **Extensibility**: Add `billable`, GPS, or overtime justification fields later without rewiring everything.

---

Questions or proposals? Ping the Timesheet task force in `#timeperk-timesheets`.  
Next review: align with AI Cortex MVP once anomaly fields land in DB.
