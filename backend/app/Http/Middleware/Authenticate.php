<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo($request): ?string
    {
        // Para API nunca redirecionar, devolve só 401 JSON
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }

        // Para web, só redireciona se quiseres
        return '/login'; // ou null se quiseres remover redireções totalmente
    }
}