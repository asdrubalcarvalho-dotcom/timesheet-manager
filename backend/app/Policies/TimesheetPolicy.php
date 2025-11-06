<?php

namespace App\Policies;

use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TimesheetPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Usuário precisa da permissão view-timesheets
        return $user->can('view-timesheets');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Timesheet $timesheet): bool
    {
        // Verificar se tem permissão geral
        if (!$user->can('view-timesheets')) {
            return false;
        }

        // Tecnicos só podem ver seus próprios timesheets
        if ($user->hasRole('Technician')) {
            return $timesheet->technician->email === $user->email;
        }

        // Managers podem ver timesheets de projetos que gerem + os seus próprios
        if ($user->hasRole('Manager')) {
            // Pode ver se é o manager do projeto
            if ($timesheet->project->manager_id === $user->id) {
                return true;
            }
            
            // Pode ver se é o próprio técnico (manager que também é técnico)
            return $timesheet->technician->email === $user->email;
        }

        // Admins podem ver todos
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create-timesheets');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Timesheet $timesheet): bool
    {
        // Verificar permissão básica
        if (!$user->can('edit-timesheets')) {
            return false;
        }

        // Não pode editar timesheets aprovados (a menos que seja Admin)
        if ($timesheet->status === 'approved' && !$user->hasRole('Admin')) {
            return false;
        }

        // Tecnicos só podem editar seus próprios timesheets
        if ($user->hasRole('Technician')) {
            return $timesheet->technician->email === $user->email 
                && in_array($timesheet->status, ['draft', 'submitted']);
        }

        // Managers podem editar timesheets de projetos que gerem + os seus próprios
        if ($user->hasRole('Manager')) {
            // Pode editar se é o manager do projeto
            if ($timesheet->project->manager_id === $user->id) {
                return true;
            }
            
            // Pode editar se é o próprio timesheet (se o manager também é técnico)
            return $timesheet->technician->email === $user->email
                && in_array($timesheet->status, ['draft', 'submitted']);
        }

        // Admins podem editar todos (respeitando regras de status)
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Timesheet $timesheet): bool
    {
        // Verificar permissão básica
        if (!$user->can('delete-timesheets')) {
            return false;
        }

        // Não pode deletar timesheets aprovados (a menos que seja Admin)
        if ($timesheet->status === 'approved' && !$user->hasRole('Admin')) {
            return false;
        }

        // Tecnicos só podem deletar seus próprios timesheets em draft
        if ($user->hasRole('Technician')) {
            return $timesheet->technician->email === $user->email 
                && $timesheet->status === 'draft';
        }

        // Managers podem deletar timesheets de projetos que gerem + os seus próprios
        if ($user->hasRole('Manager')) {
            // Pode deletar se é o manager do projeto (respeitando status)
            if ($timesheet->project->manager_id === $user->id) {
                return $timesheet->status !== 'approved';
            }
            
            // Pode deletar se é o próprio timesheet (se o manager também é técnico)
            return $timesheet->technician->email === $user->email
                && $timesheet->status === 'draft';
        }

        // Admins podem deletar (respeitando regras)
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can approve the timesheet.
     */
    public function approve(User $user, Timesheet $timesheet): bool
    {
        // Verificar permissão básica
        if (!$user->can('approve-timesheets') || $timesheet->status !== 'submitted') {
            return false;
        }

        // Admins podem aprovar todos
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Managers podem aprovar apenas timesheets dos projetos que gerem
        if ($user->hasRole('Manager')) {
            return $timesheet->project->manager_id === $user->id;
        }

        // Technicians nunca podem aprovar
        return false;
    }

    /**
     * Determine whether the user can reject the timesheet.
     */
    public function reject(User $user, Timesheet $timesheet): bool
    {
        // Verificar permissão básica
        if (!$user->can('approve-timesheets') || !in_array($timesheet->status, ['submitted', 'approved'])) {
            return false;
        }

        // Admins podem rejeitar todos
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Managers podem rejeitar apenas timesheets dos projetos que gerem
        if ($user->hasRole('Manager')) {
            return $timesheet->project->manager_id === $user->id;
        }

        // Technicians nunca podem rejeitar
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Timesheet $timesheet): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Timesheet $timesheet): bool
    {
        return $user->hasRole('Admin');
    }
}
