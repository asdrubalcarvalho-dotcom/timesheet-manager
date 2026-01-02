<?php

namespace App\Policies;

use App\Models\Timesheet;
use App\Models\User;
use App\Exceptions\UnauthorizedException;
use Illuminate\Auth\Access\Response;

class TimesheetPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Usuário precisa da permissão view-timesheets
        return $user->hasPermissionTo('view-timesheets');
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

        // Admins podem ver todos
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Verificar se o user é membro do projeto
        if (!$timesheet->project->isUserMember($user)) {
            return false;
        }

        // Se é o próprio timesheet, pode ver
        if ($timesheet->technician && $timesheet->technician->email === $user->email) {
            return true;
        }

        // Se é Project Manager no projeto, pode ver timesheets de members (não de outros managers)
        if ($timesheet->project->isUserProjectManager($user)) {
            // Verificar se o owner do timesheet é member (não manager) no projeto
            // Verificar se technician tem user associado antes de chamar getUserProjectRole
            if ($timesheet->technician && $timesheet->technician->user) {
                $ownerProjectRole = $timesheet->project->getUserProjectRole($timesheet->technician->user);
                return $ownerProjectRole === 'member';
            }
            // Se technician não tem user, permitir visualização por managers
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create-timesheets');
    }

        /**
     * Determine whether the user can update the model.
     * 
     * Middleware já verificou permissões genéricas (edit-own-timesheets ou edit-all-timesheets).
     * Policy verifica APENAS: ownership, status e project membership.
     */
    public function update(User $user, Timesheet $timesheet): bool
    {
        // Verificar status imutável PRIMEIRO
        if (in_array($timesheet->status, ['approved', 'closed']) && !$user->hasRole('Admin')) {
            throw new UnauthorizedException(
                'Approved or closed timesheets cannot be edited. Only administrators can edit timesheets in this state.'
            );
        }

        // Admins podem editar todos (respeitando regras de status)
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Ownership: Se é o próprio timesheet, pode editar (se não estiver aprovado/fechado)
        if ($timesheet->technician && $timesheet->technician->user_id === $user->id) {
            return in_array($timesheet->status, ['draft', 'submitted', 'rejected']);
        }

        // Project Manager: Pode editar timesheets de MEMBERS do projeto (não de outros managers)
        if ($timesheet->project->isUserProjectManager($user)) {
            // Verificar se o dono do timesheet é 'member' (não 'manager')
            if ($timesheet->technician && $timesheet->technician->user) {
                $ownerProjectRole = $timesheet->project->getUserProjectRole($timesheet->technician->user);
                if ($ownerProjectRole === 'manager') {
                    throw new UnauthorizedException(
                        'Project Managers cannot edit timesheets from other Project Managers.'
                    );
                }
                if ($ownerProjectRole === 'member') {
                    return in_array($timesheet->status, ['draft', 'submitted', 'rejected']);
                }
            }
        }

        throw new UnauthorizedException(
            'You do not have permission to edit this timesheet. Only the owner, Project Managers (for members), or Administrators can edit timesheets.'
        );
    }

        /**
     * Determine whether the user can delete the model.
     * 
     * Middleware já verificou permissões genéricas (edit-own-timesheets ou edit-all-timesheets).
     * Policy verifica APENAS: ownership, status e project membership.
     */
    public function delete(User $user, Timesheet $timesheet): bool
    {
        // Verificar status imutável PRIMEIRO
        if (in_array($timesheet->status, ['approved', 'closed']) && !$user->hasRole('Admin')) {
            throw new UnauthorizedException(
                'Approved or closed timesheets cannot be deleted. Only administrators can delete timesheets in this state.'
            );
        }

        // Admins podem apagar todos (respeitando regras de status)
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Ownership: Se é o próprio timesheet, pode apagar (se não estiver aprovado/fechado)
        if ($timesheet->technician && $timesheet->technician->user_id === $user->id) {
            return in_array($timesheet->status, ['draft', 'submitted', 'rejected']);
        }

        // Project Manager: Pode apagar timesheets de MEMBERS do projeto (não de outros managers)
        if ($timesheet->project->isUserProjectManager($user)) {
            // Verificar se o dono do timesheet é 'member' (não 'manager')
            if ($timesheet->technician && $timesheet->technician->user) {
                $ownerProjectRole = $timesheet->project->getUserProjectRole($timesheet->technician->user);
                if ($ownerProjectRole === 'manager') {
                    throw new UnauthorizedException(
                        'Project Managers cannot delete timesheets from other Project Managers.'
                    );
                }
                if ($ownerProjectRole === 'member') {
                    return in_array($timesheet->status, ['draft', 'submitted', 'rejected']);
                }
            }
        }

        throw new UnauthorizedException(
            'You do not have permission to delete this timesheet. Only the owner, Project Managers (for members), or Administrators can delete timesheets.'
        );
    }

    /**
     * Determine whether the user can approve the timesheet.
     * 
     * REGRA: Managers PODEM aprovar os próprios timesheets.
     *        Managers NÃO podem aprovar timesheets de OUTROS managers do mesmo projeto.
     */
    public function approve(User $user, ?Timesheet $timesheet = null): bool
    {
        // Class-level check (e.g. authorize('approve', Timesheet::class))
        if ($timesheet === null) {
            return $user->hasPermissionTo('approve-timesheets');
        }

        // Verificar permissão básica
        if (!$user->hasPermissionTo('approve-timesheets') || $timesheet->status !== 'submitted') {
            return false;
        }

        // Admins podem aprovar todos
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Verificar se o user é membro do projeto
        if (!$timesheet->project->isUserMember($user)) {
            return false;
        }

        // Apenas Project Managers podem aprovar timesheets
        if ($timesheet->project->isUserProjectManager($user)) {
            // Se for o próprio timesheet, pode aprovar
            if ($timesheet->technician && $timesheet->technician->user_id === $user->id) {
                return true;
            }
            
            // IMPORTANTE: Managers NÃO podem aprovar timesheets de OUTROS managers
            // Pode aprovar apenas timesheets de members ou próprios
            if ($timesheet->technician && $timesheet->technician->user) {
                $ownerProjectRole = $timesheet->project->getUserProjectRole($timesheet->technician->user);
                return $ownerProjectRole === 'member';
            }
            // Se technician não tem user, permitir aprovação por managers
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can reject the timesheet.
     * 
     * REGRA: Managers PODEM rejeitar os próprios timesheets.
     *        Managers NÃO podem rejeitar timesheets de OUTROS managers do mesmo projeto.
     */
    public function reject(User $user, Timesheet $timesheet): bool
    {
        // Verificar permissão básica
        if (!$user->hasPermissionTo('approve-timesheets') || !in_array($timesheet->status, ['submitted', 'approved'])) {
            return false;
        }

        // Admins podem rejeitar todos
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Verificar se o user é membro do projeto
        if (!$timesheet->project->isUserMember($user)) {
            return false;
        }

        // Apenas Project Managers podem rejeitar timesheets
        if ($timesheet->project->isUserProjectManager($user)) {
            // Se for o próprio timesheet, pode rejeitar
            if ($timesheet->technician && $timesheet->technician->user_id === $user->id) {
                return true;
            }
            
            // IMPORTANTE: Managers NÃO podem rejeitar timesheets de OUTROS managers
            // Pode rejeitar apenas timesheets de members ou próprios
            if ($timesheet->technician && $timesheet->technician->user) {
                $ownerProjectRole = $timesheet->project->getUserProjectRole($timesheet->technician->user);
                return $ownerProjectRole === 'member';
            }
            // Se technician não tem user, permitir rejeição por managers
            return true;
        }

        return false;
    }

    public function submit(User $user, Timesheet $timesheet): bool
    {
        if (!$timesheet->canBeSubmitted()) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        if ($timesheet->technician && $timesheet->technician->user_id === $user->id) {
            return true;
        }

        return $timesheet->project->isUserProjectManager($user);
    }

    /**
     * Determine whether the user can close the timesheet (mark as payroll processed).
     * 
     * Status 'closed' indica que o timesheet foi processado pelo RH/Payroll.
     * Apenas Admin ou Project Manager pode fechar manualmente.
     */
    public function close(User $user, Timesheet $timesheet): bool
    {
        if (!$user->hasPermissionTo('approve-timesheets') || !$timesheet->canBeClosed()) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        return $timesheet->project->isUserProjectManager($user);
    }

    /**
     * Reopen an approved timesheet to allow edits (supervisor action).
     */
    public function reopen(User $user, Timesheet $timesheet): bool
    {
        if (!$user->hasPermissionTo('approve-timesheets') || !$timesheet->canBeReopened()) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        return $timesheet->project->isUserProjectManager($user);
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
