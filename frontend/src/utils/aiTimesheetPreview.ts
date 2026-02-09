import type { TFunction } from 'i18next';
import type { AiTimesheetPlan } from '../types';

export type AiTimesheetPreviewErrorInput = {
  message: string;
  t: TFunction;
};

export type AiTimesheetPreviewErrorMapping = {
  title: string;
  message: string;
  severity: 'error' | 'warning';
  actions: string[];
};

export const mapAiTimesheetPreviewError = (
  input: AiTimesheetPreviewErrorInput
): AiTimesheetPreviewErrorMapping => {
  const rawMessage = input.message?.trim() || '';
  const normalized = rawMessage.toLowerCase();

  if (
    normalized.includes('cannot validate overlaps on') &&
    normalized.includes('existing entries without time')
  ) {
    return {
      title: input.t('aiTimesheet.previewErrors.existingNoTime.title'),
      message: input.t('aiTimesheet.previewErrors.existingNoTime.message'),
      severity: 'error',
      actions: [
        input.t('aiTimesheet.previewErrors.existingNoTime.labels.howToFix'),
        input.t('aiTimesheet.previewErrors.existingNoTime.labels.reviewExistingEntries'),
        input.t('aiTimesheet.previewErrors.existingNoTime.labels.replaceEntries'),
        input.t('aiTimesheet.previewErrors.existingNoTime.actions.chooseDates'),
      ],
    };
  }

  if (
    normalized.includes('overlapping time ranges detected') ||
    normalized.includes('missing time range') ||
    normalized.includes('invalid time range') ||
    normalized.includes('end time must be after start time')
  ) {
    return {
      title: input.t('aiTimesheet.previewErrors.overlap.title'),
      message: rawMessage || input.t('aiTimesheet.previewErrors.overlap.message'),
      severity: 'error',
      actions: [
        input.t('aiTimesheet.previewErrors.overlap.actions.addTimes'),
        input.t('aiTimesheet.previewErrors.overlap.actions.adjustTimes'),
      ],
    };
  }

  if (normalized.includes('break required for continuous work')) {
    return {
      title: input.t('aiTimesheet.previewErrors.break.title'),
      message: rawMessage || input.t('aiTimesheet.previewErrors.break.message'),
      severity: 'warning',
      actions: [
        input.t('aiTimesheet.previewErrors.break.actions.addBreak'),
        input.t('aiTimesheet.previewErrors.break.actions.reviewPolicy'),
      ],
    };
  }

  if (normalized.includes('project') && normalized.includes('not found')) {
    return {
      title: input.t('aiTimesheet.previewErrors.project.title'),
      message: rawMessage || input.t('aiTimesheet.previewErrors.project.message'),
      severity: 'error',
      actions: [
        input.t('aiTimesheet.previewErrors.project.actions.checkProject'),
        input.t('aiTimesheet.previewErrors.project.actions.pickAnother'),
      ],
    };
  }

  return {
    title: input.t('aiTimesheet.previewErrors.generic.title'),
    message: rawMessage || input.t('aiTimesheet.previewErrors.generic.message'),
    severity: 'error',
    actions: [
      input.t('aiTimesheet.previewErrors.generic.actions.retry'),
      input.t('aiTimesheet.previewErrors.generic.actions.editPrompt'),
    ],
  };
};

export const mapAiTimesheetMissingFields = (fields: string[], t: TFunction): string[] => {
  const labelMap: Record<string, string> = {
    intent: t('aiTimesheet.missingFields.labels.intent'),
    date_range: t('aiTimesheet.missingFields.labels.dateRange'),
    'date_range.count': t('aiTimesheet.missingFields.labels.dateRangeCount'),
    schedule: t('aiTimesheet.missingFields.labels.schedule'),
    project: t('aiTimesheet.missingFields.labels.project'),
    task: t('aiTimesheet.missingFields.labels.task'),
    location: t('aiTimesheet.missingFields.labels.location'),
  };

  const labels = fields.map((field) => labelMap[field] ?? field);
  return Array.from(new Set(labels));
};

export const parseTimeToMinutes = (value: string | null): number | null => {
  if (!value) return null;
  const [hours, minutes] = value.split(':').map((part) => Number(part));
  if (Number.isNaN(hours) || Number.isNaN(minutes)) return null;
  return hours * 60 + minutes;
};

export const formatMinutesAsHours = (minutes: number): string => {
  const hours = minutes / 60;
  if (!Number.isFinite(hours) || hours <= 0) {
    return '0';
  }
  return hours.toFixed(2).replace(/\.00$/, '');
};

export const getAiTimesheetPlanMetrics = (plan: AiTimesheetPlan): { workMinutes: number; breakMinutes: number } => {
  let workMinutes = 0;
  let breakMinutes = 0;

  plan.days.forEach((day) => {
    day.work_blocks.forEach((block) => {
      const startMinutes = parseTimeToMinutes(block.start_time);
      const endMinutes = parseTimeToMinutes(block.end_time);
      if (startMinutes !== null && endMinutes !== null && endMinutes > startMinutes) {
        workMinutes += endMinutes - startMinutes;
      }
    });

    day.breaks.forEach((pause) => {
      const startMinutes = parseTimeToMinutes(pause.start_time);
      const endMinutes = parseTimeToMinutes(pause.end_time);
      if (startMinutes !== null && endMinutes !== null && endMinutes > startMinutes) {
        breakMinutes += endMinutes - startMinutes;
      }
    });
  });

  return { workMinutes, breakMinutes };
};
