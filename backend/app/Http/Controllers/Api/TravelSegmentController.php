<?php
/*
IMPORTANT — READ FIRST

Before modifying, creating, or refactoring ANY list endpoint
(e.g. index(), search(), by-date, summary, picker, etc.):

1. You MUST read and follow ACCESS_RULES.md.
2. You MUST validate the endpoint against ALL list rules:
   - Technician existence
   - Project membership scoping
   - Canonical project manager detection
   - Manager segregation (managers must not see other managers)
   - List query must be >= Policy::view rules
   - System roles must NOT be used for data scoping

3. If the current behavior violates ANY rule:
   - Explicitly state: “BUG CONFIRMED”
   - Explain which rule is violated and where (file + lines)
   - DO NOT change code unless explicitly asked

4. If behavior is compliant:
   - Explicitly state: “ACCESS RULES COMPLIANT”

5. Never invent alternative access models.
6. When in doubt, return LESS data, not more.

Failure to follow ACCESS_RULES.md is considered a regression.

*/
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\HandlesConstraintExceptions;
use App\Http\Requests\StoreTravelSegmentRequest;
use App\Http\Requests\UpdateTravelSegmentRequest;
use App\Models\TravelSegment;
use App\Models\Project;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

class TravelSegmentController extends Controller
{
    use HandlesConstraintExceptions;
    /**
     * Display a listing of travel segments.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TravelSegment::class);

        $query = TravelSegment::with(['technician', 'project', 'originLocation', 'destinationLocation']);

        $user = $request->user();
        $isOwnerGlobalView = $user->hasRole('Owner');

        // Optional subject context (used to scope projects when viewing another technician)
        $subjectTechnician = $request->filled('technician_id')
            ? Technician::find($request->technician_id)
            : null;

        if (!$subjectTechnician && $request->filled('user_id')) {
            $subjectTechnician = Technician::where('user_id', $request->user_id)->first();
        }

        $subjectUser = $subjectTechnician?->user;
        if (!$subjectUser && $request->filled('user_id')) {
            $subjectUser = User::find($request->user_id);
        }

        $subjectManagedProjectIds = $subjectUser ? $subjectUser->getManagedProjectIds() : [];
        $subjectVisibleProjectIds = $subjectUser
            ? array_values(array_unique(array_merge(
                $subjectUser->projects()->pluck('projects.id')->toArray(),
                $subjectManagedProjectIds
            )))
            : null;

        if ($isOwnerGlobalView) {
            if ($subjectVisibleProjectIds !== null) {
                $query->whereIn('project_id', $subjectVisibleProjectIds);
            }
        } else {
            $technician = $user->technician;

            if (!$technician) {
                $query->whereRaw('1 = 0');
            } else {
                $memberProjectIds = $user->projects()->pluck('projects.id')->toArray();
                $managedProjectIds = $user->getManagedProjectIds();
                $visibleProjectIds = array_values(array_unique(array_merge($memberProjectIds, $managedProjectIds)));

                if ($subjectVisibleProjectIds !== null) {
                    $visibleProjectIds = array_values(array_intersect($visibleProjectIds, $subjectVisibleProjectIds));
                }

                if (empty($visibleProjectIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('project_id', $visibleProjectIds);
                }
            }
        }

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
        $user = $request->user();
        $project = Project::with('memberRecords')->findOrFail($validated['project_id']);

        if (!$project->isUserMember($user)) {
            return response()->json(['error' => 'You are not assigned to this project.'], 403);
        }

        $authTechnician = Technician::where('user_id', $user->id)->first()
            ?? Technician::where('email', $user->email)->first();

        if (!$authTechnician) {
            return response()->json(['error' => 'Technician profile not found'], 404);
        }

        $requestedTechnicianId = (int) ($validated['technician_id'] ?? 0);
        $isProjectManager = $project->isUserProjectManager($user);

        if ($requestedTechnicianId === 0) {
            $validated['technician_id'] = $authTechnician->id;
        } elseif ($requestedTechnicianId !== (int) $authTechnician->id) {
            if (!$isProjectManager) {
                return response()->json([
                    'error' => 'Only project managers can create records for other technicians.'
                ], 403);
            }

            $targetTechnician = Technician::find($requestedTechnicianId);
            if (!$targetTechnician) {
                return response()->json(['error' => 'Worker not found'], 404);
            }

            if ($targetTechnician->user && !$project->memberRecords()->where('user_id', $targetTechnician->user->id)->exists()) {
                return response()->json(['error' => 'This worker is not a member of the selected project.'], 422);
            }

            $validated['technician_id'] = $targetTechnician->id;
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
            'data' => $travelSegment->fresh(['technician', 'project', 'originLocation', 'destinationLocation']),
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

        $user = $request->user();
        $projectId = $validated['project_id'] ?? $travelSegment->project_id;
        $project = Project::with('memberRecords')->findOrFail($projectId);

        if (!$project->isUserMember($user)) {
            return response()->json(['error' => 'You are not assigned to this project.'], 403);
        }

        $authTechnician = Technician::where('user_id', $user->id)->first()
            ?? Technician::where('email', $user->email)->first();

        if (!$authTechnician) {
            return response()->json(['error' => 'Technician profile not found'], 404);
        }

        $requestedTechnicianId = (int) ($validated['technician_id'] ?? $travelSegment->technician_id);
        $isProjectManager = $project->isUserProjectManager($user);

        if ($requestedTechnicianId !== (int) $authTechnician->id && !$isProjectManager) {
            return response()->json([
                'error' => 'Only project managers can create records for other technicians.'
            ], 403);
        }

        if (array_key_exists('technician_id', $validated) && $requestedTechnicianId !== (int) $authTechnician->id) {
            $targetTechnician = Technician::find($requestedTechnicianId);
            if (!$targetTechnician) {
                return response()->json(['error' => 'Worker not found'], 404);
            }

            if ($targetTechnician->user && !$project->memberRecords()->where('user_id', $targetTechnician->user->id)->exists()) {
                return response()->json(['error' => 'This worker is not a member of the selected project.'], 422);
            }
        } else {
            $validated['technician_id'] = $travelSegment->technician_id;
        }

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
            'data' => $travelSegment->fresh(['technician', 'project', 'originLocation', 'destinationLocation']),
        ]);
    }

    /**
     * Remove the specified travel segment.
     */
    public function destroy(TravelSegment $travelSegment): JsonResponse
    {
        $this->authorize('delete', $travelSegment);

        try {
            $travelSegment->delete();

            return response()->json(['message' => 'Travel segment deleted successfully']);
        } catch (QueryException $e) {
            if ($this->isForeignKeyConstraint($e)) {
                return $this->constraintConflictResponse(
                    'This travel segment cannot be deleted because it has related records (timesheets or linked data).'
                );
            }

            throw $e;
        }
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

        $user = $request->user();
        $isGlobalView = $user->hasRole(['Owner', 'Admin']);

        if (!$isGlobalView) {
            $technician = $user->technician;

            if (!$technician) {
                $query->whereRaw('1 = 0');
            } else {
                $memberProjectIds = $user->projects()->pluck('projects.id')->toArray();
                $managedProjectIds = $user->getManagedProjectIds();
                $visibleProjectIds = array_values(array_unique(array_merge($memberProjectIds, $managedProjectIds)));

                if (empty($visibleProjectIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('project_id', $visibleProjectIds);

                    $query->where(function ($q) use ($technician, $managedProjectIds) {
                        $q->where('technician_id', $technician->id);

                        if (!empty($managedProjectIds)) {
                            $q->orWhere(function ($managedQuery) use ($managedProjectIds) {
                                $managedQuery
                                    ->whereIn('project_id', $managedProjectIds)
                                    ->whereHas('technician.user.memberRecords', function ($memberQuery) {
                                        $memberQuery
                                            ->whereColumn('project_members.project_id', 'travel_segments.project_id')
                                            ->where('project_role', 'member');
                                    });
                            });
                        }
                    });
                }
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
