# Travel Timesheets Feature

## Overview
This feature extends the timesheet system to support travel entries where technicians move from one location to another as part of project work. **Travel capability is determined by the Task type** - certain tasks (like installation, commissioning, maintenance at client sites) inherently involve travel, while others (like documentation, internal meetings) do not.

## Key Concepts

### Task-Driven Travel Detection
- Tasks have a `requires_travel` boolean flag (new field)
- When a task requires travel:
  - **Origin Location**: Auto-populated from technician's home location (editable)
  - **Destination Location**: Editable dropdown (where the work happens)
- When a task doesn't require travel:
  - **Origin Location**: Visible but disabled/read-only (shows home location for reference)
  - **Destination Location**: Single editable location field (labeled "Work Location")

### Origin Location Intelligence
The system determines the origin location using this priority order:

1. **Explicitly Set Origin** (highest priority)
   - If user manually selects an origin, use that
   - Allows for multi-day trips where origin ≠ home

2. **Technician's Home Location** (default for new entries)
   - From `technicians.home_location_id`
   - Auto-populated when task requires travel
   - Shown as read-only reference when task doesn't require travel

3. **Previous Day's Destination** (future enhancement)
   - For consecutive travel days, origin can auto-populate from yesterday's destination
   - Useful for multi-day on-site assignments

4. **Manual Override by Managers/Admins**
   - Ability to set different origin for special cases
   - Logged in audit trail

### Destination Location Strategy
**What appears in Destination/Work Location dropdown:**

For **Travel Tasks** (requires_travel = true):
- All active locations in the system
- **Exclude** currently selected origin location
- **Highlight** project's default location (if set)
- **Filter** by project's allowed locations (if project has constraints)

For **Non-Travel Tasks** (requires_travel = false):
- All active locations (no filtering)
- **Highlight** technician's home location
- **Highlight** project's default location (if set)

### Task Types That Typically Require Travel
Based on migration default updates:
- ✈️ **Installation**: New equipment at client sites
- ✈️ **Commissioning**: On-site testing and activation
- ✈️ **Maintenance**: Periodic client site visits
- ✈️ **Inspection**: Client site evaluations

### Task Types That Typically Don't Require Travel
- 🏢 **Documentation**: Office-based writing
- 🏢 **Training**: Often conducted at home office
- 🏢 **Testing**: Lab/office-based quality control
- 🏢 **Retrofit**: Can be both (configurable per task instance)

## Database Schema Changes

### Migration 1: Add `requires_travel` to Tasks

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Flag to indicate if task type typically requires travel
            $table->boolean('requires_travel')->default(false)->after('task_type');
            
            // Index for filtering travel-required tasks
            $table->index('requires_travel');
        });
        
        // Update existing task types that typically require travel
        DB::table('tasks')->whereIn('task_type', [
            'installation',
            'commissioning',
            'maintenance',
            'inspection'
        ])->update(['requires_travel' => true]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['requires_travel']);
            $table->dropColumn('requires_travel');
        });
    }
};
```

### Migration 2: Add `home_location_id` to Technicians

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            // Technician's default home/office location
            $table->foreignId('home_location_id')
                  ->nullable()
                  ->after('email')
                  ->constrained('locations')
                  ->onDelete('set null');
            
            $table->index('home_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropForeign(['home_location_id']);
            $table->dropIndex(['home_location_id']);
            $table->dropColumn('home_location_id');
        });
    }
};
```

### Migration 3: Add `origin_location_id` to Timesheets

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // Add origin location for travel timesheets
            $table->foreignId('origin_location_id')
                  ->nullable()
                  ->after('location_id')
                  ->constrained('locations')
                  ->onDelete('set null');
            
            // Index for queries filtering travel vs regular timesheets
            $table->index('origin_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropForeign(['origin_location_id']);
            $table->dropIndex(['origin_location_id']);
            $table->dropColumn('origin_location_id');
        });
    }
};
### Migration 3: Add `origin_location_id` to Timesheets

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // Add origin location for travel timesheets
            $table->foreignId('origin_location_id')
                  ->nullable()
                  ->after('location_id')
                  ->constrained('locations')
                  ->onDelete('set null');
            
            // Index for queries filtering travel vs regular timesheets
            $table->index('origin_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropForeign(['origin_location_id']);
            $table->dropIndex(['origin_location_id']);
            $table->dropColumn('origin_location_id');
        });
    }
};
```

## Model Updates

### Task Model

```php
// backend/app/Models/Task.php

class Task extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'description',
        'task_type',
        'requires_travel', // NEW
        'is_active',
    ];

    protected $casts = [
        'requires_travel' => 'boolean',
        'is_active' => 'boolean',
    ];

    // NEW helper method
    public function requiresTravel(): bool
    {
        return $this->requires_travel === true;
    }
}
```

### Technician Model

```php
// backend/app/Models/Technician.php

class Technician extends Model
{
    protected $fillable = [
        // ... existing fields
        'home_location_id', // NEW
    ];

    // NEW relationship
    public function homeLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'home_location_id');
    }
}
```

### Timesheet Model

```php
// backend/app/Models/Timesheet.php

class Timesheet extends Model
{
    use HasAuditFields;

    protected $fillable = [
        // ... existing fields
        'location_id',
        'origin_location_id', // NEW
        // ... rest of fields
    ];

    // Existing relationship
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    // NEW relationship
    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    // NEW helper method
    public function isTravel(): bool
    {
        return $this->origin_location_id !== null;
    }

    // NEW accessor for display
    public function getLocationDisplayAttribute(): string
    {
        if ($this->isTravel()) {
            return sprintf(
                '%s → %s',
                $this->originLocation?->name ?? 'Unknown',
                $this->location?->name ?? 'Unknown'
            );
        }
        
        return $this->location?->name ?? 'No location';
    }
}
```

## Validation Rules

### StoreTimesheetRequest Updates

```php
// backend/app/Http/Requests/StoreTimesheetRequest.php

public function rules(): array
{
    return [
        // ... existing rules
        'location_id' => ['required', 'exists:locations,id'],
        'origin_location_id' => [
            'nullable',
            'exists:locations,id',
            'different:location_id', // Origin must differ from destination
        ],
        // ... rest of rules
    ];
}

public function messages(): array
{
    return [
        'origin_location_id.different' => 'Origin and destination locations must be different for travel entries.',
        // ... existing messages
    ];
}
```

### UpdateTimesheetRequest Updates
Same validation rules as `StoreTimesheetRequest`.

## API Response Updates

### TimesheetController

```php
// backend/app/Http/Controllers/TimesheetController.php

public function index(): JsonResponse
{
    $timesheets = Timesheet::with([
        'technician.user',
        'project',
        'task',
        'location',
        'originLocation', // NEW - eager load origin
    ])->get();

    return response()->json($timesheets);
}

// Apply same eager loading to show(), store(), update() methods
```

### Response Format

```json
{
  "id": 123,
  "date": "2025-11-08",
  "start_time": "09:00:00",
  "end_time": "17:00:00",
  "location_id": 5,
  "origin_location_id": 3,
  "location": {
    "id": 5,
    "name": "Porto Office"
  },
  "origin_location": {
    "id": 3,
    "name": "Lisboa Office"
  }
}
```

## Frontend Implementation

### Type Definitions

```typescript
// frontend/src/types/index.ts

export interface Location {
  id: number;
  name: string;
  // ... other fields
}

export interface Task {
  id: number;
  project_id: number;
  name: string;
  description?: string;
  task_type: 'retrofit' | 'inspection' | 'commissioning' | 'maintenance' | 'installation' | 'testing' | 'documentation' | 'training';
  requires_travel: boolean; // NEW
  is_active: boolean;
}

export interface Technician {
  id: number;
  user_id: number;
  name: string;
  email: string;
  home_location_id?: number | null; // NEW
  home_location?: Location; // NEW
  // ... other fields
}

export interface Timesheet {
  id?: number;
  date: string;
  start_time: string;
  end_time: string;
  location_id: number;
  origin_location_id?: number | null; // Optional - only for travel tasks
  task_id: number;
  location?: Location;
  origin_location?: Location;
  task?: Task; // Include task to check requires_travel
  technician?: Technician;
  // ... rest of fields
}
```

### UI Flow - TimesheetCalendar Component

#### 1. State Management

```tsx
// Add to existing state
const [isTravel, setIsTravel] = useState<boolean>(false);
const [originLocationId, setOriginLocationId] = useState<number>(0);

// Reset function update
const resetForm = () => {
  // ... existing resets
  setIsTravel(false);
  setOriginLocationId(0);
};
```

#### 2. Dialog Form UI

**Smart Location Fields Based on Task Selection:**

```tsx
// Watch for task selection changes
useEffect(() => {
  if (taskId) {
    const selectedTask = tasks.find(t => t.id === taskId);
    if (selectedTask?.requires_travel) {
      // Auto-populate origin from technician's home location
      const currentTech = availableTechnicians.find(t => t.id === selectedTechnicianId);
      if (currentTech?.home_location_id) {
        setOriginLocationId(currentTech.home_location_id);
      }
    } else {
      // Non-travel task: clear origin
      setOriginLocationId(0);
    }
  }
}, [taskId, selectedTechnicianId, tasks, availableTechnicians]);

// Determine if current task requires travel
const selectedTask = tasks.find(t => t.id === taskId);
const taskRequiresTravel = selectedTask?.requires_travel ?? false;

{/* Origin Location - ALWAYS VISIBLE */}
<FormControl fullWidth margin="normal">
  <InputLabel>Origin Location</InputLabel>
  <Select
    value={originLocationId || ''}
    onChange={(e) => setOriginLocationId(Number(e.target.value))}
    label="Origin Location"
    disabled={!taskRequiresTravel} // Read-only if task doesn't require travel
    sx={{
      bgcolor: taskRequiresTravel ? 'background.paper' : 'action.disabledBackground',
    }}
  >
    <MenuItem value="" disabled>
      {taskRequiresTravel ? 'Select origin location' : 'Not applicable (non-travel task)'}
    </MenuItem>
    {locations.map((loc) => (
      <MenuItem key={loc.id} value={loc.id}>
        {loc.name}
        {/* Show indicator if it's technician's home location */}
        {loc.id === availableTechnicians.find(t => t.id === selectedTechnicianId)?.home_location_id && ' 🏠'}
      </MenuItem>
    ))}
  </Select>
  {!taskRequiresTravel && originLocationId && (
    <FormHelperText>
      Showing your home location for reference. This task does not require travel.
    </FormHelperText>
  )}
</FormControl>

{/* Destination Location */}
<FormControl fullWidth margin="normal" required>
  <InputLabel>
    {taskRequiresTravel ? 'Destination Location' : 'Work Location'}
  </InputLabel>
  <Select
    value={locationId}
    onChange={(e) => setLocationId(Number(e.target.value))}
    label={taskRequiresTravel ? 'Destination Location' : 'Work Location'}
  >
    <MenuItem value={0} disabled>
      Select location
    </MenuItem>
    {locations
      .filter(loc => !taskRequiresTravel || loc.id !== originLocationId) // Exclude origin only for travel tasks
      .map((loc) => (
        <MenuItem key={loc.id} value={loc.id}>
          {loc.name}
        </MenuItem>
      ))}
  </Select>
  {taskRequiresTravel && (
    <FormHelperText>
      Where you will be working (travel destination)
    </FormHelperText>
  )}
</FormControl>
```

**Visual Feedback:**

```tsx
{/* Show travel indicator when task requires travel */}
{taskRequiresTravel && originLocationId && locationId && originLocationId !== locationId && (
  <Alert severity="info" sx={{ mt: 1, mb: 1 }}>
    <AlertTitle>Travel Entry</AlertTitle>
    This timesheet includes travel from <strong>{locations.find(l => l.id === originLocationId)?.name}</strong> to{' '}
    <strong>{locations.find(l => l.id === locationId)?.name}</strong>
  </Alert>
)}
```

#### 3. Form Submission

```tsx
const handleSubmit = async () => {
  try {
    const selectedTask = tasks.find(t => t.id === taskId);
    const taskRequiresTravel = selectedTask?.requires_travel ?? false;
    
    const timesheetData = {
      date: selectedDate?.format('YYYY-MM-DD'),
      start_time: startTimeObj?.format('HH:mm:ss'),
      end_time: endTimeObj?.format('HH:mm:ss'),
      technician_id: selectedTechnicianId || undefined,
      project_id: projectId,
      task_id: taskId || null,
      location_id: locationId,
      // Only send origin if task requires travel AND origin is set
      origin_location_id: taskRequiresTravel && originLocationId ? originLocationId : null,
      description,
      hours_worked: hoursWorked,
    };

    // Validation for travel tasks
    if (taskRequiresTravel) {
      if (!originLocationId) {
        setSnackbar({
          open: true,
          message: 'Origin location is required for travel tasks',
          severity: 'error',
        });
        return;
      }
      
      if (originLocationId === locationId) {
        setSnackbar({
          open: true,
          message: 'Origin and destination must be different for travel tasks',
          severity: 'error',
        });
        return;
      }
    }

    // ... rest of submission logic
  } catch (error) {
    // ... error handling
  }
};
```

#### 4. Edit Functionality

```tsx
const handleEditClick = (timesheet: Timesheet) => {
  // ... existing setters
  setLocationId(timesheet.location_id);
  setTaskId(timesheet.task_id);
  
  // Auto-populate origin based on task and timesheet data
  if (timesheet.origin_location_id) {
    setOriginLocationId(timesheet.origin_location_id);
  } else {
    // If no origin saved but task requires travel, populate from tech's home
    const task = tasks.find(t => t.id === timesheet.task_id);
    if (task?.requires_travel) {
      const tech = availableTechnicians.find(t => t.id === timesheet.technician_id);
      setOriginLocationId(tech?.home_location_id || 0);
    } else {
      setOriginLocationId(0);
    }
  }
  
  setDialogOpen(true);
};
```

### Calendar Display Updates

#### Event Rendering (eventDidMount)

```tsx
// Update location display in all view types

// Week View - update location line
if (location?.name) {
  const locationLine = document.createElement('div');
  locationLine.style.cssText = `
    font-size: 0.6rem;
    color: rgba(0,0,0,0.6);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding-left: 2px;
  `;
  
  // NEW - check for travel
  const { origin_location } = info.event.extendedProps;
  if (origin_location?.name) {
    locationLine.textContent = `🚗 ${origin_location.name} → ${location.name}`;
    locationLine.title = `Travel: ${origin_location.name} to ${location.name}`;
  } else {
    locationLine.textContent = `📍 ${location.name}`;
    locationLine.title = location.name;
  }
  
  fcTitle.appendChild(locationLine);
}

// Apply same logic to Month View and List Views
```

#### Event Data Mapping

```tsx
const calendarEvents = useMemo(() => {
  return visibleTimesheets.map((timesheet) => {
    const eventData = {
      // ... existing fields
      extendedProps: {
        // ... existing props
        location: timesheet.location,
        origin_location: timesheet.origin_location, // NEW
        // ... rest of props
      },
    };
    return eventData;
  });
}, [visibleTimesheets, /* ... */]);
```

## User Experience Flow

### Creating a Travel Timesheet (Task Requires Travel)

1. User clicks on calendar to create new entry
2. Dialog opens with standard fields
3. User selects **Project** (e.g., "Client Site Installation")
4. User selects **Task** (e.g., "Commissioning" - has `requires_travel = true`)
5. **Origin Location** field:
   - Auto-populated with technician's home location (e.g., "Porto Office")
   - Field is **enabled** but shows home location by default
   - User can override if starting from different location (e.g., previous day's destination)
   - Shows 🏠 icon next to home location in dropdown
6. **Destination Location** field:
   - Label changes to "Destination Location"
   - Shows all locations except the selected origin
   - User selects work destination (e.g., "Lisboa Client Site")
7. **Visual Feedback**: Alert box shows "Travel from Porto Office → Lisboa Client Site"
8. User fills remaining fields (times, description)
9. On submit:
   - Validation ensures origin ≠ destination
   - API receives both `origin_location_id` and `location_id`
   - Calendar displays with travel icon: `🚗 Porto → Lisboa`

### Creating a Regular Timesheet (Task Doesn't Require Travel)

1. User clicks on calendar
2. Dialog opens with standard fields
3. User selects **Project** and **Task** (e.g., "Documentation" - `requires_travel = false`)
4. **Origin Location** field:
   - Shows technician's home location (e.g., "Porto Office")
   - Field is **disabled** (grayed out, read-only)
   - Helper text: "Showing your home location for reference. This task does not require travel."
5. **Destination Location** field:
   - Label shows "Work Location" (not "Destination")
   - Shows all locations (no filtering)
   - User selects where work happens (e.g., "Porto Office")
6. No travel alert shown
7. User fills fields normally
8. On submit:
   - `origin_location_id` sent as `null`
   - Calendar displays with location icon: `📍 Porto Office`

### Location Dropdown Content

#### Origin Location Dropdown (when enabled):
```
Select origin location
─────────────────────
Porto Office 🏠          ← Technician's home (highlighted)
Lisboa Office
Client Site A
Client Site B
Remote/Home Office
```

#### Destination Location Dropdown (travel task):
```
Select destination location
─────────────────────
Lisboa Office            ← Filters out currently selected origin
Client Site A
Client Site B
Remote/Home Office
```

#### Work Location Dropdown (non-travel task):
```
Select location
─────────────────────
Porto Office            ← No filtering, shows all
Lisboa Office
Client Site A
Client Site B
Remote/Home Office
```

### Editing Existing Entries

1. Click on event → dialog pre-populates
2. Task determines field behavior:
   - **If task requires travel**:
     - Origin location enabled with saved value (or home location if not saved)
     - Destination location editable
     - Travel alert visible
   - **If task doesn't require travel**:
     - Origin location disabled/read-only (shows home)
     - Work location editable
     - No travel alert
3. User can change task selection → fields update accordingly:
   - Switch from travel to non-travel task → origin becomes read-only
   - Switch from non-travel to travel task → origin becomes editable

## Visual Indicators

### Calendar Views

| View Type | Regular Display | Travel Display |
|-----------|----------------|----------------|
| Week      | 📍 Porto Office | 🚗 Lisboa Office → Porto Office |
| Month     | 📍 Porto | 🚗 Lisboa → Porto |
| Week List | 📍 Porto Office | 🚗 Lisboa Office → Porto Office |
| Month List| 📍 Porto Office | 🚗 Lisboa Office → Porto Office |

### Icons
- **Regular**: 📍 (pin - stationary work)
- **Travel**: 🚗 (car - movement between locations)

## Backward Compatibility

### Existing Timesheets
- All existing entries have `origin_location_id = NULL`
- Automatically treated as regular timesheets
- No data migration required
- Display logic checks for null before showing travel format

### API Contracts
- `origin_location_id` is optional in requests
- Omitting the field = regular timesheet
- Backend handles both scenarios seamlessly

## Reporting Considerations

### Useful Queries

```php
// Get all travel timesheets
Timesheet::whereNotNull('origin_location_id')->get();

// Get regular timesheets only
Timesheet::whereNull('origin_location_id')->get();

// Travel hours per technician
Timesheet::whereNotNull('origin_location_id')
    ->selectRaw('technician_id, SUM(hours_worked) as travel_hours')
    ->groupBy('technician_id')
    ->get();

// Most common travel routes
Timesheet::whereNotNull('origin_location_id')
    ->selectRaw('origin_location_id, location_id, COUNT(*) as trips')
    ->groupBy('origin_location_id', 'location_id')
    ->orderByDesc('trips')
    ->with(['originLocation', 'location'])
    ->get();
```

## Future Enhancements

### Phase 2 (Optional)
- Add `distance_km` field for travel distance
- Add `transport_type` enum (car, train, plane, etc.)
- Auto-calculate travel time based on distance
- Integration with expense tracking for travel costs
- Map visualization of travel routes

### Phase 3 (Optional)
- Multi-stop travels (table: `travel_stops`)
- Recurring travel patterns
- Travel time vs working time breakdown
- Automatic mileage expense creation

## Implementation Checklist

### Backend
- [ ] Create migration for `requires_travel` in tasks table
- [ ] Create migration for `home_location_id` in technicians table
- [ ] Create migration for `origin_location_id` in timesheets table
- [ ] Update `Task` model (fillable, casts, helper methods)
- [ ] Update `Technician` model (fillable, relationships)
- [ ] Update `Timesheet` model (fillable, relationships, helpers)
- [ ] Update `StoreTimesheetRequest` validation
- [ ] Update `UpdateTimesheetRequest` validation
- [ ] Add eager loading for `task`, `originLocation`, `technician.homeLocation` in controllers
- [ ] Update task seeder to set `requires_travel` for existing tasks
- [ ] Add admin UI for managing technician home locations
- [ ] Run migrations in development
- [ ] Test API endpoints (create travel, create regular, update both types)

### Frontend
- [ ] Update `Task` type definition (add `requires_travel`)
- [ ] Update `Technician` type definition (add `home_location_id`, `home_location`)
- [ ] Update `Timesheet` type definition (include `task` object)
- [ ] Add `originLocationId` state to `TimesheetCalendar`
- [ ] Remove `isTravel` toggle switch (task determines travel now)
- [ ] Implement task-driven origin location auto-population
- [ ] Implement conditional origin field rendering (enabled vs disabled)
- [ ] Update destination/work location label based on task type
- [ ] Add travel alert component (origin → destination preview)
- [ ] Update form submission logic (task-based validation)
- [ ] Update edit handler to respect task travel requirements
- [ ] Update `calendarEvents` to include `origin_location` and `task`
- [ ] Update `eventDidMount` for Week view travel display
- [ ] Update `eventDidMount` for Month view travel display
- [ ] Update `eventDidMount` for List views travel display
- [ ] Add admin UI for configuring task `requires_travel` flag
- [ ] Add admin UI for setting technician home locations
- [ ] Test create/edit/delete for both travel and regular entries
- [ ] Test task switching (travel ↔ non-travel)

### Admin Configuration Requirements
- [ ] **Technicians Management Page**: Add "Home Location" field
  - Dropdown to select default office/location
  - Required for technicians who will use travel tasks
  - Visible in technician profile
- [ ] **Tasks Management Page**: Add "Requires Travel" checkbox
  - Allow admins to mark tasks as travel-required
  - Bulk update for task types (e.g., all "Installation" tasks)
  - Show indicator in task list

### Documentation
- [x] Create this feature specification document
- [ ] Update `.github/copilot-instructions.md` with travel patterns
- [ ] Add travel examples to API documentation
- [ ] Update user manual with travel timesheet guide
- [ ] Create admin guide for configuring home locations and travel tasks

## Testing Scenarios

### Manual Testing
1. Create regular timesheet → verify `origin_location_id = null`
2. Create travel timesheet → verify both locations saved
3. Edit regular → convert to travel
4. Edit travel → convert to regular
5. Edit travel → change origin/destination
6. Verify calendar displays correctly in all views
7. Test validation (same origin/destination rejection)
8. Test with projects that require specific locations
9. Verify backward compatibility (old entries still display)

### Unit Tests (Backend)
```php
// tests/Feature/TimesheetTest.php

public function test_can_create_travel_timesheet()
{
    $response = $this->postJson('/api/timesheets', [
        'date' => '2025-11-08',
        'origin_location_id' => 1,
        'location_id' => 2,
        // ... other fields
    ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('timesheets', [
        'origin_location_id' => 1,
        'location_id' => 2,
    ]);
}

public function test_cannot_create_travel_with_same_origin_destination()
{
    $response = $this->postJson('/api/timesheets', [
        'origin_location_id' => 1,
        'location_id' => 1, // Same as origin
        // ... other fields
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['origin_location_id']);
}
```

## Performance Considerations

- **Index on `origin_location_id`**: Enables fast filtering of travel vs regular timesheets
- **Eager Loading**: Always include `originLocation` in API responses to avoid N+1 queries
- **No Additional Queries**: Uses existing `locations` table, no new tables needed
- **Optional Field**: Null values don't increase storage significantly

## Security Considerations

- Validate `origin_location_id` exists in database (foreign key constraint)
- Policy checks apply same as regular timesheets (ownership, manager permissions)
- No additional authorization logic needed
- Same audit trail (`created_by`, `updated_by`) applies

---

**Document Version**: 1.0  
**Last Updated**: November 8, 2025  
**Status**: Proposed - Pending Implementation
