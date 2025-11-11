import React from 'react';
import {
  Grid,
  Card,
  CardContent,
  Typography,
  Box,
  IconButton
} from '@mui/material';
import {
  Business as ProjectIcon,
  Assignment as TaskIcon,
  LocationOn as LocationIcon,
  People as PeopleIcon,
  ChevronRight as ChevronRightIcon
} from '@mui/icons-material';
import { useNavigate } from 'react-router-dom';
import AdminLayout from './AdminLayout';

const AdminDashboard: React.FC = () => {
  const navigate = useNavigate();

  const adminModules = [
    {
      title: 'Projects',
      description: 'Manage projects and assignments',
      icon: <ProjectIcon sx={{ fontSize: 48, color: '#667eea' }} />,
      route: '/admin/projects',
      color: '#667eea'
    },
    {
      title: 'Tasks',
      description: 'Manage tasks and categories',
      icon: <TaskIcon sx={{ fontSize: 48, color: '#43a047' }} />,
      route: '/admin/tasks',
      color: '#43a047'
    },
    {
      title: 'Locations',
      description: 'Manage work locations',
      icon: <LocationIcon sx={{ fontSize: 48, color: '#ff9800' }} />,
      route: '/admin/locations',
      color: '#ff9800'
    },
    {
      title: 'Users',
      description: 'Manage user accounts and roles',
      icon: <PeopleIcon sx={{ fontSize: 48, color: '#e91e63' }} />,
      route: '/admin/users',
      color: '#e91e63'
    }
  ];

  return (
    <AdminLayout title="Administration Dashboard">
      <Grid container spacing={3}>
        {adminModules.map((module) => (
          <Grid item xs={12} sm={6} md={3} key={module.route}>
            <Card
              sx={{
                height: '100%',
                cursor: 'pointer',
                transition: 'all 0.3s ease',
                '&:hover': {
                  transform: 'translateY(-4px)',
                  boxShadow: 6
                }
              }}
              onClick={() => navigate(module.route)}
            >
              <CardContent>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', mb: 2 }}>
                  {module.icon}
                  <IconButton
                    size="small"
                    sx={{
                      bgcolor: `${module.color}15`,
                      color: module.color,
                      '&:hover': {
                        bgcolor: `${module.color}25`
                      }
                    }}
                  >
                    <ChevronRightIcon />
                  </IconButton>
                </Box>
                <Typography variant="h6" sx={{ fontWeight: 600, mb: 0.5 }}>
                  {module.title}
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  {module.description}
                </Typography>
              </CardContent>
            </Card>
          </Grid>
        ))}
      </Grid>
    </AdminLayout>
  );
};

export default AdminDashboard;
