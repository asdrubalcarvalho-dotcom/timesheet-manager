<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\HasAuditFields;
use Carbon\Carbon;

class Project extends Model
{
    use HasAuditFields;

    protected $fillable = [
        'name',
        'description',
        'start_date',
        'end_date',
        'status',
        'manager_id',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date'
    ];

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Get all project member records (with their roles).
     */
    public function memberRecords(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * Get all users that are members of this project.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
                    ->withPivot('project_role', 'expense_role')
                    ->withTimestamps();
    }

    /**
     * Get users with project manager role (for timesheets).
     */
    public function projectManagers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
                    ->wherePivot('project_role', 'manager')
                    ->withPivot('project_role', 'expense_role')
                    ->withTimestamps();
    }

    /**
     * Get users with expense manager role (for expenses).
     */
    public function expenseManagers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
                    ->wherePivot('expense_role', 'manager')
                    ->withPivot('project_role', 'expense_role')
                    ->withTimestamps();
    }

    /**
     * Get users with project member role (for timesheets).
     */
    public function projectMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
                    ->wherePivot('project_role', 'member')
                    ->withPivot('project_role', 'expense_role')
                    ->withTimestamps();
    }

    /**
     * Get users with expense member role (for expenses).
     */
    public function expenseMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
                    ->wherePivot('expense_role', 'member')
                    ->withPivot('project_role', 'expense_role')
                    ->withTimestamps();
    }

    /**
     * Check if a user is a project manager (can manage timesheets).
     */
    public function isUserProjectManager(User $user): bool
    {
        // Check if user is the direct manager of the project
        if ($this->manager_id === $user->id) {
            return true;
        }

        // Check if user is a manager via project_members table
        return $this->memberRecords()
                    ->where('user_id', $user->id)
                    ->where('project_role', 'manager')
                    ->exists();
    }

    /**
     * Check if a user is an expense manager (can manage expenses).
     */
    public function isUserExpenseManager(User $user): bool
    {
        // Check if user is the direct manager of the project
        if ($this->manager_id === $user->id) {
            return true;
        }

        // Check if user is a manager via project_members table
        return $this->memberRecords()
                    ->where('user_id', $user->id)
                    ->where('expense_role', 'manager')
                    ->exists();
    }

    /**
     * Check if a user is a member (any role) of this project.
     */
    public function isUserMember(User $user): bool
    {
        return $this->memberRecords()
                    ->where('user_id', $user->id)
                    ->exists();
    }

    /**
     * Get user's project role (for timesheets).
     */
    public function getUserProjectRole(User $user): ?string
    {
        $member = $this->memberRecords()
                      ->where('user_id', $user->id)
                      ->first();
        return $member?->project_role;
    }

    /**
     * Get user's expense role (for expenses).
     */
    public function getUserExpenseRole(User $user): ?string
    {
        $member = $this->memberRecords()
                      ->where('user_id', $user->id)
                      ->first();
        return $member?->expense_role;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getTotalHours(): float
    {
        return $this->timesheets()->where('status', 'approved')->sum('hours_worked');
    }

    public function getTotalExpenses(): float
    {
        return $this->expenses()->where('status', 'approved')->sum('amount');
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'project_resource');
    }
}
