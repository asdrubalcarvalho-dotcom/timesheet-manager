<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
// Resource records live in tenant database; do NOT use BelongsToTenant trait

class Resource extends Model
{
    // removed BelongsToTenant to avoid adding tenant_id where it doesn't exist

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'meta',
        'user_id',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    // Relacionamento com projetos
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_resource');
    }

    // Relacionamento com tarefas
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'resource_task')->withPivot('allocation');
    }

    // Relacionamento com User (worker)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
