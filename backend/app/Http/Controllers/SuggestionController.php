<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TimesheetAIService;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SuggestionController extends Controller
{
    private TimesheetAIService $aiService;
    
    public function __construct(TimesheetAIService $aiService)
    {
        $this->aiService = $aiService;
    }
    
    /**
     * Get timesheet suggestions for user
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getTimesheetSuggestions(Request $request): JsonResponse
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'date' => 'required|date',
        ]);
        
        $user = Auth::user();
        $projectId = $request->input('project_id');
        $date = $request->input('date');
        
        // Get project info for context
        $project = Project::findOrFail($projectId);
        
        $context = [
            'project_name' => $project->name,
            'date' => $date,
        ];
        
        $suggestion = $this->aiService->generateSuggestion(
            $user->id,
            $projectId,
            $context
        );
        
        return response()->json([
            'success' => true,
            'data' => $suggestion
        ]);
    }
    
    /**
     * Submit feedback on suggestion accuracy
     */
    public function submitFeedback(Request $request): JsonResponse
    {
        $request->validate([
            'suggestion_id' => 'nullable|string',
            'accepted' => 'required|boolean',
            'actual_hours' => 'required|numeric|min:0|max:24',
            'actual_description' => 'required|string|max:500',
            'feedback_score' => 'nullable|integer|min:1|max:5',
        ]);
        
        // TODO: Store feedback for ML improvement
        // For now, just log for analytics
        \Log::info('AI Suggestion Feedback', [
            'user_id' => Auth::id(),
            'accepted' => $request->input('accepted'),
            'feedback_score' => $request->input('feedback_score'),
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Feedback recorded successfully'
        ]);
    }
    
    /**
     * Get AI service status and health
     */
    public function getStatus(): JsonResponse
    {
        try {
            $projectId = Project::value('id');

            if (!$projectId) {
                return response()->json([
                    'success' => true,
                    'ai_available' => false,
                    'fallback_working' => true,
                    'service_type' => 'statistical'
                ]);
            }

            // Test basic functionality
            $testSuggestion = $this->aiService->generateSuggestion(
                Auth::id(),
                $projectId,
                [
                    'project_name' => 'Test Project',
                    'date' => now()->format('Y-m-d')
                ]
            );
            
            return response()->json([
                'success' => true,
                'ai_available' => $testSuggestion['source'] === 'ai',
                'fallback_working' => $testSuggestion['success'],
                'service_type' => $testSuggestion['source'] ?? 'unknown'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'AI service unavailable',
                'ai_available' => false,
                'fallback_working' => false
            ], 503);
        }
    }

    /**
     * Provide simple AI-style suggestions for access management dashboard.
     */
    public function getAccessSuggestions(): JsonResponse
    {
        $usersWithoutRoles = User::doesntHave('roles')->count();
        $roleCoverage = Role::withCount('permissions')
            ->orderBy('permissions_count', 'asc')
            ->take(3)
            ->get()
            ->map(fn (Role $role) => [
                'role' => $role->name,
                'permissions_count' => $role->permissions_count,
            ]);
        $orphanPermissions = Permission::doesntHave('roles')->pluck('name');

        $suggestion = 'Access matrix está saudável.';
        if ($usersWithoutRoles > 0 || $orphanPermissions->isNotEmpty()) {
            $parts = [];
            if ($usersWithoutRoles > 0) {
                $parts[] = "{$usersWithoutRoles} utilizador(es) sem função atribuída";
            }
            if ($orphanPermissions->isNotEmpty()) {
                $parts[] = 'permissões sem função: ' . $orphanPermissions->take(3)->implode(', ');
            }
            $suggestion = 'Reveja rapidamente: ' . implode(' e ', $parts);
        }

        return response()->json([
            'success' => true,
            'suggestion' => $suggestion,
            'metrics' => [
                'users_without_roles' => $usersWithoutRoles,
                'roles_review' => $roleCoverage,
                'orphan_permissions' => $orphanPermissions,
            ],
        ]);
    }
}
