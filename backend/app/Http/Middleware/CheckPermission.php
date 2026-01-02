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

        // Suporta expressões no mesmo formato do Spatie:
        // - 'perm_a|perm_b' => OR
        // - 'perm_a,perm_b' => AND
        // - 'perm_a,perm_b|perm_c' => (perm_a AND perm_b) OR perm_c
        $hasPermission = false;

        foreach (explode('|', $permission) as $orGroup) {
            $orGroup = trim($orGroup);
            if ($orGroup === '') {
                continue;
            }

            $andPermissions = array_values(array_filter(array_map('trim', explode(',', $orGroup))));
            if ($andPermissions === []) {
                continue;
            }

            $groupOk = true;
            foreach ($andPermissions as $singlePermission) {
                if (!$user->can($singlePermission)) {
                    $groupOk = false;
                    break;
                }
            }

            if ($groupOk) {
                $hasPermission = true;
                break;
            }
        }

        // Verificar se o usuário tem a(s) permissão(ões) necessária(s)
        if (!$hasPermission) {
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
