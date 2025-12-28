export type NotificationSeverity = 'success' | 'error' | 'warning' | 'info';

export type GlobalNotifier = (message: string, severity: NotificationSeverity) => void;

let globalNotifier: GlobalNotifier | null = null;

export const setGlobalNotifier = (notifier: GlobalNotifier | null): void => {
  globalNotifier = notifier;
};

export const notifyGlobal = (message: string, severity: NotificationSeverity = 'info'): void => {
  if (!globalNotifier) return;

  const trimmed = typeof message === 'string' ? message.trim() : '';
  if (!trimmed) return;

  globalNotifier(trimmed, severity);
};
