import React, { useState } from 'react';
import {
  Drawer,
  List,
  ListItem,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  IconButton,
  Box,
  Typography,
  Divider,
  Avatar,
  Chip,
  useTheme,
  useMediaQuery,
  alpha,
  Collapse
} from '@mui/material';
import {
  Menu as MenuIcon,
  Close as CloseIcon,
  Dashboard as DashboardIcon,
  AccessTime as TimesheetIcon,
  Receipt as ExpenseIcon,
  Assignment as ApprovalIcon,
  Assignment as PlanningIcon,
  Flight as TravelsIcon,
  Settings as SettingsIcon,
  Logout as LogoutIcon,
  ChevronLeft as ChevronLeftIcon,
  ChevronRight as ChevronRightIcon,
  ExpandLess,
  ExpandMore,
  SmartToy as AIIcon,
  Group as TeamIcon,
  FolderOpen as ProjectsIcon,
  AdminPanelSettings as AdminIcon,
  DeleteSweep as ResetIcon
} from '@mui/icons-material';
import { useAuth } from '../Auth/AuthContext';
import { useApprovalCounts } from '../../hooks/useApprovalCounts';
import { useFeatures } from '../../contexts/FeatureContext';
import { useBilling } from '../../contexts/BillingContext';
import ResetDataDialog from '../Admin/ResetDataDialog';

interface SideMenuProps {
  currentPage: string;
  onPageChange: (page: string) => void;
}

const DRAWER_WIDTH = 280;
const DRAWER_WIDTH_COLLAPSED = 72;

export const SideMenu: React.FC<SideMenuProps> = ({ currentPage, onPageChange }) => {
  const { user, logout, isAdmin, hasPermission, isOwner } = useAuth();
  const { counts } = useApprovalCounts(); // Hook para buscar counts
  const { hasTravels, hasAI, hasPlanning } = useFeatures(); // Billing-controlled feature flags
  const { billingSummary } = useBilling(); // Get billing info to check trial status
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('md'));
  const [mobileOpen, setMobileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const [managementOpen, setManagementOpen] = useState(false);
  const [planningOpen, setPlanningOpen] = useState(true); // Planning starts open
  const [administrationOpen, setAdministrationOpen] = useState(true); // Start open for admins
  const [resetDataDialogOpen, setResetDataDialogOpen] = useState(false);

  const menuItems = [
    {
      id: 'dashboard',
      label: 'Dashboard',
      icon: <DashboardIcon />,
      path: 'dashboard',
      show: true
    },
    {
      id: 'timesheets',
      label: 'Timesheets',
      icon: <TimesheetIcon />,
      path: 'timesheets', 
      show: hasPermission('view-timesheets')
    },
    {
      id: 'travels',
      label: 'Travels',
      icon: <TravelsIcon />,
      path: 'travels',
      show: hasTravels && (hasPermission('view-timesheets') || isAdmin())
    },
    {
      id: 'expenses',
      label: 'Expenses', 
      icon: <ExpenseIcon />,
      path: 'expenses',
      show: hasPermission('view-expenses')
    },
    {
      id: 'approvals',
      label: 'Approvals',
      icon: <ApprovalIcon />,
      path: 'approvals',
      show: hasPermission('approve-timesheets') || hasPermission('approve-expenses'),
      badge: counts.total > 0 ? counts.total.toString() : undefined, // Badge dinâmico
      badgeColor: counts.total > 0 ? 'error' : 'default' as 'error' | 'default'
    }
  ];

  const managementItems = [
    {
      id: 'team',
      label: 'Team',
      icon: <TeamIcon />, 
      path: 'team',
      show: isAdmin()
    },
    {
      id: 'admin-projects',
      label: 'Projects',
      icon: <ProjectsIcon />,
      path: 'admin-projects',
      show: hasPermission('view-projects') || hasPermission('manage-projects') || isAdmin()
    },
    {
      id: 'admin-tasks',
      label: 'Tasks',
      icon: <ApprovalIcon />,
      path: 'admin-tasks',
      show: hasPermission('view-tasks') || hasPermission('manage-tasks') || isAdmin()
    },
    {
      id: 'admin-locations',
      label: 'Locations',
      icon: <SettingsIcon />,
      path: 'admin-locations',
      show: hasPermission('view-locations') || hasPermission('manage-locations') || isAdmin()
    },
    {
      id: 'admin-countries',
      label: 'Countries',
      icon: <SettingsIcon />,
      path: 'admin-countries',
      show: hasPermission('view-locations') || hasPermission('manage-locations') || isAdmin()
    },
    {
      id: 'ai-insights',
      label: 'AI Insights',
      icon: <AIIcon />, 
      path: 'ai-insights',
      show: hasAI
    }
  ];

  const visibleManagementItems = managementItems.filter((item) => item.show);

  const hasAnyPermission = (perms: string[]): boolean => perms.some((perm) => hasPermission(perm));
  const planningMenuPermissions = [
    'view-planning',
    'create-planning',
    'edit-own-planning',
    'edit-all-planning',
    'approve-planning',
    'delete-planning'
  ];
  const canSeeAnyPlanningSubmenu = hasAnyPermission(planningMenuPermissions);

  const planningItems = [
    {
      id: 'planning-projects',
      label: 'Projects',
      icon: <ProjectsIcon />,
      path: 'planning',
      show: canSeeAnyPlanningSubmenu
    },
    {
      id: 'planning-locations',
      label: 'Locations',
      icon: <SettingsIcon />,
      path: 'planning-locations',
      show: canSeeAnyPlanningSubmenu
    },
    {
      id: 'planning-users',
      label: 'Users',
      icon: <TeamIcon />,
      path: 'planning-users',
      show: canSeeAnyPlanningSubmenu
    }
  ];

  const visiblePlanningItems = planningItems.filter((item) => item.show);
  const showPlanningSection = hasPlanning && visiblePlanningItems.length > 0;

  const administrationItems = [
    {
      id: 'admin-dashboard',
      label: 'Dashboard',
      icon: <DashboardIcon />,
      path: 'admin',
      show: isAdmin()
    },
    {
      id: 'admin-users',
      label: 'Users',
      icon: <TeamIcon />,
      path: 'admin-users',
      show: isAdmin()
    },
    {
      id: 'billing',
      label: 'Billing',
      icon: <SettingsIcon />,
      path: 'billing',
      show: isAdmin() || isOwner()
    },
    {
      id: 'reset-data',
      label: 'Reset Data',
      icon: <ResetIcon />,
      path: 'reset-data',
      show: isOwner() && billingSummary?.is_trial === true, // Only Owner in Trial plan
      action: true // Special flag to trigger dialog instead of navigation
    }
  ];

  const visibleAdministrationItems = administrationItems.filter((item) => item.show);

  const handleDrawerToggle = () => {
    setMobileOpen(!mobileOpen);
  };

  const handleCollapse = () => {
    setCollapsed(!collapsed);
  };

  const handleItemClick = (path: string, isAction?: boolean) => {
    // Handle special actions (like reset-data)
    if (path === 'reset-data' && isAction) {
      setResetDataDialogOpen(true);
      if (isMobile) {
        setMobileOpen(false);
      }
      return;
    }
    
    // Normal navigation
    onPageChange(path);
    if (isMobile) {
      setMobileOpen(false);
    }
  };

  // Determine user display role badge
  const isOwnerRole = user?.roles?.includes('Owner');
  const isSuperAdmin = user?.roles?.includes('Admin');
  const displayRole = isOwnerRole ? 'Owner' : (isSuperAdmin ? 'Admin' : null);
  const displayName = user?.name || 'User';
  const avatarLetter = displayName.charAt(0).toUpperCase();

  const drawerContent = (
    <Box sx={{ 
      height: '100%', 
      display: 'flex', 
      flexDirection: 'column',
      background: 'linear-gradient(180deg, #1e293b 0%, #0f172a 100%)'
    }}>
      {/* Header */}
      <Box sx={{ 
        p: collapsed ? 1 : 3, 
        display: 'flex', 
        alignItems: 'center', 
        justifyContent: collapsed ? 'center' : 'space-between',
        borderBottom: '1px solid',
        borderColor: alpha(theme.palette.common.white, 0.1)
      }}>
        {!collapsed && (
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <Avatar sx={{ 
              bgcolor: 'primary.main',
              width: 32,
              height: 32
            }}>
              <TimesheetIcon />
            </Avatar>
            <Box>
              <Typography variant="h6" sx={{ color: 'white', fontWeight: 700 }}>
                TimePerk
              </Typography>
              <Typography variant="caption" sx={{ color: 'grey.400' }}>
                Smart Timesheet
              </Typography>
            </Box>
          </Box>
        )}
        
        {!isMobile && (
          <IconButton
            onClick={handleCollapse}
            sx={{ 
              color: 'white',
              '&:hover': { bgcolor: alpha(theme.palette.common.white, 0.1) }
            }}
          >
            {collapsed ? <ChevronRightIcon /> : <ChevronLeftIcon />}
          </IconButton>
        )}
        
        {isMobile && (
          <IconButton
            onClick={handleDrawerToggle}
            sx={{ color: 'white' }}
          >
            <CloseIcon />
          </IconButton>
        )}
      </Box>

      {/* User Info */}
      {!collapsed && (
        <Box sx={{ p: 2, borderBottom: '1px solid', borderColor: alpha(theme.palette.common.white, 0.1) }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
            <Avatar sx={{ bgcolor: 'secondary.main' }}>
              {avatarLetter}
            </Avatar>
            <Box sx={{ flex: 1, minWidth: 0 }}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                <Typography variant="body2" sx={{ color: 'white', fontWeight: 600 }} noWrap>
                  {displayName}
                </Typography>
                {displayRole && (
                  <Chip
                    label={displayRole}
                    size="small"
                    sx={{
                      height: 18,
                      fontSize: '0.65rem',
                      fontWeight: 700,
                      bgcolor: displayRole === 'Owner' ? '#fbbf24' : '#8b5cf6',
                      color: displayRole === 'Owner' ? '#78350f' : 'white',
                      '& .MuiChip-label': {
                        px: 1
                      }
                    }}
                  />
                )}
              </Box>
              <Typography variant="caption" sx={{ color: 'grey.400' }} noWrap>
                {user?.email}
              </Typography>
            </Box>
          </Box>
        </Box>
      )}

      {/* Navigation */}
      <Box sx={{ 
        flex: 1, 
        py: 1, 
        overflow: 'auto',
        minHeight: 0, // Important for flex scrolling
        '&::-webkit-scrollbar': {
          width: '6px'
        },
        '&::-webkit-scrollbar-track': {
          bgcolor: 'transparent'
        },
        '&::-webkit-scrollbar-thumb': {
          bgcolor: alpha(theme.palette.common.white, 0.2),
          borderRadius: '3px',
          '&:hover': {
            bgcolor: alpha(theme.palette.common.white, 0.3)
          }
        }
      }}>
        <List>
          {menuItems.filter(item => item.show).map((item) => (
            <ListItem key={item.id} disablePadding sx={{ mb: 0.5 }}>
              <ListItemButton
                onClick={() => handleItemClick(item.path)}
                selected={currentPage === item.path}
                sx={{
                  mx: 1,
                  borderRadius: 2,
                  color: 'grey.300',
                  '&.Mui-selected': {
                    bgcolor: alpha(theme.palette.primary.main, 0.2),
                    color: 'primary.light',
                    '& .MuiListItemIcon-root': { color: 'primary.light' }
                  },
                  '&:hover': {
                    bgcolor: alpha(theme.palette.common.white, 0.05)
                  }
                }}
              >
                <ListItemIcon sx={{ 
                  minWidth: collapsed ? 0 : 40, 
                  color: 'inherit',
                  justifyContent: 'center'
                }}>
                  {item.icon}
                </ListItemIcon>
                {!collapsed && (
                  <>
                    <ListItemText primary={item.label} />
                    {item.badge && (
                      <Chip
                        label={item.badge}
                        size="small"
                        color={(item as any).badgeColor || 'error'}
                        sx={{ ml: 1, minWidth: 20, height: 20, fontWeight: 600 }}
                      />
                    )}
                  </>
                )}
              </ListItemButton>
            </ListItem>
          ))}

          {/* Planning Section (permission-driven; parent depends on children) */}
          {showPlanningSection && (
            <>
              <Divider sx={{ my: 2, borderColor: alpha(theme.palette.common.white, 0.1) }} />
              
              {!collapsed && (
                <ListItem>
                  <ListItemButton
                    onClick={() => setPlanningOpen(!planningOpen)}
                    sx={{ color: 'grey.400' }}
                  >
                    <ListItemIcon sx={{ color: 'inherit' }}>
                      <PlanningIcon />
                    </ListItemIcon>
                    <ListItemText primary="Planning" />
                    {planningOpen ? <ExpandLess /> : <ExpandMore />}
                  </ListItemButton>
                </ListItem>
              )}

              {/* Show icons when collapsed, or full items when expanded */}
              {collapsed ? (
                // Show only icons when collapsed
                visiblePlanningItems.map((item) => (
                  <ListItem key={item.id} disablePadding sx={{ mb: 0.5 }}>
                    <ListItemButton
                      onClick={() => handleItemClick(item.path)}
                      selected={currentPage === item.path}
                      sx={{
                        mx: 1,
                        borderRadius: 2,
                        color: 'grey.300',
                        justifyContent: 'center',
                        '&.Mui-selected': {
                          bgcolor: alpha(theme.palette.secondary.main, 0.2),
                          color: 'secondary.light',
                          '& .MuiListItemIcon-root': { color: 'secondary.light' }
                        },
                        '&:hover': {
                          bgcolor: alpha(theme.palette.common.white, 0.05)
                        }
                      }}
                    >
                      <ListItemIcon sx={{ minWidth: 0, color: 'inherit', justifyContent: 'center' }}>
                        {item.icon}
                      </ListItemIcon>
                    </ListItemButton>
                  </ListItem>
                ))
              ) : (
                // Show collapsible list when expanded
                <Collapse in={planningOpen} timeout="auto" unmountOnExit>
                  {visiblePlanningItems.map((item) => (
                    <ListItem key={item.id} disablePadding sx={{ mb: 0.5 }}>
                      <ListItemButton
                        onClick={() => handleItemClick(item.path)}
                        selected={currentPage === item.path}
                        sx={{
                          mx: 2,
                          borderRadius: 2,
                          color: 'grey.400',
                          '&.Mui-selected': {
                            bgcolor: alpha(theme.palette.secondary.main, 0.2),
                            color: 'secondary.light',
                            '& .MuiListItemIcon-root': { color: 'secondary.light' }
                          },
                          '&:hover': {
                            bgcolor: alpha(theme.palette.common.white, 0.05)
                          }
                        }}
                      >
                        <ListItemIcon sx={{ minWidth: 36, color: 'inherit' }}>
                          {item.icon}
                        </ListItemIcon>
                        <ListItemText 
                          primary={item.label}
                          primaryTypographyProps={{ fontSize: '0.875rem' }}
                        />
                      </ListItemButton>
                    </ListItem>
                  ))}
                </Collapse>
              )}
            </>
          )}

          {/* Management Section (hidden if no visible children) */}
          {visibleManagementItems.length > 0 && (
            <>
              <Divider sx={{ my: 2, borderColor: alpha(theme.palette.common.white, 0.1) }} />
              
              {!collapsed && (
                <ListItem>
                  <ListItemButton
                    onClick={() => setManagementOpen(!managementOpen)}
                    sx={{ color: 'grey.400' }}
                  >
                    <ListItemIcon sx={{ color: 'inherit' }}>
                      <SettingsIcon />
                    </ListItemIcon>
                    <ListItemText primary="Management" />
                    {managementOpen ? <ExpandLess /> : <ExpandMore />}
                  </ListItemButton>
                </ListItem>
              )}

              {/* Show icons when collapsed, or full items when expanded */}
              {collapsed ? (
                visibleManagementItems.map((item) => (
                  <ListItem key={item.id} disablePadding sx={{ mb: 0.5 }}>
                    <ListItemButton
                      onClick={() => handleItemClick(item.path)}
                      selected={currentPage === item.path}
                      sx={{
                        mx: 1,
                        borderRadius: 2,
                        color: 'grey.300',
                        justifyContent: 'center',
                        '&.Mui-selected': {
                          bgcolor: alpha(theme.palette.secondary.main, 0.2),
                          color: 'secondary.light',
                          '& .MuiListItemIcon-root': { color: 'secondary.light' }
                        },
                        '&:hover': {
                          bgcolor: alpha(theme.palette.common.white, 0.05)
                        }
                      }}
                    >
                      <ListItemIcon sx={{ minWidth: 0, color: 'inherit', justifyContent: 'center' }}>
                        {item.icon}
                      </ListItemIcon>
                    </ListItemButton>
                  </ListItem>
                ))
              ) : (
                <Collapse in={managementOpen} timeout="auto" unmountOnExit>
                  {visibleManagementItems.map((item) => (
                    <ListItem key={item.id} disablePadding sx={{ mb: 0.5 }}>
                      <ListItemButton
                        onClick={() => handleItemClick(item.path)}
                        selected={currentPage === item.path}
                        sx={{
                          mx: 2,
                          borderRadius: 2,
                          color: 'grey.400',
                          '&.Mui-selected': {
                            bgcolor: alpha(theme.palette.secondary.main, 0.2),
                            color: 'secondary.light',
                            '& .MuiListItemIcon-root': { color: 'secondary.light' }
                          },
                          '&:hover': {
                            bgcolor: alpha(theme.palette.common.white, 0.05)
                          }
                        }}
                      >
                        <ListItemIcon sx={{ minWidth: 36, color: 'inherit' }}>
                          {item.icon}
                        </ListItemIcon>
                        <ListItemText 
                          primary={item.label}
                          primaryTypographyProps={{ fontSize: '0.875rem' }}
                        />
                      </ListItemButton>
                    </ListItem>
                  ))}
                </Collapse>
              )}
            </>
          )}

          {/* Administration Section (Admin Only, hidden if no visible children) */}
          {isAdmin() && visibleAdministrationItems.length > 0 && (
            <>
              <Divider sx={{ my: 2, borderColor: alpha(theme.palette.common.white, 0.1) }} />
              
              {!collapsed && (
                <ListItem>
                  <ListItemButton
                    onClick={() => setAdministrationOpen(!administrationOpen)}
                    sx={{ color: 'grey.400' }}
                  >
                    <ListItemIcon sx={{ color: 'inherit' }}>
                      <AdminIcon />
                    </ListItemIcon>
                    <ListItemText primary="Administration" />
                    {administrationOpen ? <ExpandLess /> : <ExpandMore />}
                  </ListItemButton>
                </ListItem>
              )}

              {/* Show icons when collapsed, or full items when expanded */}
              {collapsed ? (
                visibleAdministrationItems.map((item) => (
                  <ListItem key={item.id} disablePadding sx={{ mb: 0.5 }}>
                    <ListItemButton
                      onClick={() => handleItemClick(item.path, item.action)}
                      selected={currentPage === item.path}
                      sx={{
                        mx: 1,
                        borderRadius: 2,
                        color: 'grey.300',
                        justifyContent: 'center',
                        '&.Mui-selected': {
                          bgcolor: alpha('#667eea', 0.2),
                          color: '#a5b4fc',
                          '& .MuiListItemIcon-root': { color: '#a5b4fc' }
                        },
                        '&:hover': {
                          bgcolor: alpha(theme.palette.common.white, 0.05)
                        }
                      }}
                    >
                      <ListItemIcon sx={{ minWidth: 0, color: 'inherit', justifyContent: 'center' }}>
                        {item.icon}
                      </ListItemIcon>
                    </ListItemButton>
                  </ListItem>
                ))
              ) : (
                <Collapse in={administrationOpen} timeout="auto" unmountOnExit>
                  {visibleAdministrationItems.map((item) => (
                    <ListItem key={item.id} disablePadding sx={{ mb: 0.5 }}>
                      <ListItemButton
                        onClick={() => handleItemClick(item.path, item.action)}
                        selected={currentPage === item.path}
                        sx={{
                          mx: 2,
                          borderRadius: 2,
                          color: 'grey.400',
                          '&.Mui-selected': {
                            bgcolor: alpha('#667eea', 0.2),
                            color: '#a5b4fc',
                            '& .MuiListItemIcon-root': { color: '#a5b4fc' }
                          },
                          '&:hover': {
                            bgcolor: alpha(theme.palette.common.white, 0.05)
                          }
                        }}
                      >
                        <ListItemIcon sx={{ minWidth: 36, color: 'inherit' }}>
                          {item.icon}
                        </ListItemIcon>
                        <ListItemText 
                          primary={item.label}
                          primaryTypographyProps={{ fontSize: '0.875rem' }}
                        />
                      </ListItemButton>
                    </ListItem>
                  ))}
                </Collapse>
              )}
            </>
          )}
        </List>
      </Box>

      {/* Footer */}
      <Box sx={{ 
        p: 2, 
        borderTop: '1px solid', 
        borderColor: alpha(theme.palette.common.white, 0.1),
        flexShrink: 0 // Prevent footer from shrinking
      }}>
        <ListItemButton
          onClick={logout}
          sx={{
            borderRadius: 2,
            color: 'grey.300',
            '&:hover': {
              bgcolor: alpha(theme.palette.error.main, 0.1),
              color: 'error.light'
            }
          }}
        >
          <ListItemIcon sx={{ 
            minWidth: collapsed ? 0 : 40,
            color: 'inherit',
            justifyContent: 'center'
          }}>
            <LogoutIcon />
          </ListItemIcon>
          {!collapsed && <ListItemText primary="Logout" />}
        </ListItemButton>
      </Box>
    </Box>
  );

  return (
    <>
      {/* Mobile Menu Button */}
      {isMobile && (
        <IconButton
          onClick={handleDrawerToggle}
          sx={{
            position: 'fixed',
            top: 16,
            left: 16,
            zIndex: theme.zIndex.appBar + 1,
            bgcolor: 'primary.main',
            color: 'white',
            '&:hover': { bgcolor: 'primary.dark' }
          }}
        >
          <MenuIcon />
        </IconButton>
      )}

      {/* Desktop Drawer */}
      {!isMobile && (
        <Drawer
          variant="permanent"
          sx={{
            width: collapsed ? DRAWER_WIDTH_COLLAPSED : DRAWER_WIDTH,
            flexShrink: 0,
            '& .MuiDrawer-paper': {
              width: collapsed ? DRAWER_WIDTH_COLLAPSED : DRAWER_WIDTH,
              boxSizing: 'border-box',
              transition: theme.transitions.create('width', {
                easing: theme.transitions.easing.sharp,
                duration: theme.transitions.duration.enteringScreen,
              }),
              border: 'none',
              boxShadow: '4px 0 20px rgba(0, 0, 0, 0.1)',
              background: 'linear-gradient(180deg, #1e293b 0%, #0f172a 100%)',
              overflow: 'hidden'
            },
          }}
        >
          {drawerContent}
        </Drawer>
      )}

      {/* Mobile Drawer */}
      {isMobile && (
        <Drawer
          variant="temporary"
          open={mobileOpen}
          onClose={handleDrawerToggle}
          ModalProps={{ keepMounted: true }}
          sx={{
            '& .MuiDrawer-paper': {
              width: DRAWER_WIDTH,
              boxSizing: 'border-box',
            },
          }}
        >
          {drawerContent}
        </Drawer>
      )}

      {/* Reset Data Dialog */}
      <ResetDataDialog 
        open={resetDataDialogOpen}
        onClose={() => setResetDataDialogOpen(false)}
      />
    </>
  );
};

export default SideMenu;
