<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMember extends Model
{
    use HasFactory, HasAuditFields;

    protected $fillable = [
        'project_id',
        'user_id', 
        'project_role',
        'expense_role',
        'finance_role',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'project_role' => 'string',
        'expense_role' => 'string',
        'finance_role' => 'string'
    ];

    /**
     * Get the project that this membership belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user that this membership belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the member is a project manager (for timesheets).
     */
    public function isProjectManager(): bool
    {
        return $this->project_role === 'manager';
    }

    /**
     * Check if the member is a regular project member (for timesheets).
     */
    public function isProjectMember(): bool
    {
        return $this->project_role === 'member';
    }

    /**
     * Check if the member is an expense manager (for expenses).
     */
    public function isExpenseManager(): bool
    {
        return $this->expense_role === 'manager';
    }

    /**
     * Check if the member is a regular expense member (for expenses).
     */
    public function isExpenseMember(): bool
    {
        return $this->expense_role === 'member';
    }
}
