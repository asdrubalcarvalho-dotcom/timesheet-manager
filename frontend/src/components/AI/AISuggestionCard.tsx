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
  onApply: (selectedHours: number | null, selectedDescription: string) => void;
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
  const [selectedDescription, setSelectedDescription] = React.useState<string>('');
  const [selectedHours, setSelectedHours] = React.useState<number | null>(null);

  // Update selected description when suggestion changes
  React.useEffect(() => {
    if (suggestion?.suggested_description) {
      // Reset selections when new suggestion arrives
      setSelectedDescription('');
      setSelectedHours(null);
    }
  }, [suggestion]);

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
        {/* AI Badge - Right positioned where Confidence was */}
        <Box
          sx={{
            position: 'absolute',
            top: 8,
            right: 8,
            background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            borderRadius: '12px',
            padding: '2px 8px',
            zIndex: 10,
            boxShadow: '0 2px 8px rgba(102, 126, 234, 0.3)',
            display: 'flex',
            alignItems: 'center',
            gap: 0.5,
            width: 'fit-content'
          }}
        >
          <Avatar sx={{ 
            bgcolor: 'rgba(255, 255, 255, 0.2)', 
            width: 16, 
            height: 16,
            '& .MuiSvgIcon-root': { fontSize: 12 }
          }}>
            <RobotIcon />
          </Avatar>
          <Typography 
            variant="caption" 
            sx={{ 
              color: 'white', 
              fontWeight: 700,
              fontSize: '0.6rem',
              letterSpacing: 0.3,
              whiteSpace: 'nowrap'
            }}
          >
            AI CORTEX
          </Typography>
        </Box>

      <CardContent sx={{ pt: 2, pb: 1.5 }}>
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
            {/* Header with Confidence inline */}
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 1.5 }}>
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

            {/* Hours Selection - Clickable Chips */}
            <Box sx={{ mb: 2 }}>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 0.75, fontSize: '0.8rem' }}>
                Suggested Duration (click to select):
              </Typography>
              <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>
                <Chip
                  label={`${suggestion.suggested_hours}h`}
                  variant={selectedHours === suggestion.suggested_hours ? "filled" : "outlined"}
                  color="primary"
                  size="medium"
                  clickable
                  onClick={() => setSelectedHours(
                    selectedHours === suggestion.suggested_hours ? null : suggestion.suggested_hours
                  )}
                  sx={{ fontWeight: 'bold' }}
                />
                {/* Alternative hours options */}
                {[4, 6, 8].filter(h => h !== suggestion.suggested_hours).map((hours) => (
                  <Chip
                    key={hours}
                    label={`${hours}h`}
                    variant={selectedHours === hours ? "filled" : "outlined"}
                    color="primary"
                    size="medium"
                    clickable
                    onClick={() => setSelectedHours(selectedHours === hours ? null : hours)}
                  />
                ))}
              </Box>
            </Box>

            {/* Description Selection - Clickable Chips */}
            <Box sx={{ mb: 2 }}>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 0.75, fontSize: '0.8rem' }}>
                Suggested Description (click to select):
              </Typography>
              <Box sx={{ 
                display: 'flex', 
                flexDirection: 'column',
                gap: 0.75
              }}>
                {/* Primary suggestion */}
                <Chip
                  label={suggestion.suggested_description}
                  variant={selectedDescription === suggestion.suggested_description ? "filled" : "outlined"}
                  color="primary"
                  clickable
                  onClick={() => setSelectedDescription(
                    selectedDescription === suggestion.suggested_description ? '' : suggestion.suggested_description
                  )}
                  sx={{ 
                    height: 'auto',
                    py: 1,
                    '& .MuiChip-label': { 
                      whiteSpace: 'normal',
                      textAlign: 'left'
                    }
                  }}
                />
                {/* Alternative descriptions */}
                {suggestion.alternative_descriptions?.map((desc, idx) => (
                  <Chip
                    key={idx}
                    label={desc}
                    variant={selectedDescription === desc ? "filled" : "outlined"}
                    color="primary"
                    clickable
                    onClick={() => setSelectedDescription(selectedDescription === desc ? '' : desc)}
                    sx={{ 
                      height: 'auto',
                      py: 1,
                      '& .MuiChip-label': { 
                        whiteSpace: 'normal',
                        textAlign: 'left'
                      }
                    }}
                  />
                ))}
              </Box>
            </Box>

            <Divider sx={{ my: 1.5 }} />

            {/* Reasoning - Compact */}
            <Box sx={{ mb: 1.5 }}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, mb: 0.5 }}>
                <InfoIcon fontSize="small" color="info" />
                <Typography variant="body2" color="text.secondary" sx={{ fontSize: '0.75rem' }}>
                  AI Reasoning:
                </Typography>
              </Box>
              <Typography variant="body2" sx={{ fontStyle: 'italic', fontSize: '0.75rem', lineHeight: 1.3 }}>
                {suggestion.reasoning}
              </Typography>
            </Box>

            {/* Action Buttons */}
            <Box sx={{ display: 'flex', gap: 0.5, justifyContent: 'space-between', alignItems: 'center' }}>
              <Box sx={{ display: 'flex', gap: 0.5 }}>
                <Button
                  variant="contained"
                  color="primary"
                  startIcon={<AcceptIcon />}
                  onClick={() => {
                    onApply(selectedHours, selectedDescription);
                    onFeedback(true);
                  }}
                  size="small"
                  disabled={!selectedHours && !selectedDescription}
                  sx={{ fontSize: '0.75rem', px: 2 }}
                >
                  APPLY SUGGESTION
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
                  sx={{ fontSize: '0.75rem', px: 1.5 }}
                >
                  DISMISS
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