<?php

namespace App\Http\Controllers;

use App\Services\TimesheetAIService;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

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
            // Test basic functionality
            $testSuggestion = $this->aiService->generateSuggestion(
                Auth::id(),
                1,  // Assume project 1 exists
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
}