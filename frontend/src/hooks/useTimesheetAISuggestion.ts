// Simplified hook for AI suggestions in timesheet calendar
import { useState } from 'react';
import type { AISuggestion } from '../services/aiService';

export interface UseAISuggestionReturn {
  suggestion: AISuggestion | null;
  isLoading: boolean;
  isAIAvailable: boolean;
  error: string | null;
  getSuggestion: (context: any) => Promise<void>;
  applySuggestion: () => void;
  dismissSuggestion: () => void;
  provideFeedback: (accepted: boolean) => void;
}

export const useTimesheetAISuggestion = (): UseAISuggestionReturn => {
  const [suggestion, setSuggestion] = useState<AISuggestion | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [isAIAvailable] = useState(true); // Assume available for now
  const [error, setError] = useState<string | null>(null);

  const getSuggestion = async (context: any) => {
    setIsLoading(true);
    setError(null);
    
    try {
      console.log('Getting AI suggestion with context:', context);
      
      // For now, use a simple fallback suggestion
      const mockSuggestion: AISuggestion = {
        suggested_hours: 8,
        suggested_description: `Working on ${context.task_name} for ${context.project_name}`,
        confidence: 0.85,
        reasoning: 'Based on similar tasks and project patterns',
        alternative_descriptions: [
          `${context.task_name} development work`,
          `Implementation of ${context.task_name} features`,
          `${context.project_name} project tasks`
        ]
      };
      
      setSuggestion(mockSuggestion);
      
    } catch (err) {
      console.error('Error getting AI suggestion:', err);
      setError('Failed to get AI suggestion');
    } finally {
      setIsLoading(false);
    }
  };

  const applySuggestion = () => {
    if (suggestion) {
      console.log('Applied AI suggestion');
    }
  };

  const dismissSuggestion = () => {
    setSuggestion(null);
  };

  const provideFeedback = (accepted: boolean) => {
    console.log('AI feedback provided:', accepted);
  };

  return {
    suggestion,
    isLoading,
    isAIAvailable,
    error,
    getSuggestion,
    applySuggestion,
    dismissSuggestion,
    provideFeedback
  };
};