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
        return $user->can('view-expenses');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Expense $expense): bool
    {
        // Verificar se tem permissão geral
        if (!$user->can('view-expenses')) {
            return false;
        }

        // Tecnicos só podem ver suas próprias despesas
        if ($user->hasRole('Technician')) {
            return $expense->technician->email === $user->email;
        }

        // Managers e Admins podem ver todas
        return $user->hasAnyRole(['Manager', 'Admin']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create-expenses');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Expense $expense): bool
    {
        // Verificar permissão básica
        if (!$user->can('edit-expenses')) {
            return false;
        }

        // Não pode editar despesas aprovadas (a menos que seja Admin)
        if ($expense->status === 'approved' && !$user->hasRole('Admin')) {
            return false;
        }

        // Tecnicos só podem editar suas próprias despesas
        if ($user->hasRole('Technician')) {
            return $expense->technician->email === $user->email 
                && in_array($expense->status, ['draft', 'submitted']);
        }

        // Managers e Admins podem editar todas (respeitando regras de status)
        return $user->hasAnyRole(['Manager', 'Admin']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Expense $expense): bool
    {
        // Verificar permissão básica
        if (!$user->can('delete-expenses')) {
            return false;
        }

        // Não pode deletar despesas aprovadas (a menos que seja Admin)
        if ($expense->status === 'approved' && !$user->hasRole('Admin')) {
            return false;
        }

        // Tecnicos só podem deletar suas próprias despesas em draft
        if ($user->hasRole('Technician')) {
            return $expense->technician->email === $user->email 
                && $expense->status === 'draft';
        }

        // Managers e Admins podem deletar (respeitando regras)
        return $user->hasAnyRole(['Manager', 'Admin']);
    }

    /**
     * Determine whether the user can approve the expense.
     */
    public function approve(User $user, Expense $expense): bool
    {
        // Só Managers e Admins podem aprovar
        return $user->can('approve-expenses') 
            && $user->hasAnyRole(['Manager', 'Admin'])
            && $expense->status === 'submitted';
    }

    /**
     * Determine whether the user can reject the expense.
     */
    public function reject(User $user, Expense $expense): bool
    {
        // Só Managers e Admins podem rejeitar
        return $user->can('approve-expenses') 
            && $user->hasAnyRole(['Manager', 'Admin'])
            && in_array($expense->status, ['submitted', 'approved']);
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
