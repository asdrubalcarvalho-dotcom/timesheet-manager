<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Technician profile linked to this user (tenant-scoped).
     */
    public function technician(): HasOne
    {
        return $this->hasOne(Technician::class);
    }

    /**
     * Get all project member records for this user.
     */
    public function memberRecords()
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * Get all projects this user is a member of.
     */
    public function projects()
    {
        return $this->belongsToMany(Project::class, 'project_members')
                    ->withPivot('project_role', 'expense_role')
                    ->withTimestamps();
    }

    /**
     * Get projects where this user is a project manager.
     */
    public function managedProjects()
    {
        return $this->belongsToMany(Project::class, 'project_members')
                    ->wherePivot('project_role', 'manager')
                    ->withPivot('project_role', 'expense_role')
                    ->withTimestamps();
    }

    /**
     * Get projects where this user is an expense manager.
     */
    public function expenseMangedProjects()
    {
        return $this->belongsToMany(Project::class, 'project_members')
                    ->wherePivot('expense_role', 'manager')
                    ->withPivot('project_role', 'expense_role')
                    ->withTimestamps();
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Check if user is project manager for a specific project.
     */
    public function isProjectManagerFor(Project $project): bool
    {
        return $project->isUserProjectManager($this);
    }

    /**
     * Check if user manages any project via project_members pivot.
     * This is the correct way to determine if a user is a "project manager",
     * NOT by checking Spatie role 'Manager'.
     */
    public function isProjectManager(): bool
    {
        return $this->memberRecords()
            ->where('project_role', 'manager')
            ->exists();
    }

    /**
     * Get all project IDs where this user is a manager via project_members.
     */
    public function getManagedProjectIds(): array
    {
        // Get projects via project_members
        $memberManagerProjects = $this->memberRecords()
            ->where('project_role', 'manager')
            ->pluck('project_id')
            ->toArray();

        return array_unique($memberManagerProjects);
    }

    /**
     * Get project ids where the user manages expenses via project_members.
     */
    public function getExpenseManagedProjectIds(): array
    {
        $expenseManagerProjects = $this->memberRecords()
            ->where('expense_role', 'manager')
            ->pluck('project_id')
            ->toArray();

        return array_unique($expenseManagerProjects);
    }

    /**
     * Check if user is expense manager for a specific project.
     */
    public function isExpenseManagerFor(Project $project): bool
    {
        return $project->isUserExpenseManager($this);
    }

    /**
     * Check if user is member of a specific project.
     */
    public function isMemberOf(Project $project): bool
    {
        return $project->isUserMember($this);
    }
}
