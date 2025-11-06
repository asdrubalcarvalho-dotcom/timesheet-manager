<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permission
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        // Verificar se o usuário está autenticado
        if (!auth()->check()) {
            return response()->json([
                'error' => 'Unauthenticated.',
                'message' => 'Acesso negado. Faça login para continuar.'
            ], 401);
        }

        $user = auth()->user();

        // Verificar se o usuário tem a permissão necessária
        if (!$user->can($permission)) {
            return response()->json([
                'error' => 'Forbidden.',
                'message' => 'Você não tem permissão para realizar esta ação.',
                'required_permission' => $permission,
                'user_permissions' => $user->getPermissionsViaRoles()->pluck('name')
            ], 403);
        }

        return $next($request);
    }
}
