<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Project;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Filtrar apenas expenses de projetos onde o user é member
        $query = Expense::with(['project', 'technician'])
            ->whereHas('project', function($q) use ($user) {
                $q->whereHas('memberRecords', function($subQ) use ($user) {
                    $subQ->where('user_id', $user->id);
                });
            });

        // Aplicar filtros adicionais baseados na role do user no projeto
        if (!$user->hasRole('Admin')) {
            $query->where(function($q) use ($user) {
                // Sempre pode ver suas próprias expenses
                $q->whereHas('technician', function($subQ) use ($user) {
                    $subQ->where('user_id', $user->id);
                });

                // Se é Expense Manager, pode ver expenses de members
                $q->orWhereHas('project', function($subQ) use ($user) {
                    $subQ->whereHas('memberRecords', function($memberQ) use ($user) {
                        $memberQ->where('user_id', $user->id)
                            ->where('expense_role', 'manager');
                    });
                })->whereHas('technician.user', function($subQ) use ($user) {
                    // Filtrar apenas expenses de members do projeto
                    $subQ->whereHas('memberRecords', function($memberQ) {
                        $memberQ->where('expense_role', 'member');
                    });
                });
            });
        }

        // Filtros opcionais
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date_from') && $request->has('date_to')) {
            $query->whereBetween('date', [$request->date_from, $request->date_to]);
        }

        $expenses = $query->orderBy('date', 'desc')->paginate(15);

        return response()->json($expenses);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $this->authorize('create', Expense::class);

        $user = Auth::user();

        // Verificar se o user é member do projeto
        $project = Project::findOrFail($request->project_id);
        if (!$project->isUserMember($user)) {
            return response()->json(['message' => 'You are not a member of this project.'], 403);
        }

        // Encontrar o technician associado ao user
        $technician = \App\Models\Technician::where('user_id', $user->id)->first();
        if (!$technician) {
            // Fallback para email se user_id não estiver definido
            $technician = \App\Models\Technician::where('email', $user->email)->first();
        }

        if (!$technician) {
            return response()->json(['message' => 'No technician record found for this user.'], 404);
        }

        // Adicionar technician_id aos dados validados
        $data = $request->validated();
        $data['technician_id'] = $technician->id;

        $expense = Expense::create($data);

        $expense->load(['project', 'technician']);

        return response()->json($expense, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        $expense->load(['project', 'technician']);

        return response()->json($expense);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        $expense->update($request->validated());

        $expense->load(['project', 'technician']);

        return response()->json($expense);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        $expense->delete();

        return response()->json(['message' => 'Expense deleted successfully.']);
    }

    /**
     * Approve an expense.
     */
    public function approve(Expense $expense): JsonResponse
    {
        $this->authorize('approve', $expense);

        if ($expense->status !== 'submitted') {
            return response()->json(['message' => 'Only submitted expenses can be approved.'], 422);
        }

        $expense->update(['status' => 'approved']);
        $expense->load(['project', 'technician']);

        return response()->json($expense);
    }

    /**
     * Reject an expense.
     */
    public function reject(Expense $expense): JsonResponse
    {
        $this->authorize('reject', $expense);

        if (!in_array($expense->status, ['submitted', 'approved'])) {
            return response()->json(['message' => 'Only submitted or approved expenses can be rejected.'], 422);
        }

        $expense->update(['status' => 'rejected']);
        $expense->load(['project', 'technician']);

        return response()->json($expense);
    }

    /**
     * Submit an expense for approval.
     */
    public function submit(Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        if ($expense->status !== 'draft') {
            return response()->json(['message' => 'Only draft expenses can be submitted.'], 422);
        }

        $expense->update(['status' => 'submitted']);
        $expense->load(['project', 'technician']);

        return response()->json($expense);
    }

    /**
     * Get available projects for the authenticated user (same as TimesheetController).
     */
    public function getUserProjects(): JsonResponse
    {
        $user = Auth::user();

        // Retornar apenas projetos onde o user é member
        $projects = $user->projects()->with(['memberRecords' => function($q) use ($user) {
            $q->where('user_id', $user->id);
        }])->get();

        // Adicionar informações de role do user no projeto
        $projects->each(function($project) use ($user) {
            $memberRecord = $project->memberRecords->first();
            $project->user_project_role = $memberRecord?->project_role;
            $project->user_expense_role = $memberRecord?->expense_role;
            unset($project->memberRecords); // Limpar dados desnecessários
        });

        return response()->json($projects);
    }
}