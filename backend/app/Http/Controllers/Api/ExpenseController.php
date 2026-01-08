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
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;

class ExpenseController extends Controller
{
    use HandlesConstraintExceptions;
    public function __construct()
    {
        $this->authorizeResource(Expense::class, 'expense');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isOwnerGlobalView = $user->hasRole('Owner');

        // Report-driven list query uses canonical project membership scoping (ACCESS_RULES.md §3.4)
        $isReportQuery = $request->boolean('report');

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

        $subjectManagedProjectIds = $subjectUser ? $subjectUser->getExpenseManagedProjectIds() : [];
        $subjectVisibleProjectIds = $subjectUser
            ? array_values(array_unique(array_merge(
                $subjectUser->projects()->pluck('projects.id')->toArray(),
                $subjectManagedProjectIds
            )))
            : null;

        $query = Expense::with(['technician', 'project']);

        if ($isReportQuery) {
            // Canonical project membership scoping (ACCESS_RULES.md §2, §3)
            // - Owner: tenant-wide
            // - Others: restrict to expenses whose project_id is in user's project memberships
            if (!$user->hasRole('Owner')) {
                $technician = $user->technician
                    ?? Technician::where('user_id', $user->id)->first()
                    ?? Technician::where('email', $user->email)->first();

                if (!$technician) {
                    $query->whereRaw('1 = 0');
                } else {
                    $memberProjectIds = $user->projects()->pluck('projects.id')->toArray();
                    if (empty($memberProjectIds)) {
                        $query->whereRaw('1 = 0');
                    } else {
                        $query->whereIn('project_id', $memberProjectIds);
                    }
                }
            }
        } else {
            // Canonical (non-report) list behavior — unchanged.
            if ($isOwnerGlobalView) {
                if ($subjectVisibleProjectIds !== null) {
                    $query->whereIn('project_id', $subjectVisibleProjectIds);
                }
            } else {
                $technician = $user->technician
                    ?? Technician::where('user_id', $user->id)->first()
                    ?? Technician::where('email', $user->email)->first();

                // ACCESS_RULES.md — Technician requirement (no Technician => empty list)
                if (!$technician) {
                    $query->whereRaw('1 = 0');
                } else {
                    // ACCESS_RULES.md — Canonical project visibility (member OR canonical manager)
                    $memberProjectIds = $user->projects()->pluck('projects.id')->toArray();
                    $managedProjectIds = $user->getExpenseManagedProjectIds();
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
        }

        // Filter by technician (narrowing only; base query remains ACCESS_RULES-scoped)
        if ($request->filled('technician_id')) {
            $query->where('technician_id', $request->technician_id);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('start_date')) {
            $query->where('date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('date', '<=', $request->end_date);
        }

        $expenses = $query->orderBy('date', 'desc')->get();

        return response()->json($expenses);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $this->authorize('create', Expense::class);

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

        $requestedTechnicianId = isset($validated['technician_id']) ? (int) $validated['technician_id'] : 0;
        $isProjectManager = $project->isUserExpenseManager($user);

        if ($requestedTechnicianId === 0) {
            $requestedTechnicianId = $authTechnician->id;
        }

        if ($requestedTechnicianId !== (int) $authTechnician->id) {
            if (!$isProjectManager) {
                return response()->json([
                    'error' => 'Only expense managers can create records for other technicians.'
                ], 403);
            }

            $technician = Technician::find($requestedTechnicianId);
            if (!$technician) {
                return response()->json(['error' => 'Worker not found'], 404);
            }

            if ($technician->user && !$project->memberRecords()->where('user_id', $technician->user->id)->exists()) {
                return response()->json(['error' => 'This worker is not a member of the selected project.'], 422);
            }
        }

        $validated['technician_id'] = $requestedTechnicianId;

        $validated['status'] = $validated['status'] ?? 'draft';

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('expenses', 'public');
            $validated['attachment_path'] = $path;
        }

        // Auto-calculate mileage amount if expense_type is mileage
        if (isset($validated['expense_type']) && $validated['expense_type'] === 'mileage') {
            if (isset($validated['distance_km']) && isset($validated['rate_per_km'])) {
                $validated['amount'] = round($validated['distance_km'] * $validated['rate_per_km'], 2);
            }
        }

        $expense = Expense::create($validated);
        $expense->load(['technician', 'project']);
        
        return response()->json($expense, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Expense $expense): JsonResponse
    {
        $expense->load(['technician', 'project']);
        return response()->json($expense);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        // Only allow editing of submitted or rejected expenses
        if (!$expense->canBeEdited()) {
            return response()->json(['error' => 'Cannot edit approved expenses'], 403);
        }

        $validated = $request->validated();
        $validated['technician_id'] = $validated['technician_id'] ?? $expense->technician_id;
        $validated['project_id'] = $validated['project_id'] ?? $expense->project_id;

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

        $requestedTechnicianId = isset($validated['technician_id']) ? (int) $validated['technician_id'] : 0;
        $isProjectManager = $project->isUserExpenseManager($user);

        if ($requestedTechnicianId !== (int) $authTechnician->id && !$isProjectManager) {
            return response()->json([
                'error' => 'Only expense managers can create records for other technicians.'
            ], 403);
        }

        if ($requestedTechnicianId !== (int) $authTechnician->id) {
            $targetTechnician = Technician::find($requestedTechnicianId);
            if (!$targetTechnician) {
                return response()->json(['error' => 'Worker not found'], 404);
            }

            if ($targetTechnician->user && !$project->memberRecords()->where('user_id', $targetTechnician->user->id)->exists()) {
                return response()->json(['error' => 'This worker is not a member of the selected project.'], 422);
            }
        }

        $validated['technician_id'] = $requestedTechnicianId;
        
        // Remove _method from validated data if present
        unset($validated['_method']);

        // Handle file upload
        if ($request->hasFile('attachment')) {
            // Delete old attachment if exists
            if ($expense->attachment_path && Storage::exists($expense->attachment_path)) {
                Storage::delete($expense->attachment_path);
            }
            
            $path = $request->file('attachment')->store('expenses', 'public');
            $validated['attachment_path'] = $path;
        }

        // Auto-calculate mileage amount if expense_type is mileage
        if (isset($validated['expense_type']) && $validated['expense_type'] === 'mileage') {
            if (isset($validated['distance_km']) && isset($validated['rate_per_km'])) {
                $validated['amount'] = round($validated['distance_km'] * $validated['rate_per_km'], 2);
            }
        }

        $expense->update($validated);
        $expense->load(['technician', 'project']);
        
        return response()->json($expense);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Expense $expense): JsonResponse
    {
        if (in_array($expense->status, ['submitted', 'approved'])) {
            return response()->json(['error' => 'Cannot delete submitted or approved expenses'], 403);
        }

        // Delete attachment file if exists
        if ($expense->attachment_path && Storage::exists($expense->attachment_path)) {
            Storage::delete($expense->attachment_path);
        }

        try {
            $expense->delete();
            return response()->json(['message' => 'Expense deleted successfully']);
        } catch (QueryException $e) {
            if ($this->isForeignKeyConstraint($e)) {
                return $this->constraintConflictResponse(
                    'This expense cannot be deleted because it is referenced by related records.'
                );
            }

            throw $e;
        }
    }

    /**
     * Download expense attachment
     * Supports both Authorization header and ?token query parameter
     */
    public function downloadAttachment(Request $request, Expense $expense)
    {
        // Manual authentication check (avoid redirect to login route)
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Verify user has permission to view this expense
        $this->authorize('view', $expense);

        if (!$expense->attachment_path || !Storage::disk('public')->exists($expense->attachment_path)) {
            return response()->json(['error' => 'Attachment not found'], 404);
        }

        // Get file contents and metadata from public disk
        $contents = Storage::disk('public')->get($expense->attachment_path);
        $mimeType = Storage::disk('public')->mimeType($expense->attachment_path);
        $filename = basename($expense->attachment_path);

        // Return file response with inline display (not download)
        return response($contents)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Get pending expenses for approval (managers only)
     */
    public function pending(Request $request): JsonResponse
    {
        $query = Expense::with(['technician', 'project'])
            ->whereIn('status', ['submitted', 'finance_review', 'finance_approved', 'paid']);

        // Limit managers to their own projects/records
        if (!$request->user()->hasRole('Admin')) {
            $managedProjectIds = $request->user()->getExpenseManagedProjectIds();

            if (!empty($managedProjectIds)) {
                $query->whereIn('project_id', $managedProjectIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $expenses = $query->orderBy('date', 'desc')->get();
        
        return response()->json($expenses);
    }

    /**
     * Approve an expense (manager only)
     */
    public function approve(Expense $expense): JsonResponse
    {
        $this->authorize('approve', $expense);

        $expense->approve();
        $expense->load(['technician', 'project']);
        
        return response()->json($expense);
    }

    /**
     * Reject an expense (manager only)
     */
    public function reject(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('reject', $expense);

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500'
        ]);

        $expense->reject($validated['rejection_reason']);
        $expense->load(['technician', 'project']);
        
        return response()->json($expense);
    }

    /**
     * Submit an expense for approval.
     */
    public function submit(Expense $expense): JsonResponse
    {
        $this->authorize('submit', $expense);

        if (!$expense->canBeSubmitted()) {
            return response()->json(['error' => 'Only draft or rejected expenses can be submitted.'], 422);
        }

        $expense->submit();
        $expense->load(['technician', 'project']);

        return response()->json($expense);
    }

    /**
     * Approve by Finance (finance team only)
     */
    public function approveByFinance(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('approveByFinance', $expense);

        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
            'payment_reference' => 'nullable|string|max:100'
        ]);

        $expense->approveByFinance(
            $request->user()->id,
            $validated['notes'] ?? null,
            $validated['payment_reference'] ?? null
        );
        $expense->load(['technician', 'project']);
        
        return response()->json($expense);
    }

    /**
     * Mark expense as paid (finance team only)
     */
    public function markPaid(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('markPaid', $expense);

        $validated = $request->validate([
            'payment_reference' => 'required|string|max:100'
        ]);

        $expense->markAsPaid($validated['payment_reference']);
        $expense->load(['technician', 'project']);
        
        return response()->json($expense);
    }

    /**
     * Projects available to the authenticated user.
     */
    public function getUserProjects(Request $request): JsonResponse
    {
        $user = $request->user();
        $isGlobalView = $user->hasRole(['Owner', 'Admin']);

        // Optional subject context for dropdown scoping
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

        $subjectManagedProjectIds = $subjectUser ? $subjectUser->getExpenseManagedProjectIds() : [];
        $subjectVisibleProjectIds = $subjectUser
            ? array_values(array_unique(array_merge(
                $subjectUser->projects()->pluck('projects.id')->toArray(),
                $subjectManagedProjectIds
            )))
            : null;

        $technician = $user->technician
            ?? Technician::where('user_id', $user->id)->first()
            ?? Technician::where('email', $user->email)->first();

        // ACCESS_RULES.md — Technician requirement (no Technician => empty list)
        if (!$technician && !$isGlobalView) {
            return response()->json([]);
        }

        // ACCESS_RULES.md — Canonical project visibility (member OR canonical manager)
        $memberProjectIds = $technician ? $user->projects()->pluck('projects.id')->toArray() : [];
        $managedProjectIds = $technician ? $user->getExpenseManagedProjectIds() : [];
        $visibleProjectIds = array_values(array_unique(array_merge($memberProjectIds, $managedProjectIds)));

        if ($subjectVisibleProjectIds !== null) {
            $visibleProjectIds = $isGlobalView
                ? $subjectVisibleProjectIds
                : array_values(array_intersect($visibleProjectIds, $subjectVisibleProjectIds));
            $managedProjectIds = $isGlobalView
                ? $subjectManagedProjectIds
                : array_values(array_intersect($managedProjectIds, $subjectManagedProjectIds));
        }

        if (empty($visibleProjectIds)) {
            return response()->json([]);
        }

        $roleUser = $subjectUser ?? $user;

        $projects = Project::query()
            ->whereIn('id', $visibleProjectIds)
            ->with([
                'memberRecords' => function ($query) use ($roleUser) {
                    $query->where('user_id', $roleUser->id);
                }
            ])
            ->orderBy('name')
            ->get();

        $projects->each(function ($project) use ($managedProjectIds) {
            $memberRecord = $project->memberRecords->first();
            $project->user_project_role = $memberRecord?->project_role;
            $project->user_expense_role = $memberRecord?->expense_role;

            if (!$memberRecord && in_array($project->id, $managedProjectIds, true)) {
                $project->user_expense_role = 'manager';
                $project->user_project_role = $project->user_project_role ?? 'manager';
            }

            unset($project->memberRecords);
        });

        return response()->json($projects);
    }
}
