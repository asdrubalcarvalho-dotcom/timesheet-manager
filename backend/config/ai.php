<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for AI-powered features in TimePerk Cortex
    |
    */

    'ollama_enabled' => env('AI_OLLAMA_ENABLED', true),
    'ollama_url' => env('AI_OLLAMA_URL', 'http://ollama:11434'),
    'ollama_model' => env('AI_OLLAMA_MODEL', 'gemma2:2b'),
    
    'confidence_thresholds' => [
        'min' => 0.3,      // Below this, show as low confidence
        'medium' => 0.6,   // Medium confidence suggestions
        'high' => 0.8,     // High confidence suggestions
    ],
    
    'suggestion_limits' => [
        'max_history_days' => 90,
        'max_entries_analyzed' => 15,
        'timeout_seconds' => 30,
    ],
    
    'fallback_enabled' => true,  // Use statistical fallback if AI fails
];