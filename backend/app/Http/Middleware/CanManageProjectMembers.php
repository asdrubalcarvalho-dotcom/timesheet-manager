<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allow access to project member management endpoints for users that either
 * have the global manage-projects permission or act as managers of the project.
 */
class CanManageProjectMembers
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Faça login para continuar.'
            ], 401);
        }

        $project = $request->route('project');
        if (!$project instanceof Project) {
            $project = Project::find($project);
            if (!$project) {
                return response()->json([
                    'error' => 'Not Found',
                    'message' => 'Projeto não encontrado.'
                ], 404);
            }

            // Guarantee controller receives a hydrated Project model
            $request->route()->setParameter('project', $project);
        }

        if (
            $user->can('manage-projects') ||
            $user->hasAnyRole(['Admin', 'Manager']) ||
            $project->isUserProjectManager($user)
        ) {
            return $next($request);
        }

        return response()->json([
            'error' => 'Forbidden',
            'message' => 'Você não pode gerenciar os membros deste projeto.'
        ], 403);
    }
}
