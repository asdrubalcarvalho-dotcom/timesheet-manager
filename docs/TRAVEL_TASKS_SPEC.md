# Travel Management Module (Timesheet – Travel Tasks)

## Goal
Implement a separate **Travel Management** module to track travel segments for technicians, independent from daily timesheet entries but linkable when needed.

## Data model
Create a new `travel_segments` table with:

- `id`
- `tenant_id` (multi-tenant, use `BelongsToTenant`)
- `technician_id` (FK to `technicians.id`)
- `project_id` (FK to `projects.id`, **required** — travel must always belong to a project, including internal/department projects)
- `travel_date` (for now a single date; later we may extend to departure/arrival datetimes)
- `origin_country`, `origin_city`
- `destination_country`, `destination_city`
- `direction` (enum: `departure`, `arrival`, `project_to_project`, `internal`, `other`)
- `classification_reason` (short text)
- `status` enum: `planned`, `completed`, `cancelled`
- `linked_timesheet_entry_id` (nullable, reserved for future)
- `created_by`, `updated_by` (must use `HasAuditFields` trait)

**Important rule:** the technician’s contract country is stored in  
`technicians.worker_contract_country`.

Implement a static helper on the model or a dedicated service:

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
