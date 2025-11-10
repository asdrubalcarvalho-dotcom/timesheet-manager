<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resource extends Model
{
    protected $fillable = [
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
