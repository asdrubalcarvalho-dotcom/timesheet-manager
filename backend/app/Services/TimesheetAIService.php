<?php

namespace App\Services;

use App\Models\Timesheet;
use App\Models\Technician;
use App\Data\TimesheetValidationSnapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TimesheetAIService
{
    private string $ollamaUrl;
    private bool $ollamaEnabled;
    
    public function __construct()
    {
        $this->ollamaUrl = config('ai.ollama_url', 'http://ollama:11434');
        $this->ollamaEnabled = config('ai.ollama_enabled', true);
    }
    
    /**
     * Generate intelligent timesheet suggestions
     */
    public function generateSuggestion(int $userId, int $projectId, array $context): array
    {
        $technicianId = $this->resolveTechnicianId($userId);

        if (!$technicianId) {
            return $this->generateStatisticalSuggestion(null, $projectId, $context);
        }

        try {
            // Try AI-powered suggestion first
            if ($this->ollamaEnabled && $this->isOllamaAvailable()) {
                $aiSuggestion = $this->generateAISuggestion($technicianId, $projectId, $context);
                if ($aiSuggestion['success']) {
                    return $aiSuggestion;
                }
            }
            
            // Fallback to statistical analysis
            return $this->generateStatisticalSuggestion($technicianId, $projectId, $context);
            
        } catch (\Exception $e) {
            Log::error('AI Service Error: ' . $e->getMessage());
            return $this->generateStatisticalSuggestion($technicianId, $projectId, $context);
        }
    }

    /**
     * Lightweight anomaly detection used by validation snapshots.
     */
    public function analyzeTimesheet(TimesheetValidationSnapshot $snapshot): array
    {
        $issues = [];
        $score = 0.15; // base confidence

        if ($snapshot->hoursWorked >= 10) {
            $issues[] = 'Turno prolongado (>=10h).';
            $score += 0.25;
        }

        if ($snapshot->dailyTotalHours > 12) {
            $issues[] = 'Carga diária excede 12h para este técnico.';
            $score += 0.25;
        }

        if ($snapshot->overlapRisk === 'block') {
            $issues[] = 'Horário sobrepõe outro registo.';
            $score += 0.2;
        } elseif ($snapshot->overlapRisk === 'warning') {
            $issues[] = 'Sem hora inicial/final para validar sobreposição.';
            $score += 0.1;
        }

        if (!$snapshot->membershipOk) {
            $issues[] = 'Técnico não está associado ao projeto.';
            $score += 0.2;
        }

        if (!$snapshot->projectActive) {
            $issues[] = 'Projeto encontra-se inativo.';
            $score += 0.1;
        }

        // Basic heuristic: normalize score between 0 and 1
        $normalizedScore = min(max($score, 0), 1);
        $flagged = $normalizedScore >= 0.6 || !empty($issues);

        return [
            'flagged' => $flagged,
            'score' => round($normalizedScore, 2),
            'feedback' => $issues,
            'source' => $this->ollamaEnabled ? 'ai' : 'heuristic',
        ];
    }
    
    /**
     * Generate AI-powered suggestion using Ollama/Gemma
     */
    private function generateAISuggestion(int $technicianId, int $projectId, array $context): array
    {
        $userHistory = $this->getUserHistory($technicianId, $projectId);
        $prompt = $this->buildPrompt($userHistory, $context);
        
        try {
            $response = Http::timeout(30)->post("{$this->ollamaUrl}/api/generate", [
                'model' => 'gemma2:2b',
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => 0.2,  // Low temperature for consistency
                    'top_p' => 0.8,
                    'num_predict' => 200   // Limit response length
                ]
            ]);
            
            if ($response->successful()) {
                $result = $this->parseAIResponse($response->json());
                $result['source'] = 'ai';
                $result['success'] = true;
                return $result;
            }
            
        } catch (\Exception $e) {
            Log::warning('Ollama API Error: ' . $e->getMessage());
        }
        
        return ['success' => false];
    }
    
    /**
     * Fallback statistical suggestion
     */
    private function generateStatisticalSuggestion(?int $technicianId, int $projectId, array $context): array
    {
        if (!$technicianId) {
            return [
                'success' => true,
                'source' => 'default',
                'suggested_hours' => 8.0,
                'confidence' => 0.3,
                'description' => 'General project work',
                'reasoning' => 'No technician history available'
            ];
        }

        $recentEntries = Timesheet::where('technician_id', $technicianId)
            ->where('project_id', $projectId)
            ->where('date', '>=', now()->subDays(30))
            ->orderBy('date', 'desc')
            ->limit(10)
            ->get();
            
        if ($recentEntries->count() < 2) {
            return [
                'success' => true,
                'source' => 'default',
                'suggested_hours' => 8.0,
                'confidence' => 0.3,
                'description' => 'Development work',
                'reasoning' => 'Default suggestion (insufficient history)'
            ];
        }
        
        $avgHours = $recentEntries->avg('hours_worked');
        $descriptions = $recentEntries->pluck('description')
            ->filter()
            ->take(3)
            ->toArray();
            
        $confidence = min(0.8, $recentEntries->count() / 10);
        
        return [
            'success' => true,
            'source' => 'statistical',
            'suggested_hours' => round($avgHours, 1),
            'confidence' => $confidence,
            'description' => $descriptions[0] ?? 'Development work',
            'alternatives' => array_slice($descriptions, 1, 2),
            'reasoning' => "Based on {$recentEntries->count()} recent entries"
        ];
    }
    
    /**
     * Build AI prompt for timesheet suggestion
     */
    private function buildPrompt(array $history, array $context): string
    {
        $historyText = '';
        foreach ($history as $entry) {
            $historyText .= "Date: {$entry['date']}, Hours: {$entry['hours']}, Task: {$entry['description']}\n";
        }
        
        $targetDate = Carbon::parse($context['date']);
        $dayOfWeek = $targetDate->format('l');
        
        return "You are a timesheet assistant. Based on this user's work history:

{$historyText}

Project: {$context['project_name']}
Target Date: {$context['date']} ({$dayOfWeek})

Suggest appropriate work hours and task description. Consider:
- User's typical patterns for this project and day of week
- Recent work trends
- Realistic work hours (usually 6-10 hours)

Respond ONLY with valid JSON in this exact format:
{\"hours\": 8.0, \"description\": \"Frontend development\", \"confidence\": 85}

JSON Response:";
    }
    
    /**
     * Parse AI response and extract suggestion
     */
    private function parseAIResponse(array $response): array
    {
        $text = $response['response'] ?? '';
        
        // Extract JSON from response
        if (preg_match('/\{[^}]+\}/', $text, $matches)) {
            $jsonStr = $matches[0];
            $decoded = json_decode($jsonStr, true);
            
            if ($decoded && isset($decoded['hours'], $decoded['description'])) {
                return [
                    'suggested_hours' => (float) $decoded['hours'],
                    'description' => trim($decoded['description']),
                    'confidence' => ($decoded['confidence'] ?? 80) / 100,
                    'reasoning' => 'AI analysis of historical patterns'
                ];
            }
        }
        
        // Fallback parsing
        preg_match('/(\d+\.?\d*)\s*hours?/i', $text, $hourMatches);
        preg_match('/"([^"]*development[^"]*)"/i', $text, $descMatches);
        
        return [
            'suggested_hours' => (float) ($hourMatches[1] ?? 8.0),
            'description' => $descMatches[1] ?? 'Development work',
            'confidence' => 0.7,
            'reasoning' => 'AI analysis with fallback parsing'
        ];
    }
    
    /**
     * Get user's recent timesheet history
     */
    private function getUserHistory(int $technicianId, int $projectId): array
    {
        return Timesheet::where('technician_id', $technicianId)
            ->where('project_id', $projectId)
            ->where('date', '>=', now()->subDays(90))
            ->orderBy('date', 'desc')
            ->limit(15)
            ->get(['date', 'hours_worked as hours', 'description'])
            ->toArray();
    }
    
    /**
     * Check if Ollama is available
     */
    private function isOllamaAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->ollamaUrl}/api/tags");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function resolveTechnicianId(int $userId): ?int
    {
        return Technician::where('user_id', $userId)->value('id');
    }
}
