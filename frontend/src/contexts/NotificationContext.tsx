import React, { createContext, useContext, useState, useCallback, useEffect, type ReactNode } from 'react';
import AlertSnackbar from '../components/Common/AlertSnackbar';
import type { AlertColor } from '@mui/material';
import { setGlobalNotifier } from '../services/globalNotifications';

interface Notification {
  id: number;
  message: string;
  severity: AlertColor;
}

interface NotificationContextType {
  showNotification: (message: string, severity?: AlertColor) => void;
  showSuccess: (message: string) => void;
  showError: (message: string) => void;
  showWarning: (message: string) => void;
  showInfo: (message: string) => void;
}

const NotificationContext = createContext<NotificationContextType | undefined>(undefined);

export const useNotification = (): NotificationContextType => {
  const context = useContext(NotificationContext);
  if (!context) {
    throw new Error('useNotification must be used within a NotificationProvider');
  }
  return context;
};

interface NotificationProviderProps {
  children: ReactNode;
}

export const NotificationProvider: React.FC<NotificationProviderProps> = ({ children }) => {
  const [notification, setNotification] = useState<Notification | null>(null);

  const showNotification = useCallback((message: string, severity: AlertColor = 'info') => {
    setNotification({
      id: Date.now(),
      message,
      severity,
    });
  }, []);

  const showSuccess = useCallback((message: string) => {
    showNotification(message, 'success');
  }, [showNotification]);

  const showError = useCallback((message: string) => {
    showNotification(message, 'error');
  }, [showNotification]);

  const showWarning = useCallback((message: string) => {
    showNotification(message, 'warning');
  }, [showNotification]);

  const showInfo = useCallback((message: string) => {
    showNotification(message, 'info');
  }, [showNotification]);

  useEffect(() => {
    setGlobalNotifier((message: string, severity: AlertColor) => {
      showNotification(message, severity);
    });

    return () => {
      setGlobalNotifier(null);
    };
  }, [showNotification]);

  const handleClose = useCallback(() => {
    setNotification(null);
  }, []);

  return (
    <NotificationContext.Provider
      value={{
        showNotification,
        showSuccess,
        showError,
        showWarning,
        showInfo,
      }}
    >
      {children}
      {notification && (
        <AlertSnackbar
          open={true}
          message={notification.message}
          severity={notification.severity}
          onClose={handleClose}
        />
      )}
    </NotificationContext.Provider>
  );
};
