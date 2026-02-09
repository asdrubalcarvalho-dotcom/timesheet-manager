import api from './api';
import type { AiTimesheetApplyResponse, AiTimesheetPlan, AiTimesheetPreviewResponse } from '../types';

const isDev = typeof import.meta !== 'undefined' && !!import.meta.env?.DEV;

const logAiTimesheet = (message: string, detail?: unknown): void => {
  if (!isDev) return;
  if (typeof detail === 'undefined') {
    console.info(`AI_TIMESHEET: ${message}`);
  } else {
    console.info(`AI_TIMESHEET: ${message}`, detail);
  }
};

const getLocalTimezone = (): string => {
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  return timezone && timezone.trim().length > 0 ? timezone : 'UTC';
};

export const previewTimesheetPlan = async (
  prompt: string,
  timezone: string
): Promise<AiTimesheetPreviewResponse> => {
  const payload = { prompt: prompt.trim(), timezone: timezone.trim() };
  logAiTimesheet('calling preview');
  const response = await api.post('/api/ai/timesheet/preview', payload);
  logAiTimesheet('preview response', response.status);
  return response.data as AiTimesheetPreviewResponse;
};

export const commitTimesheetPlan = async (
  plan: AiTimesheetPlan,
  clientRequestId?: string
): Promise<AiTimesheetApplyResponse> => {
  logAiTimesheet('calling commit');
  const response = await api.post('/api/ai/timesheet/commit', {
    confirmed: true,
    plan,
    client_request_id: clientRequestId,
  });
  logAiTimesheet('commit response', response.status);
  return response.data as AiTimesheetApplyResponse;
};

export const previewAiTimesheet = async (prompt: string): Promise<AiTimesheetPreviewResponse> =>
  previewTimesheetPlan(prompt, getLocalTimezone());

export const commitAiTimesheet = async (payload: {
  requestId: string;
  plan: AiTimesheetPlan;
}): Promise<AiTimesheetApplyResponse> => {
  const response = await api.post('/api/ai/timesheet/commit', {
    request_id: payload.requestId,
    confirmed: true,
    plan: payload.plan,
  });
  return response.data as AiTimesheetApplyResponse;
};
