<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Technician;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
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

        $query = Expense::with(['technician', 'project']);

        if (!$user->hasRole('Admin')) {
            $query->where(function ($builder) use ($user) {
                $builder->whereHas('technician', function ($technicianQuery) use ($user) {
                    $technicianQuery->where('user_id', $user->id);
                })->orWhere(function ($subQuery) use ($user) {
                    $subQuery->whereHas('project.memberRecords', function ($memberQuery) use ($user) {
                        $memberQuery->where('user_id', $user->id)
                            ->where('expense_role', 'manager');
                    })->whereHas('technician.user.memberRecords', function ($memberQuery) {
                        $memberQuery->where('expense_role', 'member');
                    });
                });
            });
        }

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
        // Policy check
        $this->authorize('create', Expense::class);

        $validated = $request->validated();

        $project = Project::with('memberRecords')->findOrFail($validated['project_id']);
        $user = $request->user();
        $isAdmin = $user->hasRole('Admin');
        $isExpenseManager = $project->isUserExpenseManager($user);

        if (!$isAdmin && !$project->isUserMember($user)) {
            return response()->json(['message' => 'You are not a member of this project.'], 403);
        }

        $technician = null;

        if (($isAdmin || $isExpenseManager) && !empty($validated['technician_id'])) {
            $technician = Technician::find($validated['technician_id']);

            if (!$technician) {
                return response()->json(['error' => 'Worker not found'], 404);
            }

            if ($technician->user && !$project->memberRecords()->where('user_id', $technician->user->id)->exists()) {
                return response()->json(['error' => 'This worker is not a member of the selected project.'], 422);
            }
        }

        if (!$technician) {
            $technician = Technician::where('user_id', $user->id)->first()
                ?? Technician::where('email', $user->email)->first();

            if (!$technician) {
                return response()->json(['error' => 'Technician profile not found'], 404);
            }
        }

        $validated['technician_id'] = $technician->id;
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

        $expense->delete();
        return response()->json(['message' => 'Expense deleted successfully']);
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

        $projects = $user->projects()->with(['memberRecords' => function ($query) use ($user) {
            $query->where('user_id', $user->id);
        }])->orderBy('name')->get();

        $projects->each(function ($project) use ($user) {
            $memberRecord = $project->memberRecords->first();
            $project->user_project_role = $memberRecord?->project_role;
            $project->user_expense_role = $memberRecord?->expense_role;
            unset($project->memberRecords);
        });

        return response()->json($projects);
    }
}
