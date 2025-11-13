<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class SetSanctumTenantConnection
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // If tenant is initialized, make sure PersonalAccessToken uses tenant connection
        if (tenancy()->initialized) {
            // Create a new instance and set its connection to the tenant database
            $model = new PersonalAccessToken();
            $model->setConnection(tenancy()->database()->getName());
            
            // Replace the Sanctum model globally
            config(['sanctum.personal_access_token_model' => get_class($model)]);
        }

        return $next($request);
    }
}
