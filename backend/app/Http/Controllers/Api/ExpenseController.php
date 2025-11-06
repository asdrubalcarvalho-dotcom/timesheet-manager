<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Expense::with(['technician', 'project']);

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

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('date', '<=', $request->end_date);
        }

        $expenses = $query->orderBy('date', 'desc')->get();
        return response()->json($expenses);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'project_id' => 'required|exists:projects,id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'required|string|max:255',
            'description' => 'required|string',
            'attachment' => 'nullable|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:5120', // 5MB
            'status' => ['string', Rule::in(['submitted'])]
        ]);

        // Handle file upload
        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('expenses', 'public');
            $validated['attachment_path'] = $path;
        }

        // Set default status if not provided
        if (!isset($validated['status'])) {
            $validated['status'] = 'submitted';
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
    public function update(Request $request, Expense $expense): JsonResponse
    {
        // Only allow editing of submitted or rejected expenses
        if (!$expense->canBeEdited()) {
            return response()->json(['error' => 'Cannot edit approved expenses'], 403);
        }

        $validated = $request->validate([
            'date' => 'date',
            'amount' => 'numeric|min:0.01',
            'category' => 'string|max:255',
            'description' => 'string',
            'attachment' => 'nullable|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:5120', // 5MB
            'status' => [Rule::in(['submitted', 'rejected'])]
        ]);

        // Handle file upload
        if ($request->hasFile('attachment')) {
            // Delete old attachment if exists
            if ($expense->attachment_path && Storage::exists($expense->attachment_path)) {
                Storage::delete($expense->attachment_path);
            }
            
            $path = $request->file('attachment')->store('expenses', 'public');
            $validated['attachment_path'] = $path;
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
        if (!$expense->canBeEdited()) {
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
     * Approve an expense (manager only)
     */
    public function approve(Expense $expense): JsonResponse
    {
        $expense->approve();
        $expense->load(['technician', 'project']);
        
        return response()->json($expense);
    }

    /**
     * Reject an expense (manager only)
     */
    public function reject(Request $request, Expense $expense): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        $expense->reject($validated['reason']);
        $expense->load(['technician', 'project']);
        
        return response()->json($expense);
    }
}
