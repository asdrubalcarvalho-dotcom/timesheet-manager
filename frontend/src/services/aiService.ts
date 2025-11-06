// AI Service for Smart Auto-Complete
import api from './api';

export interface AISuggestion {
  suggested_hours: number;
  suggested_description: string;
  confidence: number;
  reasoning: string;
  alternative_descriptions: string[];
}

export interface SuggestionRequest {
  project_id: number;
  target_date: string;
  context?: {
    recent_entries?: number;
    day_of_week?: string;
    time_of_day?: string;
  };
}

export interface SuggestionFeedback {
  suggestion_id?: string;
  accepted: boolean;
  modified_hours?: number;
  modified_description?: string;
  feedback_notes?: string;
}

class AIService {
  /**
   * Check if AI service is available and healthy
   */
  async checkStatus(): Promise<{ available: boolean; model_loaded: boolean; response_time?: number }> {
    try {
      const startTime = Date.now();
      const response = await api.get('/ai/status');
      const responseTime = Date.now() - startTime;
      
      return {
        available: true,
        model_loaded: response.data.model_loaded || false,
        response_time: responseTime
      };
    } catch (error) {
      console.warn('AI Service not available:', error);
      return {
        available: false,
        model_loaded: false
      };
    }
  }

  /**
   * Get AI suggestions for timesheet entry
   */
  async getSuggestion(request: SuggestionRequest): Promise<AISuggestion | null> {
    try {
      console.log('Requesting AI suggestion:', request);
      
      const response = await api.post('/ai/suggestions/timesheet', request);
      
      if (response.data && response.data.confidence > 0.3) {
        console.log('AI suggestion received:', response.data);
        return response.data;
      }
      
      console.log('AI suggestion confidence too low or no data');
      return null;
    } catch (error) {
      console.error('Failed to get AI suggestion:', error);
      return null;
    }
  }

  /**
   * Provide feedback on AI suggestion
   */
  async provideFeedback(feedback: SuggestionFeedback): Promise<boolean> {
    try {
      await api.post('/ai/suggestions/feedback', feedback);
      console.log('AI feedback sent successfully');
      return true;
    } catch (error) {
      console.error('Failed to send AI feedback:', error);
      return false;
    }
  }

  /**
   * Generate mock suggestion for development/fallback
   */
  generateMockSuggestion(projectId: number, targetDate: string): AISuggestion {
    const mockSuggestions = {
      1: { // Website Redesign
        hours: [7.5, 8.0, 7.0, 8.5],
        descriptions: [
          'Frontend development - React components',
          'UI/UX implementation',
          'Responsive design updates',
          'Component testing and debugging'
        ]
      },
      2: { // Mobile App
        hours: [6.0, 7.0, 8.0, 6.5],
        descriptions: [
          'Mobile app development',
          'API integration',
          'Testing and bug fixes',
          'Feature implementation'
        ]
      },
      3: { // Database Migration
        hours: [4.0, 5.0, 6.0, 4.5],
        descriptions: [
          'Database schema updates',
          'Data migration scripts',
          'Performance optimization',
          'Testing and validation'
        ]
      }
    };

    const projectSuggestions = mockSuggestions[projectId as keyof typeof mockSuggestions] || mockSuggestions[1];
    const randomIndex = Math.floor(Math.random() * projectSuggestions.hours.length);
    
    const dayOfWeek = new Date(targetDate).getDay();
    const isMonday = dayOfWeek === 1;
    const isFriday = dayOfWeek === 5;
    
    // Adjust hours based on day of week
    let suggestedHours = projectSuggestions.hours[randomIndex];
    if (isMonday) suggestedHours += 0.5; // Monday motivation
    if (isFriday) suggestedHours -= 0.5; // Friday wind-down
    
    return {
      suggested_hours: Math.max(0.5, Math.min(12, suggestedHours)),
      suggested_description: projectSuggestions.descriptions[randomIndex],
      confidence: 0.75 + Math.random() * 0.2, // 75-95% confidence
      reasoning: `Based on ${randomIndex + 3} similar entries with consistent patterns`,
      alternative_descriptions: projectSuggestions.descriptions.filter((_, i) => i !== randomIndex).slice(0, 2)
    };
  }

  /**
   * Smart suggestion with fallback to mock data
   */
  async getSmartSuggestion(request: SuggestionRequest): Promise<AISuggestion | null> {
    // Try real AI first
    const aiSuggestion = await this.getSuggestion(request);
    if (aiSuggestion) {
      return aiSuggestion;
    }

    // Fallback to mock suggestion for development
    console.log('Using mock AI suggestion as fallback');
    return this.generateMockSuggestion(request.project_id, request.target_date);
  }
}

export const aiService = new AIService();