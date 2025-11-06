// Custom hook for AI-powered timesheet suggestions
import { useState, useEffect } from 'react';
import { aiService } from '../services/aiService';
import type { AISuggestion, SuggestionRequest } from '../services/aiService';

export interface UseAISuggestionOptions {
  autoLoad?: boolean;
  minConfidence?: number;
}

export interface UseAISuggestionReturn {
  suggestion: AISuggestion | null;
  isLoading: boolean;
  isAIAvailable: boolean;
  error: string | null;
  loadSuggestion: (request: SuggestionRequest) => Promise<void>;
  applySuggestion: () => void;
  dismissSuggestion: () => void;
  provideFeedback: (accepted: boolean, notes?: string) => Promise<void>;
}

export const useAISuggestion = (
  options: UseAISuggestionOptions = {}
): UseAISuggestionReturn => {
  const { minConfidence = 0.3 } = options;
  
  const [suggestion, setSuggestion] = useState<AISuggestion | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [isAIAvailable, setIsAIAvailable] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [currentRequest, setCurrentRequest] = useState<SuggestionRequest | null>(null);

  // Check AI availability on mount
  useEffect(() => {
    const checkAIStatus = async () => {
      try {
        const status = await aiService.checkStatus();
        setIsAIAvailable(status.available);
        
        if (!status.available) {
          console.log('AI service not available, using fallback mode');
        }
      } catch (error) {
        console.warn('Failed to check AI status:', error);
        setIsAIAvailable(false);
      }
    };

    checkAIStatus();
  }, []);

  const loadSuggestion = async (request: SuggestionRequest) => {
    setIsLoading(true);
    setError(null);
    setCurrentRequest(request);

    try {
      console.log('Loading AI suggestion for:', request);
      
      const aiSuggestion = await aiService.getSmartSuggestion(request);
      
      if (aiSuggestion && aiSuggestion.confidence >= minConfidence) {
        setSuggestion(aiSuggestion);
        console.log('AI suggestion loaded:', aiSuggestion);
      } else if (aiSuggestion) {
        console.log(`AI suggestion confidence (${aiSuggestion.confidence}) below threshold (${minConfidence})`);
        setSuggestion(null);
      } else {
        setSuggestion(null);
      }
    } catch (error) {
      console.error('Error loading AI suggestion:', error);
      setError('Failed to load AI suggestion');
      setSuggestion(null);
    } finally {
      setIsLoading(false);
    }
  };

  const applySuggestion = () => {
    if (suggestion) {
      console.log('Applying AI suggestion:', suggestion);
      // The parent component will handle applying the values
      // This is just for tracking that the suggestion was accepted
    }
  };

  const dismissSuggestion = () => {
    console.log('Dismissing AI suggestion');
    setSuggestion(null);
  };

  const provideFeedback = async (accepted: boolean, notes?: string) => {
    if (suggestion && currentRequest) {
      try {
        const feedback = {
          accepted,
          feedback_notes: notes,
          // Include original suggestion data for learning
          modified_hours: suggestion.suggested_hours,
          modified_description: suggestion.suggested_description
        };

        await aiService.provideFeedback(feedback);
        console.log('AI feedback sent:', feedback);
      } catch (error) {
        console.error('Failed to send AI feedback:', error);
      }
    }
  };

  return {
    suggestion,
    isLoading,
    isAIAvailable,
    error,
    loadSuggestion,
    applySuggestion,
    dismissSuggestion,
    provideFeedback
  };
};

// Hook specifically for timesheet form integration
export const useTimesheetAISuggestion = (projectId: number, targetDate: string) => {
  const aiHook = useAISuggestion({ autoLoad: false, minConfidence: 0.4 });
  
  useEffect(() => {
    if (projectId > 0 && targetDate) {
      const request: SuggestionRequest = {
        project_id: projectId,
        target_date: targetDate,
        context: {
          day_of_week: new Date(targetDate).toLocaleDateString('en-US', { weekday: 'long' }),
          recent_entries: 5
        }
      };
      
      aiHook.loadSuggestion(request);
    }
  }, [projectId, targetDate]);

  return aiHook;
};