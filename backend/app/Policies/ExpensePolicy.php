<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Usuário precisa da permissão view-expenses
        return $user->hasPermissionTo('view-expenses');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Expense $expense): bool
    {
        // Verificar se tem permissão geral
        if (!$user->hasPermissionTo('view-expenses')) {
            return false;
        }

        // Admins podem ver todas
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Verificar se o user é membro do projeto
        if (!$expense->project->isUserMember($user)) {
            return false;
        }

        // Se é a própria expense, pode ver
        if ($expense->technician && $expense->technician->email === $user->email) {
            return true;
        }

        // Se é Expense Manager no projeto, pode ver expenses de members (não de outros managers)
        if ($expense->project->isUserExpenseManager($user)) {
            if ($expense->technician && $expense->technician->user) {
                $ownerExpenseRole = $expense->project->getUserExpenseRole($expense->technician->user);
                return $ownerExpenseRole === 'member';
            }

            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create-expenses');
    }

    /**
     * Determine whether the user can update the model.
     * 
     * Middleware já verificou permissões genéricas (edit-own-expenses ou edit-all-expenses).
     * Policy verifica APENAS: ownership, status e project membership.
     */
    public function update(User $user, Expense $expense): bool
    {
        // Regra de negócio: Não pode editar despesas aprovadas (exceto Admins)
        if ($expense->status === 'approved' && !$user->hasRole('Admin')) {
            return false;
        }

        // Admins podem editar todas (respeitando regras de status)
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Ownership: Se é a própria expense, pode editar (se não estiver aprovada)
        if ($expense->technician && $expense->technician->user_id === $user->id) {
            return in_array($expense->status, ['draft', 'submitted', 'rejected']);
        }

        // Expense Manager: Pode editar expenses de MEMBERS do projeto (não de outros managers)
        if ($expense->project->isUserExpenseManager($user)) {
            // Verificar se o dono da expense é 'member' (não 'manager')
            if ($expense->technician && $expense->technician->user) {
                $ownerExpenseRole = $expense->project->getUserExpenseRole($expense->technician->user);
                if ($ownerExpenseRole === 'member') {
                    return in_array($expense->status, ['draft', 'submitted', 'rejected']);
                }
            }
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     * 
     * Middleware já verificou permissões genéricas (edit-own-expenses ou edit-all-expenses).
     * Policy verifica APENAS: ownership, status e project membership.
     */
    public function delete(User $user, Expense $expense): bool
    {
        // Regra de negócio: Não pode deletar despesas aprovadas (exceto Admins)
        if ($expense->status === 'approved' && !$user->hasRole('Admin')) {
            return false;
        }

        // Admins podem deletar (respeitando regras)
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Ownership: Se é a própria expense, pode deletar (se não estiver aprovada)
        if ($expense->technician && $expense->technician->user_id === $user->id) {
            return in_array($expense->status, ['draft', 'submitted', 'rejected']);
        }

        // Expense Manager: Pode deletar expenses de MEMBERS do projeto (não de outros managers)
        if ($expense->project->isUserExpenseManager($user)) {
            // Verificar se o dono da expense é 'member' (não 'manager')
            if ($expense->technician && $expense->technician->user) {
                $ownerExpenseRole = $expense->project->getUserExpenseRole($expense->technician->user);
                if ($ownerExpenseRole === 'member') {
                    return in_array($expense->status, ['draft', 'submitted', 'rejected']);
                }
            }
        }

        return false;
    }

    /**
     * Determine whether the user can approve the expense.
     * 
     * REGRA: Expense Managers PODEM aprovar as próprias expenses.
     *        Managers NÃO podem aprovar expenses de OUTROS managers do mesmo projeto.
     */
    public function approve(User $user, Expense $expense): bool
    {
        // Verificar permissão básica
        if (!$user->hasPermissionTo('approve-expenses') || $expense->status !== 'submitted') {
            return false;
        }

        // Admins podem aprovar todas
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Verificar se o user é membro do projeto
        if (!$expense->project->isUserMember($user)) {
            return false;
        }

        // Apenas Expense Managers podem aprovar expenses
        if ($expense->project->isUserExpenseManager($user)) {
            // Se for a própria expense, pode aprovar
            if ($expense->technician && $expense->technician->user_id === $user->id) {
                return true;
            }
            
            // IMPORTANTE: Managers NÃO podem aprovar expenses de OUTROS managers
            // Pode aprovar apenas expenses de members ou próprias
            if ($expense->technician && $expense->technician->user) {
                $ownerExpenseRole = $expense->project->getUserExpenseRole($expense->technician->user);
                return $ownerExpenseRole === 'member';
            }

            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can reject the expense.
     * 
     * REGRA: Expense Managers PODEM rejeitar as próprias expenses.
     *        Managers NÃO podem rejeitar expenses de OUTROS managers do mesmo projeto.
     */
    public function reject(User $user, Expense $expense): bool
    {
        // Verificar permissão básica
        if (!$user->hasPermissionTo('approve-expenses') || !in_array($expense->status, ['submitted', 'approved'])) {
            return false;
        }

        // Admins podem rejeitar todas
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Verificar se o user é membro do projeto
        if (!$expense->project->isUserMember($user)) {
            return false;
        }

        // Apenas Expense Managers podem rejeitar expenses
        if ($expense->project->isUserExpenseManager($user)) {
            // Se for a própria expense, pode rejeitar
            if ($expense->technician && $expense->technician->user_id === $user->id) {
                return true;
            }
            
            // IMPORTANTE: Managers NÃO podem rejeitar expenses de OUTROS managers
            // Pode rejeitar apenas expenses de members ou próprias
            if ($expense->technician && $expense->technician->user) {
                $ownerExpenseRole = $expense->project->getUserExpenseRole($expense->technician->user);
                return $ownerExpenseRole === 'member';
            }

            return true;
        }

        return false;
    }

    public function submit(User $user, Expense $expense): bool
    {
        if (!$expense->canBeSubmitted()) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        if ($expense->technician && $expense->technician->user_id === $user->id) {
            return true;
        }

        return $expense->project->isUserExpenseManager($user);
    }

    /**
     * Determine whether the user can approve by finance (finance team only).
     */
    public function approveByFinance(User $user, Expense $expense): bool
    {
        // Must have finance permission and expense must be in finance_review status
        if (!$user->hasPermissionTo('approve-finance-expenses') || $expense->status !== 'finance_review') {
            return false;
        }

        // Admins can approve all
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Finance role can approve
        if ($user->hasRole('Finance')) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can mark expense as paid (finance team only).
     */
    public function markPaid(User $user, Expense $expense): bool
    {
        // Must have permission and expense must be in finance_approved status
        if (!$user->hasPermissionTo('mark-expenses-paid') || $expense->status !== 'finance_approved') {
            return false;
        }

        // Admins can mark all as paid
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Finance role can mark as paid
        if ($user->hasRole('Finance')) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Expense $expense): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Expense $expense): bool
    {
        return $user->hasRole('Admin');
    }
}
