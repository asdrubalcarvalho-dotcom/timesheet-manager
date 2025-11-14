# COPILOT — FULL IMPLEMENTATION TASK LIST  
### Travel Segments Datetime + Full Travel System Upgrade  
(Backend + Frontend — Implement Everything from Start to Finish)

This document defines the exact sequence of tasks Copilot must execute to fully implement the updated Travel Segments system, including datetime support, duration computation, backend logic, frontend UI, API updates, and groundwork for Timesheet + Approvals integration.

Copilot must follow these steps **EXACTLY in this order**, without skipping, merging, or modifying requirements unless explicitly instructed.

---

# 1. Backend — Database Layer

## 1.1 Create Tenant Migration (Add Datetime Fields)

Create a new tenant migration under:
```
backend/database/migrations/tenant/
```

Migration must add:

```php
$table->dateTime('start_at')->nullable()->after('travel_date');
$table->dateTime('end_at')->nullable()->after('start_at');
$table->unsignedInteger('duration_minutes')->nullable()->after('end_at');
```

Do **not** remove `travel_date`.

---

# 2. Backend — Model Update (`TravelSegment.php`)

Modify file:
```
backend/app/Models/TravelSegment.php
```

## 2.1 Add to `$fillable`:
```php
'start_at', 'end_at', 'duration_minutes',
```

## 2.2 Add to `$casts`:
```php
'start_at' => 'datetime',
'end_at' => 'datetime',
'duration_minutes' => 'integer',
```

## 2.3 Add auto-calculation logic:

```php
protected static function booted()
{
    static::saving(function (TravelSegment $segment) {

        if ($segment->start_at) {
            $segment->travel_date = $segment->start_at->toDateString();
        }

        if ($segment->start_at && $segment->end_at) {
            $segment->duration_minutes =
                $segment->end_at->diffInMinutes($segment->start_at);
        }
    });
}
```

---

# 3. Backend — Validation Requests

## 3.1 StoreTravelSegmentRequest

Rules must include:

```php
'start_at' => ['required', 'date'],
'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
```

Conditional:

- If `status = completed`, then `end_at` is required.

## 3.2 UpdateTravelSegmentRequest

Same rules, but all optional.

---

# 4. Backend — Controller Adjustments

Update `store()` and `update()` in:
```
backend/app/Http/Controllers/Api/TravelSegmentController.php
```

- Accept and apply `start_at` and `end_at`
- Do NOT calculate duration here
- Refresh model on return:

```php
return response()->json([
   'data' => $segment->fresh(),
]);
```

---

# 5. Frontend — TypeScript API & Interfaces

## 5.1 Update `TravelSegment` in:
```
frontend/src/services/travels.ts
```

Add:

```ts
start_at?: string | null;
end_at?: string | null;
duration_minutes?: number | null;
travel_date?: string;
```

Ensure all fields remain compatible with `Partial<TravelSegment>`.

---

# 6. Frontend — TravelForm Component

Modify:
```
frontend/src/components/Travels/TravelForm.tsx
```

## 6.1 Add new fields:
- `start_at` (datetime)
- `end_at` (datetime)

Use project-wide datetime components.

## 6.2 Add duration preview (client-side only):

```ts
const durationLabel = useMemo(() => {
  if (!formData.start_at || !formData.end_at) return null;

  const start = new Date(formData.start_at);
  const end = new Date(formData.end_at);
  if (end < start) return null;

  const diff = end.getTime() - start.getTime();
  const minutes = Math.floor(diff / 60000);
  const hours = Math.floor(minutes / 60);

  return `${hours}h ${String(minutes % 60).padStart(2, '0')}m`;
}, [formData.start_at, formData.end_at]);
```

Display in a styled Typography or equivalent.

---

# 7. Frontend — Form Submission

Prepare payload exactly as follows:

```ts
const payload: Partial<TravelSegment> = {
  technician_id: formData.technician_id!,
  project_id: formData.project_id!,
  origin_country: formData.origin_country!,
  destination_country: formData.destination_country!,
  origin_city: formData.origin_city || null,
  destination_city: formData.destination_city || null,
  status: formData.status || 'planned',
  start_at: formData.start_at,
  end_at: formData.end_at,
};
```

Do **not** send duration.  
Do **not** send travel_date.

Backend sets those.

---

# 8. Frontend — Travels List/Grid (Optional Adjustment)

Ensure the Travels list displays:
- start_at
- end_at
- duration_minutes

No breaking UI changes.

---

# 9. Backend Foundations for Timesheet Integration

Ensure the API supports:
- fetch travel segments by date
- fetch by technician
- fetch by project
- fetch date ranges
- return datetime and duration fields

No UI changes yet.

---

# 10. Backend Foundations for Approvals Integration

Ensure endpoints can aggregate:
- travel count per day
- total travel duration per day
- travel vs timesheet alignment

No UI work yet.

---

# 11. Testing Requirements

## 11.1 Backend
- Creating a travel sets travel_date automatically
- Duration is correct
- Changing start/end recalculates properly
- Completed status enforces end_at

## 11.2 Frontend
- Datetime pickers work
- Duration preview updates live
- Payload correct
- Edit mode works correctly

---

# 12. Strict Copilot Rules

Copilot MUST:
- Not refactor unrelated code
- Not delete fields unless instructed
- Not alter routes or APIs beyond this spec
- Not break multi-tenancy
- Maintain project conventions
- Ask confirmation before any structural deviation

---

# END OF FULL TASK LIST  
Follow every step sequentially and completely.


---

# 13. Timesheet UI Integration — Travel Indicators & Preview

The goal of this phase is to visually integrate Travel Segments into the existing Timesheet views (monthly, weekly, daily) so that:
- days with travel are clearly marked,
- the user can quickly preview travel details for a given day/project,
- the behaviour is consistent across views.

Copilot must first analyse the existing Timesheet components and routes:
- React components (e.g. `TimesheetCalendar`, `TimesheetGrid`, `TimesheetPage`, or equivalents under `frontend/src/components/Timesheets/`).
- Backend endpoints serving timesheet data (e.g. `/api/timesheets`, `/api/timesheets/calendar`, `/api/timesheets/overview`).

## 13.1 Backend — Fetch Travels per Day & Technician

- Implement or extend an API endpoint that allows fetching travel segments grouped by:
  - technician_id
  - date (based on `travel_date` or `start_at` date)
  - project_id (optional but recommended)

Suggested response shape for a monthly calendar:

```json
{
  "technician_id": 1,
  "month": "2025-03",
  "travels_by_date": {
    "2025-03-03": [
      { "id": 10, "project_id": 5, "start_at": "2025-03-03T07:30:00Z", "end_at": "2025-03-03T11:10:00Z" },
      { "id": 11, "project_id": 6, "start_at": "2025-03-03T18:00:00Z", "end_at": "2025-03-03T20:15:00Z" }
    ],
    "2025-03-04": [
      { "id": 12, "project_id": 6, "start_at": "2025-03-04T09:00:00Z", "end_at": "2025-03-04T10:30:00Z" }
    ]
  }
}
```

- Endpoint must be multi-tenant aware, using the existing tenancy setup.
- Use existing auth/guard patterns; do not introduce new guards.

## 13.2 Frontend — Monthly Timesheet View

In the monthly Timesheet calendar component:

- For each day cell:
  - If there are one or more travel segments for that date, render a small travel indicator (e.g. a ✈ icon or a badge with the number of segments).
  - This indicator should be visually consistent with the UI style (use existing chip/badge components if the project has them).

- On click of the travel indicator:
  - Open a side panel or modal that lists:
    - all travel segments for that date (and for the selected technician),
    - including origin/destination, project, start/end time, duration.
  - Provide a button such as “Open Travel Details” that navigates to the dedicated Travels screen filtered by:
    - technician_id,
    - date range,
    - and optionally project_id.

## 13.3 Frontend — Weekly / Daily Timesheet View

In weekly or daily views where timesheets are shown as rows:

- Add a “Travel” column or inline indicator that:
  - shows the number of segments for that row (same technician/date/project),
  - or a simple travel icon when at least one segment exists.

- On click of the travel indicator:
  - Open the same side panel/modal pattern as above, but filtered by:
    - technician_id,
    - date,
    - project_id of the row.

- Make sure the component re-uses existing modal/drawer primitives already used elsewhere in the app (e.g. for expenses or timesheet details).

## 13.4 Behaviour Rules

- Days with travel but no timesheet hours should still show the travel indicator.
- Days with timesheet hours but no travel should show no travel indicator.
- The UI must not break if the travel API returns an empty set.
- Handle loading/error states gracefully when fetching travel data for the Timesheet views.

---

# 14. Approvals UI Integration — Timesheets + Expenses + Travels

This phase integrates travel data into the existing approvals screen, alongside timesheets and expenses, so approvers can validate all three dimensions together.

Copilot must first:
- Inspect the existing approvals API endpoint(s) (e.g. `/api/approvals`, `/api/approvals/timesheets`, `/api/approvals/expenses`).
- Inspect the approvals React components (e.g. under `frontend/src/components/Approvals/`).

## 14.1 Backend — Aggregation for Approvals

Extend or create an approvals endpoint that returns, per technician/date/project:

- Timesheet summary:
  - total hours
  - timesheet status
- Expenses summary:
  - total amount
  - currency
  - number of expense items
  - expense approval status
- Travel summary:
  - number of travel segments for that day/project
  - total travel duration in minutes or hours
  - list of segment IDs (for drill-down)

Suggested response structure:

```json
{
  "technician": { "id": 1, "name": "John Doe" },
  "date": "2025-03-03",
  "project": { "id": 5, "name": "UPG Lisbon T1" },
  "timesheet": { "hours": 8, "status": "pending" },
  "expenses": { "total": 120.0, "currency": "EUR", "count": 3, "status": "pending" },
  "travels": { "count": 2, "duration_minutes": 450, "segment_ids": [10, 11] },
  "flags": ["OK"]
}
```

Rules:
- Respect multi-tenancy and existing scoping.
- Re-use existing query patterns and repositories where possible.

## 14.2 Frontend — Approvals Grid

In the Approvals grid:

- Add new columns for travel:
  - “Travels” (count of segments)
  - “Travel time” (formatted duration, e.g. `7h 30m`)

- If there are no travels for that row, show `0` or `—` gracefully.

- Provide a way (e.g. click on the travels cell or an icon) to open a details panel with:
  - list of travel segments for that technician/date/project,
  - their origin/destination, start/end, duration,
  - and links (if helpful) to open the full Travel module.

## 14.3 Flags and Simple Consistency Checks

Without adding complex AI logic at this stage, implement basic consistency flags:

- Flag when:
  - there are travel segments but zero timesheet hours;
  - there are significant expenses but zero timesheet hours;
  - there are timesheet hours and travels, but total travel duration is very high compared to work hours (e.g. more than 2x).

- Represent flags in:
  - the `flags` array in the API response,
  - and visually in the Approvals grid (e.g. an icon, coloured badge, or warning text).

Copilot must:
- Integrate flags into the existing visual language used by the app for warnings.

---

# 15. Cross-cutting Concerns & UX Consistency

- Re-use existing layout components (modals, drawers, cards, tables) instead of introducing new UI primitives.
- Ensure all new API calls:
  - include tenant headers / context (e.g. `X-Tenant`),
  - use the existing `api` service layer (e.g. `frontend/src/services/api.ts`).
- Keep error handling consistent:
  - display toast/snackbar messages using the same mechanism as the rest of the app (e.g. `showError`, `showSuccess` helpers).
- Only add new routes if strictly necessary; prefer integrating into the existing Timesheet and Approvals screens.

---

# 16. Final Verification Checklist

## 16.1 Timesheet Views

- Monthly view shows travel indicators on days with travel.
- Weekly/daily views show per-row travel markers.
- Clicking an indicator opens a details panel with travel segment data.
- No crashes or layout breakage if there are many travels on the same day.

## 16.2 Approvals

- Approvals grid displays travel count and travel time columns.
- Flags are visible where data is inconsistent.
- Details panel shows full travel info for a given row.

## 16.3 API & Multi-tenancy

- All new endpoints honour tenant scoping.
- Existing behaviour for tenants without any travels remains unchanged (no regressions).

---

# 17. Strict Copilot Behaviour (Extended)

In addition to previous rules, Copilot MUST:
- Avoid adding new dependencies (UI or backend) unless absolutely necessary.
- Avoid side refactors in Timesheet or Approvals modules; changes must be focused and minimal.
- Keep all naming consistent with existing patterns (English, snake_case for DB, camelCase for TS/JS).
- Ask for confirmation (via comment or TODO) before introducing any new route or breaking change.

# END OF EXTENDED TASK LIST (TRAVEL + TIMESHEET + APPROVALS)
