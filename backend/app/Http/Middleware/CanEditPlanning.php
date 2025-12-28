<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para verificar permissões de edição do Planning.
 *
 * Segue o mesmo modelo de Timesheets:
 * - edit-own-planning (ex.: Technician)
 * - edit-all-planning (ex.: Manager)
 */
class CanEditPlanning
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Acesso negado. Faça login para continuar.',
            ], 401);
        }

        $user = auth()->user();

        if (!$user->can('edit-own-planning') && !$user->can('edit-all-planning')) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Você não tem permissão para editar Planning.',
                'required_permissions' => ['edit-own-planning', 'edit-all-planning'],
                'user_permissions' => $user->getPermissionsViaRoles()->pluck('name')->toArray(),
            ], 403);
        }

        return $next($request);
    }
}
