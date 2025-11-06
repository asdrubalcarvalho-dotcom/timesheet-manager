import React from 'react';
import {
  Card,
  CardContent,
  Typography,
  Button,
  Box,
  Chip,
  Alert,
  Skeleton,
  Stack,
  Divider,
  Avatar,
  Fade,
  useTheme,
  alpha
} from '@mui/material';
import {
  CheckCircle as AcceptIcon,
  Cancel as RejectIcon,
  Info as InfoIcon,
  AutoAwesome as MagicIcon,
  SmartToy as RobotIcon
} from '@mui/icons-material';
import type { AISuggestion } from '../../services/aiService';

interface AISuggestionCardProps {
  suggestion: AISuggestion | null;
  isLoading: boolean;
  isAIAvailable: boolean;
  error: string | null;
  onApply: () => void;
  onDismiss: () => void;
  onFeedback: (accepted: boolean) => void;
}

export const AISuggestionCard: React.FC<AISuggestionCardProps> = ({
  suggestion,
  isLoading,
  isAIAvailable,
  error,
  onApply,
  onDismiss,
  onFeedback
}) => {
  // Don't render if AI is not available and there's no suggestion
  if (!isAIAvailable && !suggestion && !isLoading && !error) {
    return null;
  }

  const getConfidenceColor = (confidence: number) => {
    if (confidence >= 0.8) return 'success';
    if (confidence >= 0.6) return 'info';
    if (confidence >= 0.4) return 'warning';
    return 'error';
  };

  const getConfidenceLabel = (confidence: number) => {
    if (confidence >= 0.8) return 'High Confidence';
    if (confidence >= 0.6) return 'Medium Confidence';
    if (confidence >= 0.4) return 'Low Confidence';
    return 'Very Low Confidence';
  };

  const theme = useTheme();

  return (
    <Fade in={true} timeout={500}>
      <Card 
        sx={{ 
          mb: 3, 
          background: suggestion 
            ? 'linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%)'
            : 'linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%)',
          border: suggestion ? '2px solid' : '1px solid',
          borderColor: suggestion ? 'primary.main' : alpha(theme.palette.grey[300], 0.5),
          position: 'relative',
          overflow: 'visible',
          borderRadius: 3,
          boxShadow: suggestion 
            ? '0 8px 32px rgba(102, 126, 234, 0.2)' 
            : '0 4px 16px rgba(0, 0, 0, 0.08)',
          transition: 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)',
          '&:hover': {
            transform: 'translateY(-2px)',
            boxShadow: suggestion 
              ? '0 12px 40px rgba(102, 126, 234, 0.25)' 
              : '0 8px 24px rgba(0, 0, 0, 0.12)'
          }
        }}
      >
        {/* Enhanced AI Badge */}
        <Box
          sx={{
            position: 'absolute',
            top: -16,
            left: 20,
            background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            borderRadius: '20px',
            padding: '8px 16px',
            boxShadow: '0 4px 16px rgba(102, 126, 234, 0.3)',
            display: 'flex',
            alignItems: 'center',
            gap: 1
          }}
        >
          <Avatar sx={{ 
            bgcolor: 'rgba(255, 255, 255, 0.2)', 
            width: 24, 
            height: 24,
            '& .MuiSvgIcon-root': { fontSize: 16 }
          }}>
            <RobotIcon />
          </Avatar>
          <Typography 
            variant="caption" 
            sx={{ 
              color: 'white', 
              fontWeight: 700,
              fontSize: '0.75rem',
              letterSpacing: 0.5
            }}
          >
            AI CORTEX
          </Typography>
        </Box>

      <CardContent sx={{ pt: 3 }}>
        {/* Loading State */}
        {isLoading && (
          <Box>
            <Stack spacing={1}>
              <Skeleton variant="text" width="60%" />
              <Skeleton variant="text" width="80%" />
              <Skeleton variant="rectangular" height={60} />
            </Stack>
          </Box>
        )}

        {/* Error State */}
        {error && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
        )}

        {/* AI Not Available */}
        {!isAIAvailable && !suggestion && !isLoading && (
          <Alert severity="info">
            <Typography variant="body2">
              AI suggestions are currently unavailable. Using fallback mode for basic suggestions.
            </Typography>
          </Alert>
        )}

        {/* Suggestion Content */}
        {suggestion && (
          <Box>
            {/* Header with Confidence */}
            <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
              <Typography variant="h6" sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                <MagicIcon color="primary" />
                Smart Suggestion
              </Typography>
              <Chip
                label={getConfidenceLabel(suggestion.confidence)}
                color={getConfidenceColor(suggestion.confidence)}
                size="small"
                variant="outlined"
              />
            </Box>

            {/* Main Suggestion */}
            <Box sx={{ mb: 2 }}>
              <Typography variant="body1" sx={{ fontWeight: 'bold', mb: 1 }}>
                Suggested Hours: {suggestion.suggested_hours}h
              </Typography>
              <Typography variant="body1" sx={{ mb: 1 }}>
                <strong>Description:</strong> {suggestion.suggested_description}
              </Typography>
            </Box>

            {/* Alternative Descriptions */}
            {suggestion.alternative_descriptions && suggestion.alternative_descriptions.length > 0 && (
              <Box sx={{ mb: 2 }}>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
                  Alternative descriptions:
                </Typography>
                <Stack direction="row" spacing={1} flexWrap="wrap">
                  {suggestion.alternative_descriptions.map((desc: string, index: number) => (
                    <Chip
                      key={index}
                      label={desc}
                      variant="outlined"
                      size="small"
                      sx={{ mb: 0.5 }}
                    />
                  ))}
                </Stack>
              </Box>
            )}

            <Divider sx={{ my: 2 }} />

            {/* Reasoning */}
            <Box sx={{ mb: 2 }}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1 }}>
                <InfoIcon fontSize="small" color="info" />
                <Typography variant="body2" color="text.secondary">
                  AI Reasoning:
                </Typography>
              </Box>
              <Typography variant="body2" sx={{ fontStyle: 'italic' }}>
                {suggestion.reasoning}
              </Typography>
            </Box>

            {/* Action Buttons */}
            <Box sx={{ display: 'flex', gap: 1, justifyContent: 'space-between', flexWrap: 'wrap' }}>
              <Box sx={{ display: 'flex', gap: 1 }}>
                <Button
                  variant="contained"
                  color="primary"
                  startIcon={<AcceptIcon />}
                  onClick={() => {
                    onApply();
                    onFeedback(true);
                  }}
                  size="small"
                >
                  Apply Suggestion
                </Button>
                <Button
                  variant="outlined"
                  color="inherit"
                  startIcon={<RejectIcon />}
                  onClick={() => {
                    onDismiss();
                    onFeedback(false);
                  }}
                  size="small"
                >
                  Dismiss
                </Button>
              </Box>

              {/* Confidence Indicator */}
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                <Typography variant="caption" color="text.secondary">
                  Confidence: {Math.round(suggestion.confidence * 100)}%
                </Typography>
              </Box>
            </Box>
          </Box>
        )}
      </CardContent>
    </Card>
    </Fade>
  );
};

export default AISuggestionCard;