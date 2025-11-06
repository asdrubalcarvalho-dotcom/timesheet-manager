# Technical Specifications: Smart Auto-Complete
## TimePerk Cortex - AI Implementation Details

### 🔧 **Algorithm Specifications**

#### Pattern Analysis Algorithm
```php
/**
 * Core algorithm for generating timesheet suggestions
 * Uses weighted statistical analysis of historical data
 */
class PatternAnalysisEngine {
    
    // Confidence thresholds
    const MIN_CONFIDENCE = 0.3;    // Below this, don't show suggestions
    const HIGH_CONFIDENCE = 0.8;   // Above this, highlight suggestion
    
    // Weight distribution for suggestion calculation
    const WEIGHTS = [
        'recent_entries' => 0.60,    // Last 5 entries (most relevant)
        'day_of_week' => 0.25,       // Same weekday pattern
        'project_average' => 0.15    // Overall project pattern
    ];
    
    /**
     * Generate suggestions based on user patterns
     */
    public function generateSuggestion($userId, $projectId, $targetDate) {
        $patterns = [
            'recent' => $this->analyzeRecentEntries($userId, $projectId, 5),
            'weekly' => $this->analyzeWeeklyPattern($userId, $projectId, $targetDate),
            'project' => $this->analyzeProjectPattern($userId, $projectId)
        ];
        
        return $this->calculateWeightedSuggestion($patterns);
    }
    
    /**
     * Analyze recent entries (highest weight)
     */
    private function analyzeRecentEntries($userId, $projectId, $limit = 5) {
        $entries = Timesheet::where('technician_id', $userId)
            ->where('project_id', $projectId)
            ->latest('date')
            ->limit($limit)
            ->get();
            
        if ($entries->count() < 2) {
            return ['confidence' => 0, 'hours' => 0, 'descriptions' => []];
        }
        
        // Calculate weighted average (more recent = higher weight)
        $weightedHours = 0;
        $totalWeight = 0;
        
        foreach ($entries as $index => $entry) {
            $weight = ($limit - $index) / $limit; // Decreasing weight
            $weightedHours += $entry->hours_worked * $weight;
            $totalWeight += $weight;
        }
        
        return [
            'confidence' => min(0.9, $entries->count() / $limit),
            'hours' => $totalWeight > 0 ? $weightedHours / $totalWeight : 0,
            'descriptions' => $entries->pluck('description')->unique()->take(3)->toArray()
        ];
    }
    
    /**
     * Analyze same-weekday patterns
     */
    private function analyzeWeeklyPattern($userId, $projectId, $targetDate) {
        $dayOfWeek = Carbon::parse($targetDate)->dayOfWeek;
        
        $entries = Timesheet::where('technician_id', $userId)
            ->where('project_id', $projectId)
            ->whereRaw('DAYOFWEEK(date) = ?', [$dayOfWeek])
            ->where('date', '>=', now()->subMonths(3)) // Last 3 months
            ->get();
            
        if ($entries->count() < 2) {
            return ['confidence' => 0, 'hours' => 0];
        }
        
        $avgHours = $entries->avg('hours_worked');
        $stdDev = $this->calculateStandardDeviation($entries->pluck('hours_worked'));
        
        // Lower confidence if high variance
        $confidence = min(0.8, $entries->count() / 8) * (1 - min(0.5, $stdDev / $avgHours));
        
        return [
            'confidence' => max(0, $confidence),
            'hours' => $avgHours
        ];
    }
    
    /**
     * Calculate final weighted suggestion
     */
    private function calculateWeightedSuggestion($patterns) {
        $totalWeight = 0;
        $weightedHours = 0;
        $confidence = 0;
        $allDescriptions = [];
        
        foreach ($patterns as $type => $data) {
            if ($data['confidence'] > 0) {
                $weight = self::WEIGHTS["{$type}_entries"] ?? self::WEIGHTS[$type] ?? 0;
                $weightedHours += $data['hours'] * $weight * $data['confidence'];
                $totalWeight += $weight * $data['confidence'];
                $confidence += $data['confidence'] * $weight;
                
                if (isset($data['descriptions'])) {
                    $allDescriptions = array_merge($allDescriptions, $data['descriptions']);
                }
            }
        }
        
        return [
            'suggested_hours' => $totalWeight > 0 ? round($weightedHours / $totalWeight, 2) : 0,
            'suggested_descriptions' => array_unique($allDescriptions),
            'confidence_score' => min(1.0, $confidence),
            'pattern_breakdown' => $patterns
        ];
    }
}
```

---

### 🗄️ **Database Schema**

#### New Tables
```sql
-- Suggestion analytics for tracking and improvement
CREATE TABLE suggestion_analytics (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    suggestion_type ENUM('hours', 'description', 'combined') NOT NULL,
    suggested_hours DECIMAL(5,2) NULL,
    actual_hours DECIMAL(5,2) NULL,
    suggested_description TEXT NULL,
    actual_description TEXT NULL,
    confidence_score DECIMAL(3,2) NOT NULL,
    was_accepted BOOLEAN DEFAULT FALSE,
    interaction_time_ms INT UNSIGNED NULL, -- Time to accept/reject
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES technicians(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    
    INDEX idx_user_project (user_id, project_id),
    INDEX idx_created_at (created_at),
    INDEX idx_acceptance (was_accepted, confidence_score)
);

-- User preferences for AI features
CREATE TABLE user_ai_preferences (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    suggestions_enabled BOOLEAN DEFAULT TRUE,
    min_confidence_threshold DECIMAL(3,2) DEFAULT 0.3,
    preferred_suggestion_types JSON DEFAULT '["hours", "description"]',
    auto_apply_high_confidence BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES technicians(id) ON DELETE CASCADE
);

-- Performance metrics cache
CREATE TABLE suggestion_performance_cache (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    metric_key VARCHAR(100) NOT NULL,
    metric_value JSON NOT NULL,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    
    UNIQUE KEY unique_metric (metric_key),
    INDEX idx_expires (expires_at)
);
```

---

### 🌐 **API Endpoints**

#### Suggestion Endpoints
```php
/**
 * GET /api/timesheets/suggestions
 * Get intelligent suggestions for timesheet entry
 */
Route::get('timesheets/suggestions', [TimesheetSuggestionController::class, 'getSuggestions']);

/**
 * POST /api/timesheets/suggestions/feedback
 * Record user feedback on suggestion accuracy
 */
Route::post('timesheets/suggestions/feedback', [TimesheetSuggestionController::class, 'recordFeedback']);

/**
 * GET /api/users/ai-insights
 * Get personalized productivity insights
 */
Route::get('users/ai-insights', [UserInsightsController::class, 'getInsights']);

/**
 * PUT /api/users/ai-preferences
 * Update user AI feature preferences
 */
Route::put('users/ai-preferences', [UserPreferencesController::class, 'updateAIPreferences']);
```

#### Request/Response Specifications
```typescript
// GET /api/timesheets/suggestions
interface SuggestionRequest {
    project_id: number;
    date: string; // YYYY-MM-DD
    task_id?: number;
    location_id?: number;
}

interface SuggestionResponse {
    suggested_hours: number;
    suggested_descriptions: string[];
    confidence_score: number; // 0.0 - 1.0
    pattern_source: 'recent' | 'weekly' | 'project' | 'mixed';
    insights: {
        historical_average: number;
        recent_trend: 'increasing' | 'decreasing' | 'stable';
        day_of_week_typical: boolean;
    };
    metadata: {
        data_points_used: number;
        last_entry_date: string;
        suggestion_id: string; // For feedback tracking
    };
}

// POST /api/timesheets/suggestions/feedback
interface FeedbackRequest {
    suggestion_id: string;
    accepted: boolean;
    actual_hours?: number;
    actual_description?: string;
    interaction_time_ms?: number;
    user_rating?: 1 | 2 | 3 | 4 | 5; // Optional satisfaction rating
}
```

---

### ⚛️ **React Components Architecture**

#### Smart Input Component
```tsx
// SmartTimesheetInput.tsx
interface SmartTimesheetInputProps {
    projectId: number;
    selectedDate: string;
    taskId?: number;
    locationId?: number;
    initialHours?: number;
    initialDescription?: string;
    onHoursChange: (hours: number) => void;
    onDescriptionChange: (description: string) => void;
}

const SmartTimesheetInput: React.FC<SmartTimesheetInputProps> = ({
    projectId,
    selectedDate,
    taskId,
    locationId,
    initialHours = 0,
    initialDescription = '',
    onHoursChange,
    onDescriptionChange
}) => {
    // State management
    const [suggestion, setSuggestion] = useState<SuggestionResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [feedbackSent, setFeedbackSent] = useState(false);
    const [userPreferences] = useUserAIPreferences();
    
    // Debounced suggestion fetching
    const debouncedFetchSuggestions = useCallback(
        debounce(async (params: SuggestionRequest) => {
            if (!userPreferences.suggestions_enabled) return;
            
            setLoading(true);
            try {
                const response = await suggestionService.getSuggestions(params);
                if (response.confidence_score >= userPreferences.min_confidence_threshold) {
                    setSuggestion(response);
                    
                    // Auto-apply if user preference and high confidence
                    if (userPreferences.auto_apply_high_confidence && 
                        response.confidence_score >= 0.8) {
                        handleSuggestionAccept();
                    }
                }
            } catch (error) {
                console.error('Failed to fetch suggestions:', error);
            } finally {
                setLoading(false);
            }
        }, 500),
        [userPreferences]
    );
    
    // Effect to fetch suggestions when parameters change
    useEffect(() => {
        if (projectId && selectedDate) {
            debouncedFetchSuggestions({
                project_id: projectId,
                date: selectedDate,
                task_id: taskId,
                location_id: locationId
            });
        }
    }, [projectId, selectedDate, taskId, locationId, debouncedFetchSuggestions]);
    
    // Handle suggestion acceptance
    const handleSuggestionAccept = useCallback(() => {
        if (!suggestion) return;
        
        const startTime = performance.now();
        onHoursChange(suggestion.suggested_hours);
        
        if (suggestion.suggested_descriptions.length > 0) {
            onDescriptionChange(suggestion.suggested_descriptions[0]);
        }
        
        // Record acceptance feedback
        suggestionService.recordFeedback({
            suggestion_id: suggestion.metadata.suggestion_id,
            accepted: true,
            interaction_time_ms: performance.now() - startTime
        });
        
        setFeedbackSent(true);
    }, [suggestion, onHoursChange, onDescriptionChange]);
    
    // Handle suggestion rejection
    const handleSuggestionReject = useCallback(() => {
        if (!suggestion) return;
        
        suggestionService.recordFeedback({
            suggestion_id: suggestion.metadata.suggestion_id,
            accepted: false
        });
        
        setSuggestion(null);
        setFeedbackSent(true);
    }, [suggestion]);
    
    // Render suggestion UI
    const renderSuggestion = () => {
        if (!suggestion || feedbackSent) return null;
        
        const confidenceColor = suggestion.confidence_score >= 0.8 ? 'success' : 
                               suggestion.confidence_score >= 0.5 ? 'warning' : 'info';
        
        return (
            <Card elevation={0} sx={{ 
                bgcolor: `${confidenceColor}.light`, 
                mb: 2, 
                p: 2,
                border: 1,
                borderColor: `${confidenceColor}.main`
            }}>
                <Box display="flex" alignItems="center" gap={2} flexWrap="wrap">
                    <AutoAwesomeIcon color={confidenceColor} />
                    
                    <Box flex={1}>
                        <Typography variant="body2" fontWeight="medium">
                            Sugestão: {suggestion.suggested_hours}h
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                            {suggestion.insights.recent_trend === 'stable' && 
                             'Consistente com suas últimas entradas'}
                        </Typography>
                    </Box>
                    
                    <Chip 
                        label={`${(suggestion.confidence_score * 100).toFixed(0)}%`}
                        size="small"
                        color={confidenceColor}
                        variant="outlined"
                    />
                    
                    <ButtonGroup size="small">
                        <Button
                            variant="contained"
                            color={confidenceColor}
                            onClick={handleSuggestionAccept}
                            startIcon={<CheckIcon />}
                        >
                            Usar
                        </Button>
                        <Button
                            variant="outlined"
                            onClick={handleSuggestionReject}
                            startIcon={<CloseIcon />}
                        >
                            Rejeitar
                        </Button>
                    </ButtonGroup>
                </Box>
            </Card>
        );
    };
    
    return (
        <Box>
            {loading && (
                <Skeleton variant="rectangular" height={80} sx={{ mb: 2, borderRadius: 1 }} />
            )}
            {renderSuggestion()}
        </Box>
    );
};
```

#### Custom Hooks
```tsx
// useUserAIPreferences.ts
interface UserAIPreferences {
    suggestions_enabled: boolean;
    min_confidence_threshold: number;
    preferred_suggestion_types: string[];
    auto_apply_high_confidence: boolean;
}

export const useUserAIPreferences = () => {
    const [preferences, setPreferences] = useState<UserAIPreferences>({
        suggestions_enabled: true,
        min_confidence_threshold: 0.3,
        preferred_suggestion_types: ['hours', 'description'],
        auto_apply_high_confidence: false
    });
    
    const [loading, setLoading] = useState(true);
    
    useEffect(() => {
        const fetchPreferences = async () => {
            try {
                const response = await fetch('/api/users/ai-preferences');
                if (response.ok) {
                    const data = await response.json();
                    setPreferences(data);
                }
            } catch (error) {
                console.error('Failed to fetch AI preferences:', error);
            } finally {
                setLoading(false);
            }
        };
        
        fetchPreferences();
    }, []);
    
    const updatePreferences = async (newPreferences: Partial<UserAIPreferences>) => {
        try {
            const response = await fetch('/api/users/ai-preferences', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(newPreferences)
            });
            
            if (response.ok) {
                const updated = await response.json();
                setPreferences(updated);
                return updated;
            }
        } catch (error) {
            console.error('Failed to update AI preferences:', error);
            throw error;
        }
    };
    
    return { preferences, loading, updatePreferences };
};

// useSuggestionAnalytics.ts
export const useSuggestionAnalytics = () => {
    const trackSuggestionShown = (suggestionId: string, confidence: number) => {
        analytics.track('suggestion_shown', {
            suggestion_id: suggestionId,
            confidence_score: confidence,
            timestamp: new Date().toISOString()
        });
    };
    
    const trackSuggestionInteraction = (
        suggestionId: string, 
        action: 'accepted' | 'rejected' | 'modified',
        metadata?: Record<string, any>
    ) => {
        analytics.track('suggestion_interaction', {
            suggestion_id: suggestionId,
            action,
            metadata,
            timestamp: new Date().toISOString()
        });
    };
    
    return { trackSuggestionShown, trackSuggestionInteraction };
};
```

---

### 🚀 **Performance Optimizations**

#### Caching Strategy
```php
class SuggestionCacheService {
    const CACHE_TTL = 3600; // 1 hour
    const CACHE_PREFIX = 'suggestions:';
    
    public function getCachedSuggestion($userId, $projectId, $date) {
        $key = $this->buildCacheKey($userId, $projectId, $date);
        return Cache::get($key);
    }
    
    public function cacheSuggestion($userId, $projectId, $date, $suggestion) {
        $key = $this->buildCacheKey($userId, $projectId, $date);
        Cache::put($key, $suggestion, self::CACHE_TTL);
    }
    
    public function invalidateUserCache($userId) {
        $pattern = self::CACHE_PREFIX . $userId . ':*';
        Cache::deleteByPattern($pattern);
    }
    
    private function buildCacheKey($userId, $projectId, $date) {
        return self::CACHE_PREFIX . "{$userId}:{$projectId}:" . Carbon::parse($date)->format('Y-m-d');
    }
}
```

#### Database Query Optimization
```php
// Optimized query with proper indexing
class OptimizedPatternQuery {
    public function getRecentEntries($userId, $projectId, $limit = 5) {
        return DB::table('timesheets')
            ->select(['hours_worked', 'description', 'date'])
            ->where('technician_id', $userId)
            ->where('project_id', $projectId)
            ->where('status', '!=', 'rejected') // Only successful entries
            ->orderByDesc('date')
            ->limit($limit)
            ->get();
    }
    
    // Use raw queries for complex aggregations
    public function getWeeklyPatterns($userId, $projectId) {
        return DB::select("
            SELECT 
                DAYOFWEEK(date) as day_of_week,
                AVG(hours_worked) as avg_hours,
                STDDEV(hours_worked) as std_dev,
                COUNT(*) as entry_count
            FROM timesheets 
            WHERE technician_id = ? 
                AND project_id = ? 
                AND date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                AND status IN ('approved', 'submitted')
            GROUP BY DAYOFWEEK(date)
            HAVING entry_count >= 2
        ", [$userId, $projectId]);
    }
}
```

---

### 🧪 **Testing Strategy**

#### Unit Tests
```php
// tests/Unit/PatternAnalysisEngineTest.php
class PatternAnalysisEngineTest extends TestCase {
    private PatternAnalysisEngine $engine;
    
    protected function setUp(): void {
        parent::setUp();
        $this->engine = new PatternAnalysisEngine();
    }
    
    public function testGeneratesAccurateSuggestionWithSufficientData() {
        // Arrange
        $user = User::factory()->create();
        $project = Project::factory()->create();
        
        // Create consistent pattern: 8 hours every Monday
        Timesheet::factory()->count(4)->create([
            'technician_id' => $user->id,
            'project_id' => $project->id,
            'hours_worked' => 8.0,
            'date' => Carbon::parse('last Monday'),
        ]);
        
        // Act
        $suggestion = $this->engine->generateSuggestion(
            $user->id, 
            $project->id, 
            Carbon::parse('next Monday')->format('Y-m-d')
        );
        
        // Assert
        $this->assertEquals(8.0, $suggestion['suggested_hours']);
        $this->assertGreaterThan(0.7, $suggestion['confidence_score']);
    }
    
    public function testReturnsLowConfidenceWithInsufficientData() {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        
        // Only one entry
        Timesheet::factory()->create([
            'technician_id' => $user->id,
            'project_id' => $project->id,
        ]);
        
        $suggestion = $this->engine->generateSuggestion(
            $user->id, 
            $project->id, 
            now()->format('Y-m-d')
        );
        
        $this->assertLessThan(0.3, $suggestion['confidence_score']);
    }
}
```

#### Integration Tests
```php
// tests/Feature/SuggestionApiTest.php
class SuggestionApiTest extends TestCase {
    use RefreshDatabase;
    
    public function testGetSuggestionsRequiresAuthentication() {
        $response = $this->getJson('/api/timesheets/suggestions');
        $response->assertStatus(401);
    }
    
    public function testGetSuggestionsReturnsValidFormat() {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/timesheets/suggestions?project_id=1&date=2025-11-06');
            
        $response->assertStatus(200)
            ->assertJsonStructure([
                'suggested_hours',
                'suggested_descriptions',
                'confidence_score',
                'pattern_source',
                'insights',
                'metadata'
            ]);
    }
}
```

#### Frontend Tests
```typescript
// SmartTimesheetInput.test.tsx
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { SmartTimesheetInput } from './SmartTimesheetInput';

describe('SmartTimesheetInput', () => {
    const mockProps = {
        projectId: 1,
        selectedDate: '2025-11-06',
        onHoursChange: jest.fn(),
        onDescriptionChange: jest.fn(),
    };
    
    beforeEach(() => {
        jest.clearAllMocks();
    });
    
    it('shows suggestion when confidence is above threshold', async () => {
        // Mock API response
        global.fetch = jest.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({
                suggested_hours: 8,
                confidence_score: 0.85,
                suggested_descriptions: ['Development work'],
                metadata: { suggestion_id: 'test-123' }
            })
        });
        
        render(<SmartTimesheetInput {...mockProps} />);
        
        await waitFor(() => {
            expect(screen.getByText(/Sugestão: 8h/)).toBeInTheDocument();
        });
    });
    
    it('applies suggestion when user clicks accept', async () => {
        // Setup mock and render component
        // ... setup code ...
        
        const acceptButton = screen.getByRole('button', { name: /usar/i });
        fireEvent.click(acceptButton);
        
        expect(mockProps.onHoursChange).toHaveBeenCalledWith(8);
        expect(mockProps.onDescriptionChange).toHaveBeenCalledWith('Development work');
    });
});
```

---

### 📊 **Monitoring & Analytics**

#### Performance Metrics
```php
// app/Services/SuggestionMetricsService.php
class SuggestionMetricsService {
    public function calculateDailyMetrics($date = null) {
        $date = $date ?? now()->format('Y-m-d');
        
        return [
            'suggestions_generated' => $this->getSuggestionsGenerated($date),
            'suggestions_accepted' => $this->getSuggestionsAccepted($date),
            'average_confidence' => $this->getAverageConfidence($date),
            'user_satisfaction' => $this->getUserSatisfaction($date),
            'time_saved_minutes' => $this->calculateTimeSaved($date),
        ];
    }
    
    private function calculateTimeSaved($date) {
        // Calculate based on accepted suggestions vs manual entry time
        $acceptedSuggestions = DB::table('suggestion_analytics')
            ->where(DB::raw('DATE(created_at)'), $date)
            ->where('was_accepted', true)
            ->count();
            
        // Assume 2 minutes saved per accepted suggestion
        return $acceptedSuggestions * 2;
    }
}
```

---

*This technical specification provides the detailed implementation roadmap for the Smart Auto-Complete feature in TimePerk Cortex.*

**Document Version:** 1.0  
**Implementation Phase:** Ready for Development  
**Estimated Complexity:** Medium  
**Dependencies:** Laravel 11, React 18, MySQL 8.0