

# ✈️ Travel Management Module — Copilot Instructions

## 🎯 Scope & Goal

Implement and evolve a **Travel Management** module to track **travel segments** for technicians, **separate from daily timesheets** but linkable when needed.

This document tells Copilot exactly how to work with this module — **what to generate, what patterns to follow, and what NOT to change.**

Key points:

- Each **travel segment** is a single Origin → Destination movement on a given date.
- Every travel segment is:
  - Multi-tenant
  - Linked to a **technician**
  - **Always linked to a project** (projects also cover internal/department activities)
- The technician’s **contract country** is stored in  
  `technicians.worker_contract_country`.
- Direction (departure/arrival/etc.) is **derived**, not typed manually.

---

## 🧱 1. Data Model (Backend)

### 1.1 Table: `travel_segments`

Create a tenant-level table `travel_segments` with these fields:

- `id` (PK)
- `tenant_id` (multi-tenant, `BelongsToTenant`)
- `technician_id` (FK → `technicians.id`, required)
- `project_id` (FK → `projects.id`, **required** — travel must always belong to a project, including internal/department projects)
- `travel_date` (for now a single date; later we may extend to departure/arrival datetimes)
- `origin_country` (ISO alpha-2 code, e.g. `PT`, `ES`)
- `origin_city` (free text for now)
- `destination_country`
- `destination_city`
- `direction` enum:
  - `departure`
  - `arrival`
  - `project_to_project`
  - `internal`
  - `other`
- `classification_reason` (short text; human-readable explanation)
- `status` enum:
  - `planned`
  - `completed`
  - `cancelled`
- `linked_timesheet_entry_id` (nullable, reserved for future linkage to timesheets)
- `created_by`, `updated_by` (filled via `HasAuditFields`)
- `created_at`, `updated_at` (default timestamps)

**Important rule:** technician contract country lives in  
`technicians.worker_contract_country`.

### 1.2 Direction classification helper

Implement a **static helper** on the model or a dedicated service:

```php
public static function classifyDirection(
    string $originCountry,
    string $destinationCountry,
    string $contractCountry
): string {
    if ($originCountry === $contractCountry && $destinationCountry !== $contractCountry) {
        return 'departure'; // leaving contract country
    }

    if ($destinationCountry === $contractCountry && $originCountry !== $contractCountry) {
        return 'arrival'; // returning to contract country
    }

    if ($originCountry !== $contractCountry && $destinationCountry !== $contractCountry
        && $originCountry !== $destinationCountry) {
        return 'project_to_project'; // between two project countries
    }

    if ($originCountry === $contractCountry && $destinationCountry === $contractCountry) {
        return 'internal'; // internal travel inside contract country
    }

    return 'other';
}
```

When storing or updating a Travel Segment:

1. Load the technician and read `worker_contract_country`.
2. Call `classifyDirection()`.
3. Set `direction` and optionally a short `classification_reason`.

---

## 🧬 2. Migrations

### 2.1 Migration file

Name suggestion:

```bash
php artisan make:migration create_travel_segments_table
```

Example migration:

```php
Schema::create('travel_segments', function (Blueprint $table) {
    $table->id();

    $table->unsignedBigInteger('tenant_id');
    $table->foreignId('technician_id')->constrained()->onDelete('cascade');
    $table->foreignId('project_id')->constrained()->onDelete('cascade');

    $table->date('travel_date');

    $table->string('origin_country', 2);
    $table->string('origin_city')->nullable();
    $table->string('destination_country', 2);
    $table->string('destination_city')->nullable();

    $table->enum('direction', [
        'departure',
        'arrival',
        'project_to_project',
        'internal',
        'other',
    ])->default('other');

    $table->string('classification_reason')->nullable();

    $table->enum('status', ['planned', 'completed', 'cancelled'])
          ->default('planned');

    $table->unsignedBigInteger('linked_timesheet_entry_id')->nullable();

    $table->unsignedBigInteger('created_by')->nullable();
    $table->unsignedBigInteger('updated_by')->nullable();

    $table->timestamps();

    $table->index(['tenant_id', 'technician_id', 'project_id', 'travel_date']);
});
```

Copilot must:
- Apply tenant DB pattern (matching existing tenant migrations).
- Never create this table in the central database.

---

## 🧩 3. Eloquent Model

Create `app/Models/TravelSegment.php`:

```php
class TravelSegment extends Model
{
    use BelongsToTenant;
    use HasAuditFields;

    protected $fillable = [
        'technician_id',
        'project_id',
        'travel_date',
        'origin_country',
        'origin_city',
        'destination_country',
        'destination_city',
        'direction',
        'classification_reason',
        'status',
        'linked_timesheet_entry_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = ['travel_date' => 'date'];

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function linkedTimesheetEntry()
    {
        return $this->belongsTo(Timesheet::class, 'linked_timesheet_entry_id');
    }

    public static function classifyDirection(
        string $originCountry,
        string $destinationCountry,
        string $contractCountry
    ): string {
        // implementation must match the spec
    }
}
```

---

## ✅ 4. Validation Layer (FormRequests)

Create:

- `StoreTravelSegmentRequest`
- `UpdateTravelSegmentRequest`

Rules:

```php
'technician_id' => ['required','integer','exists:technicians,id'],
'project_id' => ['required','integer','exists:projects,id'],
'travel_date' => ['required','date'],
'origin_country' => ['required','string','size:2'],
'destination_country' => ['required','string','size:2'],
'status' => ['nullable','in:planned,completed,cancelled'],
```

---

## 🌐 5. Controllers & Routes

### Controller

`backend/app/Http/Controllers/Api/TravelSegmentController.php`

Actions:
- index
- store
- show
- update
- destroy
- suggest

### Routes

Under tenant routes:

```php
Route::middleware(['auth:sanctum','tenancy'])
    ->prefix('api')
    ->group(function () {
        Route::get('travels', [TravelSegmentController::class, 'index']);
        Route::post('travels', [TravelSegmentController::class, 'store']);
        Route::get('travels/{travel}', [TravelSegmentController::class, 'show']);
        Route::put('travels/{travel}', [TravelSegmentController::class, 'update']);
        Route::delete('travels/{travel}', [TravelSegmentController::class, 'destroy']);

        Route::get('travels/suggestions', [TravelSegmentController::class, 'suggest']);
    });
```

---

## 🔐 6. Authorization & Policies

Policy: `TravelSegmentPolicy`

- Must follow patterns of existing policies.
- Must enforce tenant isolation.
- Must use the existing Spatie roles/permissions system.

---

## 🎨 7. Frontend (React + TS + MUI)

Location:

```
frontend/src/components/Travels/
```

Components to create:
- `TravelsList.tsx`
- `TravelForm.tsx`
- `TravelTable.tsx`
- `api/travels.ts`

### UI Behavior

- Project selection is mandatory.
- Direction is computed, not manually editable.
- Country fields use MUI Autocomplete or Select with ISO codes.
- AI suggestion is optional and can pre-fill Origin/Destination.

---

## 🤖 8. AI Suggestion Endpoint

Service: `TravelSuggestionService`

Heuristic:
- Origin defaults to technician contract country.
- Destination defaults to one of the project’s locations.
- Future improvements allowed but must follow explicit user approval.

Endpoint:

```
GET /api/travels/suggestions?technician_id=&project_id=
```

---

## 🧠 9. Tenancy Rules

- Must always run travel logic in tenant context.
- Must never read/write to central DB for travel.
- Must follow the same rules used for Timesheets/Expenses.

---

## 🚫 10. Copilot MUST NOT:

1. Change tenancy configuration.
2. Add new tenancy modes.
3. Modify authentication guards.
4. Introduce new external packages.
5. Create central DB travel tables.
6. Alter existing modules unless instructed.

---

## 🚀 11. Execution Order

When implementing Travel Tasks, Copilot must:

1. Create migration  
2. Create model  
3. Create FormRequests  
4. Create policy  
5. Create controller  
6. Add tenant routes  
7. Implement suggestion service  
8. Create frontend API client  
9. Build UI pages/forms  
10. Add tests (Feature + Cypress)
