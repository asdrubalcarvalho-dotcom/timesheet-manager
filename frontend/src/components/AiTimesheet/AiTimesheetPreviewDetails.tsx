import React, { useMemo } from 'react';
import { Box, Divider, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import type { AiTimesheetPlan } from '../../types';
import { formatMinutesAsHours, getAiTimesheetPlanMetrics } from '../../utils/aiTimesheetPreview';

type AiTimesheetPreviewDetailsProps = {
  plan: AiTimesheetPlan;
};

const AiTimesheetPreviewDetails: React.FC<AiTimesheetPreviewDetailsProps> = ({ plan }) => {
  const { t } = useTranslation();

  const metrics = useMemo(() => getAiTimesheetPlanMetrics(plan), [plan]);
  const days = plan.days ?? [];

  // Temporary debug: confirm preview day count.
  console.log('[AiTimesheetPreviewDetails] days', days.length);

  const startDate = plan.range?.start_date ?? null;
  const endDate = plan.range?.end_date ?? null;
  const rangeLabel = startDate
    ? endDate && endDate !== startDate
      ? `${startDate} - ${endDate}`
      : startDate
    : endDate || t('aiTimesheet.previewSummary.unknown');

  const totalWork = formatMinutesAsHours(metrics.workMinutes);
  const totalBreaks = formatMinutesAsHours(metrics.breakMinutes);

  return (
    <Stack spacing={1.5}>
      <Stack spacing={0.5}>
        <Typography variant="body2">
          {t('aiTimesheet.previewSummary.dateRange')}: {rangeLabel}
        </Typography>
        <Typography variant="body2">
          {t('aiTimesheet.previewSummary.totalWork')}: {totalWork}h
        </Typography>
        <Typography variant="body2">
          {t('aiTimesheet.previewSummary.totalBreaks')}: {totalBreaks}h
        </Typography>
      </Stack>

      <Stack spacing={1.5} sx={{ minHeight: 0 }}>
        <Box sx={{ minHeight: 0, maxHeight: { xs: 260, sm: 320 }, overflowY: 'auto', pr: 1 }}>
          {days.map((day) => (
            <Box key={day.date}>
            <Typography variant="subtitle2">{day.date}</Typography>
            <Stack spacing={0.5} sx={{ mt: 0.5 }}>
              {day.work_blocks.map((block, idx) => {
                const projectName = block.project?.name ?? t('aiTimesheet.previewSummary.unassigned');
                const taskName = block.task?.name;
                const locationName = block.location?.name;
                const notes = block.notes?.trim();
                const timeLabel = block.start_time && block.end_time
                  ? `${block.start_time}-${block.end_time}`
                  : t('aiTimesheet.previewSummary.unknown');

                return (
                  <Stack key={`${day.date}-${idx}`} spacing={0.25}>
                    <Typography variant="body2">
                      {t('aiTimesheet.previewSummary.blockTime')}: {timeLabel}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      {t('aiTimesheet.previewSummary.project')}: {projectName}
                    </Typography>
                    {taskName ? (
                      <Typography variant="body2" color="text.secondary">
                        {t('aiTimesheet.previewSummary.task')}: {taskName}
                      </Typography>
                    ) : null}
                    {locationName ? (
                      <Typography variant="body2" color="text.secondary">
                        {t('aiTimesheet.previewSummary.location')}: {locationName}
                      </Typography>
                    ) : null}
                    {notes ? (
                      <Typography variant="body2" color="text.secondary">
                        {t('aiTimesheet.previewSummary.notes')}: {notes}
                      </Typography>
                    ) : null}
                  </Stack>
                );
              })}
            </Stack>
            <Divider sx={{ mt: 1.5 }} />
            </Box>
          ))}
        </Box>
      </Stack>
    </Stack>
  );
};

export default AiTimesheetPreviewDetails;
