import React, { useState, useEffect } from 'react';
import {
  Box,
  Typography,
  Card,
  CardContent,
  Grid,
  Chip,
  LinearProgress,
  Paper,
  List,
  ListItem,
  ListItemIcon,
  ListItemText,
  Divider,
  useTheme
} from '@mui/material';
import {
  TrendingUp,
  Analytics,
  Psychology,
  Timer,
  Assignment,
  Warning,
  CheckCircle
} from '@mui/icons-material';

interface AIInsight {
  id: string;
  title: string;
  description: string;
  type: 'positive' | 'warning' | 'neutral';
  confidence: number;
  category: string;
}

export const AIInsights: React.FC = () => {
  const theme = useTheme();
  const [insights, setInsights] = useState<AIInsight[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Simular carregamento de insights da IA
    const timer = setTimeout(() => {
      setInsights([
        {
          id: '1',
          title: 'High Productivity',
          description: 'João Silva increased his productivity by 23% this week with focus on Frontend Development.',
          type: 'positive',
          confidence: 89,
          category: 'Productivity'
        },
        {
          id: '2',
          title: 'Possible Overload',
          description: 'Carlos Manager has 45h registered this week, above the 40h average.',
          type: 'warning',
          confidence: 76,
          category: 'Workload'
        },
        {
          id: '3',
          title: 'Optimized Pattern',
          description: 'Website Redesign project has 92% of timesheets approved on first submission.',
          type: 'positive',
          confidence: 94,
          category: 'Quality'
        },
        {
          id: '4',
          title: 'Optimal Hours Identified',
          description: 'Team is most productive between 10am-12pm and 2pm-4pm based on 30-day analysis.',
          type: 'neutral',
          confidence: 82,
          category: 'Temporal'
        }
      ]);
      setLoading(false);
    }, 1500);

    return () => clearTimeout(timer);
  }, []);

  const getInsightIcon = (type: string) => {
    switch (type) {
      case 'positive':
        return <CheckCircle sx={{ color: theme.palette.success.main }} />;
      case 'warning':
        return <Warning sx={{ color: theme.palette.warning.main }} />;
      default:
        return <Analytics sx={{ color: theme.palette.info.main }} />;
    }
  };

  const getInsightColor = (type: string) => {
    switch (type) {
      case 'positive':
        return theme.palette.success.main;
      case 'warning':
        return theme.palette.warning.main;
      default:
        return theme.palette.info.main;
    }
  };

  if (loading) {
    return (
      <Box sx={{ p: 3 }}>
        <Typography variant="h4" sx={{ mb: 3, display: 'flex', alignItems: 'center', gap: 1 }}>
          <Psychology sx={{ color: theme.palette.primary.main }} />
          🤖 AI Insights
        </Typography>
        <Paper sx={{ p: 3, textAlign: 'center' }}>
          <Typography variant="body1" sx={{ mb: 2 }}>
            Analyzing data with artificial intelligence...
          </Typography>
          <LinearProgress />
        </Paper>
      </Box>
    );
  }

  return (
    <Box sx={{ p: 3 }}>
      <Typography variant="h4" sx={{ mb: 1, display: 'flex', alignItems: 'center', gap: 1 }}>
        <Psychology sx={{ color: theme.palette.primary.main }} />
        🤖 AI Insights
      </Typography>
      <Typography variant="body2" color="textSecondary" sx={{ mb: 3 }}>
        Intelligent analysis based on timesheet and productivity patterns
      </Typography>

      {/* Resumo Rápido */}
      <Grid container spacing={3} sx={{ mb: 4 }}>
        <Grid item xs={12} md={3}>
          <Card sx={{ background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', color: 'white' }}>
            <CardContent>
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                <TrendingUp />
                <Typography variant="h6" sx={{ ml: 1 }}>
                  Productivity
                </Typography>
              </Box>
              <Typography variant="h4">+15%</Typography>
              <Typography variant="body2">vs. previous month</Typography>
            </CardContent>
          </Card>
        </Grid>

        <Grid item xs={12} md={3}>
          <Card sx={{ background: 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)', color: 'white' }}>
            <CardContent>
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                <Timer />
                <Typography variant="h6" sx={{ ml: 1 }}>
                  Efficiency
                </Typography>
              </Box>
              <Typography variant="h4">87%</Typography>
              <Typography variant="body2">approval rate</Typography>
            </CardContent>
          </Card>
        </Grid>

        <Grid item xs={12} md={3}>
          <Card sx={{ background: 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)', color: 'white' }}>
            <CardContent>
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                <Assignment />
                <Typography variant="h6" sx={{ ml: 1 }}>
                  Projects
                </Typography>
              </Box>
              <Typography variant="h4">3</Typography>
              <Typography variant="body2">active in period</Typography>
            </CardContent>
          </Card>
        </Grid>

        <Grid item xs={12} md={3}>
          <Card sx={{ background: 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)', color: 'white' }}>
            <CardContent>
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                <Analytics />
                <Typography variant="h6" sx={{ ml: 1 }}>
                  Insights
                </Typography>
              </Box>
              <Typography variant="h4">{insights.length}</Typography>
              <Typography variant="body2">active recommendations</Typography>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      {/* Lista de Insights */}
      <Paper sx={{ p: 3 }}>
        <Typography variant="h6" sx={{ mb: 2 }}>
          Recent Insights
        </Typography>
        <List>
          {insights.map((insight, index) => (
            <React.Fragment key={insight.id}>
              <ListItem sx={{ px: 0 }}>
                <ListItemIcon>
                  {getInsightIcon(insight.type)}
                </ListItemIcon>
                <ListItemText
                  primary={
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 0.5 }}>
                      <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>
                        {insight.title}
                      </Typography>
                      <Chip 
                        label={insight.category} 
                        size="small" 
                        sx={{ 
                          backgroundColor: getInsightColor(insight.type) + '20',
                          color: getInsightColor(insight.type),
                          fontWeight: 500
                        }} 
                      />
                    </Box>
                  }
                  secondary={
                    <Box>
                      <Typography variant="body2" color="textSecondary" sx={{ mb: 1 }}>
                        {insight.description}
                      </Typography>
                      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                        <Typography variant="caption" color="textSecondary">
                          Confidence: {insight.confidence}%
                        </Typography>
                        <LinearProgress
                          variant="determinate"
                          value={insight.confidence}
                          sx={{ 
                            width: 60, 
                            height: 4,
                            '& .MuiLinearProgress-bar': {
                              backgroundColor: getInsightColor(insight.type)
                            }
                          }}
                        />
                      </Box>
                    </Box>
                  }
                />
              </ListItem>
              {index < insights.length - 1 && <Divider />}
            </React.Fragment>
          ))}
        </List>
      </Paper>

      {/* Nota sobre IA */}
      <Box sx={{ mt: 3, p: 2, backgroundColor: theme.palette.grey[50], borderRadius: 1 }}>
        <Typography variant="caption" color="textSecondary">
          💡 <strong>About AI Insights:</strong> These insights are generated by machine learning algorithms that analyze patterns in timesheets, 
          work hours and approvals. Accuracy improves continuously with more data.
        </Typography>
      </Box>
    </Box>
  );
};

export default AIInsights;