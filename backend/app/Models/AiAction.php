<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAction extends Model
{
    protected $fillable = [
        'actor_id',
        'tenant_id',
        'client_request_id',
        'action',
        'request_json',
        'response_json',
    ];

    protected $casts = [
        'request_json' => 'array',
        'response_json' => 'array',
    ];
}
