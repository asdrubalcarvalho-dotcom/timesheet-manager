<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    // List all events
    public function index(): JsonResponse
    {
        $events = Event::with(['project', 'task', 'location', 'technician'])->get();
        return response()->json($events);
    }

    // Create new event
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'project_id' => 'nullable|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
            'location_id' => 'nullable|exists:locations,id',
            'technician_id' => 'nullable|exists:technicians,id',
            'type' => 'required|string',
        ]);
        $event = Event::create($validated);
        return response()->json($event, 201);
    }

    // Get event details
    public function show(Event $event): JsonResponse
    {
        $event->load(['project', 'task', 'location', 'technician']);
        return response()->json($event);
    }

    // Update event
    public function update(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'start' => 'sometimes|date',
            'end' => 'sometimes|date|after_or_equal:start',
            'project_id' => 'nullable|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
            'location_id' => 'nullable|exists:locations,id',
            'technician_id' => 'nullable|exists:technicians,id',
            'type' => 'sometimes|string',
        ]);
        $event->update($validated);
        return response()->json($event);
    }

    // Delete event
    public function destroy(Event $event): JsonResponse
    {
        $event->delete();
        return response()->json(['success' => true]);
    }
}
