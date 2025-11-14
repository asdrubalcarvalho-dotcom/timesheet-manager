<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTravelSegmentRequest;
use App\Http\Requests\UpdateTravelSegmentRequest;
use App\Models\TravelSegment;
use App\Models\Technician;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TravelSegmentController extends Controller
{
    /**
     * Display a listing of travel segments.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TravelSegment::class);

        $query = TravelSegment::with(['technician', 'project', 'originLocation', 'destinationLocation']);

        // Filter by technician
        if ($request->has('technician_id')) {
            $query->where('technician_id', $request->technician_id);
        }

        // Filter by project
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('travel_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('travel_date', '<=', $request->end_date);
        }

        // Apply user-based filtering
        $user = $request->user();
        if (!$user->hasRole(['Admin', 'Owner'])) {
            $technician = $user->technician;
            if ($technician) {
                // Users see their own travels + travels from projects they manage
                $managedProjectIds = $user->managedProjects()->pluck('id')->toArray();
                
                $query->where(function ($q) use ($technician, $managedProjectIds) {
                    $q->where('technician_id', $technician->id)
                      ->orWhereIn('project_id', $managedProjectIds);
                });
            }
        }

        $travelSegments = $query->orderBy('travel_date', 'desc')->get();

        return response()->json(['data' => $travelSegments]);
    }

    /**
     * Store a newly created travel segment.
     */
    public function store(StoreTravelSegmentRequest $request): JsonResponse
    {
        $this->authorize('create', TravelSegment::class);

        $validated = $request->validated();

        // Auto-resolve technician_id if not provided
        if (!isset($validated['technician_id'])) {
            $technician = Technician::where('user_id', auth()->id())->first();
            if (!$technician) {
                return response()->json(['message' => 'No technician profile found for current user.'], 400);
            }
            $validated['technician_id'] = $technician->id;
        }

        // Load technician to get contract country
        $technician = Technician::findOrFail($validated['technician_id']);
        $contractCountry = $technician->worker_contract_country ?? 'PT'; // Default to PT if not set

        // Classify direction
        $classification = TravelSegment::classifyDirection(
            $validated['origin_country'],
            $validated['destination_country'],
            $contractCountry
        );

        $validated['direction'] = $classification['direction'];
        $validated['classification_reason'] = $classification['reason'];

        // Create travel segment (HasAuditFields auto-sets created_by/updated_by)
        $travelSegment = TravelSegment::create($validated);

        return response()->json([
            'data' => $travelSegment->fresh(['technician', 'project', 'originLocation', 'destinationLocation'])
        ], 201);
    }

    /**
     * Display the specified travel segment.
     */
    public function show(TravelSegment $travelSegment): JsonResponse
    {
        $this->authorize('view', $travelSegment);

        return response()->json($travelSegment->load(['technician', 'project', 'originLocation', 'destinationLocation']));
    }

    /**
     * Update the specified travel segment.
     */
    public function update(UpdateTravelSegmentRequest $request, TravelSegment $travelSegment): JsonResponse
    {
        $this->authorize('update', $travelSegment);

        $validated = $request->validated();

        // Re-classify direction if countries changed
        if (isset($validated['origin_country']) || isset($validated['destination_country'])) {
            $originCountry = $validated['origin_country'] ?? $travelSegment->origin_country;
            $destinationCountry = $validated['destination_country'] ?? $travelSegment->destination_country;
            
            $technician = $travelSegment->technician;
            $contractCountry = $technician->worker_contract_country ?? 'PT';

            $classification = TravelSegment::classifyDirection(
                $originCountry,
                $destinationCountry,
                $contractCountry
            );

            $validated['direction'] = $classification['direction'];
            $validated['classification_reason'] = $classification['reason'];
        }

        $travelSegment->update($validated);

        return response()->json([
            'data' => $travelSegment->fresh(['technician', 'project', 'originLocation', 'destinationLocation'])
        ]);
    }

    /**
     * Remove the specified travel segment.
     */
    public function destroy(TravelSegment $travelSegment): JsonResponse
    {
        $this->authorize('delete', $travelSegment);

        $travelSegment->delete();

        return response()->json(['message' => 'Travel segment deleted successfully']);
    }

    /**
     * Get AI suggestions for travel segment.
     */
    public function suggest(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'technician_id' => 'required|integer|exists:technicians,id',
                'project_id' => 'required|integer|exists:projects,id',
            ]);

            $technician = Technician::findOrFail($request->technician_id);
            $contractCountry = $technician->worker_contract_country ?? 'PT';

            // Get most frequent routes for this technician + project
            $recentTravels = TravelSegment::where('technician_id', $request->technician_id)
                ->where('project_id', $request->project_id)
                ->orderBy('travel_date', 'desc')
                ->limit(10)
                ->get();

            $suggestion = [
                'origin_country' => $contractCountry,
                'origin_location_id' => null,
                'destination_country' => null,
                'destination_location_id' => null,
            ];

            // If we have historical data, suggest most common route
            if ($recentTravels->isNotEmpty()) {
                // Get most frequent destination
                $destinationStats = $recentTravels->groupBy('destination_country')
                    ->map(fn($group) => $group->count())
                    ->sortDesc();
                
                if ($destinationStats->isNotEmpty()) {
                    $mostCommonDestCountry = $destinationStats->keys()->first();
                    $mostCommonDest = $recentTravels->firstWhere('destination_country', $mostCommonDestCountry);
                    
                    $suggestion['destination_country'] = $mostCommonDestCountry;
                    $suggestion['destination_location_id'] = $mostCommonDest->destination_location_id ?? null;
                }
            }

            return response()->json($suggestion);
        } catch (\Exception $e) {
            \Log::error('Travel suggestion failed', [
                'error' => $e->getMessage(),
                'technician_id' => $request->technician_id ?? null,
                'project_id' => $request->project_id ?? null,
            ]);

            // Return default suggestion based on contract country
            $technician = Technician::find($request->technician_id);
            $contractCountry = $technician?->worker_contract_country ?? 'PT';

            return response()->json([
                'origin_country' => $contractCountry,
                'origin_location_id' => null,
                'destination_country' => null,
                'destination_location_id' => null,
            ]);
        }
    }

    /**
     * Get travel segments grouped by date for Timesheet integration.
     * 
     * Returns travels organized by date for easy integration with calendar views.
     * Supports filtering by technician, project, and date range.
     */
    public function getTravelsByDate(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TravelSegment::class);

        $validated = $request->validate([
            'technician_id' => 'nullable|integer|exists:technicians,id',
            'month' => 'nullable|date_format:Y-m',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'project_id' => 'nullable|integer|exists:projects,id',
        ]);

        $query = TravelSegment::with(['project:id,name', 'originLocation:id,name,country,city', 'destinationLocation:id,name,country,city']);

        // Filter by technician if provided
        if (!empty($validated['technician_id'])) {
            $query->where('technician_id', $validated['technician_id']);
        }

        // Filter by month or date range
        if (!empty($validated['month'])) {
            $startOfMonth = $validated['month'] . '-01';
            $endOfMonth = date('Y-m-t', strtotime($startOfMonth));
            $query->whereBetween('travel_date', [$startOfMonth, $endOfMonth]);
        } elseif (!empty($validated['start_date']) && !empty($validated['end_date'])) {
            $query->whereBetween('travel_date', [$validated['start_date'], $validated['end_date']]);
        }

        // Optional project filter
        if (!empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        // Apply authorization filter (same as index)
        $user = $request->user();
        if (!$user->hasRole(['Admin', 'Owner'])) {
            $technician = $user->technician;
            if ($technician) {
                $managedProjectIds = $user->getManagedProjectIds();
                
                $query->where(function ($q) use ($technician, $managedProjectIds) {
                    $q->where('technician_id', $technician->id)
                      ->orWhereIn('project_id', $managedProjectIds);
                });
            }
        }

        $travels = $query->orderBy('travel_date')->orderBy('start_at')->get();

        // Group travels by date
        $travelsByDate = [];
        foreach ($travels as $travel) {
            $date = $travel->travel_date instanceof \DateTime 
                ? $travel->travel_date->format('Y-m-d') 
                : $travel->travel_date;
                
            if (!isset($travelsByDate[$date])) {
                $travelsByDate[$date] = [];
            }
            $travelsByDate[$date][] = $travel;
        }

        return response()->json([
            'technician_id' => $validated['technician_id'] ?? null,
            'month' => $validated['month'] ?? null,
            'travels_by_date' => $travelsByDate,
        ]);
    }
}
