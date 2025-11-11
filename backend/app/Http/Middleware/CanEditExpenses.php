<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware profissional para verificar permissões de edição de expenses.
 * 
 * Aceita usuários com QUALQUER UMA das seguintes permissões:
 * - edit-own-expenses (Technicians - podem editar próprias expenses)
 * - edit-all-expenses (Managers - podem editar expenses de membros)
 * 
 * A lógica granular de ownership e project membership é delegada à ExpensePolicy.
 */
class CanEditExpenses
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
        if (!$user->can('edit-own-expenses') && !$user->can('edit-all-expenses')) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Você não tem permissão para editar expenses.',
                'required_permissions' => ['edit-own-expenses', 'edit-all-expenses'],
                'user_permissions' => $user->getPermissionsViaRoles()->pluck('name')->toArray()
            ], 403);
        }

        return $next($request);
    }
}
