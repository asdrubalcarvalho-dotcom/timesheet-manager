<?php

namespace App\Models\Modules\Billing\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanChangeHistory extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'subscription_plan_history';

    protected $fillable = [
        'tenant_id',
        'previous_plan',
        'new_plan',
        'previous_user_limit',
        'new_user_limit',
        'changed_at',
        'changed_by',
        'notes',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'previous_user_limit' => 'integer',
        'new_user_limit' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
