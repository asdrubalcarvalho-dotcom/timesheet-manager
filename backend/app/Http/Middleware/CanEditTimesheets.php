<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware profissional para verificar permissões de edição de timesheets.
 * 
 * Aceita usuários com QUALQUER UMA das seguintes permissões:
 * - edit-own-timesheets (Technicians - podem editar próprios timesheets)
 * - edit-all-timesheets (Managers - podem editar timesheets de membros)
 * 
 * A lógica granular de ownership e project membership é delegada à TimesheetPolicy.
 */
class CanEditTimesheets
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Verificar autenticação
        if (!auth()->check()) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Acesso negado. Faça login para continuar.'
            ], 401);
        }

        $user = auth()->user();

        // Verificar se o usuário tem PELO MENOS UMA das permissões de edição
        if (!$user->can('edit-own-timesheets') && !$user->can('edit-all-timesheets')) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Você não tem permissão para editar timesheets.',
                'required_permissions' => ['edit-own-timesheets', 'edit-all-timesheets'],
                'user_permissions' => $user->getPermissionsViaRoles()->pluck('name')->toArray()
            ], 403);
        }

        return $next($request);
    }
}
