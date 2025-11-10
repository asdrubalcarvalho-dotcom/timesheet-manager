# Planning Module Implementation (FullCalendar.js + Laravel)

## 1. Backend: REST API Endpoints

### Projects
- `GET /api/projects` — List all projects
- `POST /api/projects` — Create new project
- `GET /api/projects/{id}` — Get project details
- `PUT /api/projects/{id}` — Update project
- `DELETE /api/projects/{id}` — Delete project

### Tasks
- `GET /api/tasks` — List all tasks
- `POST /api/tasks` — Create new task
- `GET /api/tasks/{id}` — Get task details
- `PUT /api/tasks/{id}` — Update task
- `DELETE /api/tasks/{id}` — Delete task

### Locations
- `GET /api/locations` — List all locations
- `POST /api/locations` — Create new location
- `GET /api/locations/{id}` — Get location details
- `PUT /api/locations/{id}` — Update location
- `DELETE /api/locations/{id}` — Delete location

### Technicians (Resources)
- `GET /api/technicians` — List all technicians
- `POST /api/technicians` — Create new technician
- `GET /api/technicians/{id}` — Get technician details
- `PUT /api/technicians/{id}` — Update technician
- `DELETE /api/technicians/{id}` — Delete technician

### Planning Events (Calendar)
- `GET /api/events` — List all planning events (project/task/resource/location)
- `POST /api/events` — Create new event
- `GET /api/events/{id}` — Get event details
- `PUT /api/events/{id}` — Update event
- `DELETE /api/events/{id}` — Delete event

**Event Model Example:**
```json
{
  "id": 1,
  "title": "Frontend Development",
  "start": "2025-11-10T09:00:00",
  "end": "2025-11-10T17:00:00",
  "project_id": 2,
  "task_id": 5,
  "location_id": 3,
  "technician_id": 7,
  "type": "task" // or "project", "location", "resource"
}
```

---

## 2. Frontend: FullCalendar.js Integration

### Main Features
- Month, Week, Day, List views (free)
- Drag & drop events
- Inline CRUD (create, edit, delete)
- Dialogs/forms for editing event details
- Filtering by project, location, resource
- Color coding by type/status

### Example FullCalendar.js Config (React)
```tsx
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';

<FullCalendar
  plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin, listPlugin]}
  initialView="dayGridMonth"
  events={fetchEventsFromApi}
  editable={true}
  selectable={true}
  eventClick={handleEventClick}
  dateClick={handleDateClick}
  eventDrop={handleEventDrop}
  eventResize={handleEventResize}
  eventAdd={handleEventAdd}
  eventChange={handleEventChange}
  eventRemove={handleEventRemove}
  eventContent={customEventRender}
/>
```

### CRUD Flow
- **Create:** User clicks on date → dialog opens → fills details → POST to `/api/events`
- **Edit:** User clicks event → dialog opens → edits → PUT to `/api/events/{id}`
- **Delete:** User clicks delete → DELETE to `/api/events/{id}`
- **Drag & Drop:** Updates start/end → PUT to `/api/events/{id}`

---

## 3. Mapping Events to Master Data
- Each event links to project, task, location, technician (resource)
- Calendar can filter/group by any of these entities
- Color coding by type/status for visual clarity
- CRUD dialogs/forms allow selection/creation of master data inline

---

## 4. Example Event Dialog Fields
- Title
- Project (dropdown)
- Task (dropdown, filtered by project)
- Location (dropdown)
- Technician/Resource (dropdown)
- Start/End date & time
- Type (task/project/location/resource)
- Status (planned, in progress, completed)

---

## 5. Next Steps
1. Implement backend endpoints for events and master data
2. Create React component with FullCalendar.js and CRUD dialogs
3. Test integration and data sync
4. Document usage and customization

---

**This approach is 100% free, modular, and scalable for project/resource/task/location planning.**
