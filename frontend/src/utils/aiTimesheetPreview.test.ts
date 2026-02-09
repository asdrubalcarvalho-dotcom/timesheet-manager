import { describe, expect, it } from 'vitest';
import { mapAiTimesheetPreviewError } from './aiTimesheetPreview';

const t = (key: string) => key;

describe('mapAiTimesheetPreviewError', () => {
  it('maps overlap errors to guidance', () => {
    const result = mapAiTimesheetPreviewError({
      message: 'Overlapping time ranges detected on 2026-02-02.',
      t,
    });

    expect(result.title).toBe('aiTimesheet.previewErrors.overlap.title');
    expect(result.severity).toBe('error');
    expect(result.message).toBe('Overlapping time ranges detected on 2026-02-02.');
    expect(result.actions).toEqual([
      'aiTimesheet.previewErrors.overlap.actions.addTimes',
      'aiTimesheet.previewErrors.overlap.actions.adjustTimes',
    ]);
  });

  it('maps break requirement messages as warnings', () => {
    const result = mapAiTimesheetPreviewError({
      message: 'Break required for continuous work over 6.0 hours on 2026-02-02.',
      t,
    });

    expect(result.title).toBe('aiTimesheet.previewErrors.break.title');
    expect(result.severity).toBe('warning');
    expect(result.message).toBe('Break required for continuous work over 6.0 hours on 2026-02-02.');
    expect(result.actions).toEqual([
      'aiTimesheet.previewErrors.break.actions.addBreak',
      'aiTimesheet.previewErrors.break.actions.reviewPolicy',
    ]);
  });

  it('maps existing entries without time overlap error', () => {
    const result = mapAiTimesheetPreviewError({
      message: 'Cannot validate overlaps on 2026-02-02 due to existing entries without time.',
      t,
    });

    expect(result.title).toBe('aiTimesheet.previewErrors.existingNoTime.title');
    expect(result.message).toBe('aiTimesheet.previewErrors.existingNoTime.message');
    expect(result.severity).toBe('error');
    expect(result.actions).toEqual([
      'aiTimesheet.previewErrors.existingNoTime.labels.howToFix',
      'aiTimesheet.previewErrors.existingNoTime.labels.reviewExistingEntries',
      'aiTimesheet.previewErrors.existingNoTime.labels.replaceEntries',
      'aiTimesheet.previewErrors.existingNoTime.actions.chooseDates',
    ]);
  });

  it('maps project not found errors', () => {
    const result = mapAiTimesheetPreviewError({
      message: 'Project not found for "Alpha".',
      t,
    });

    expect(result.title).toBe('aiTimesheet.previewErrors.project.title');
    expect(result.severity).toBe('error');
    expect(result.actions).toEqual([
      'aiTimesheet.previewErrors.project.actions.checkProject',
      'aiTimesheet.previewErrors.project.actions.pickAnother',
    ]);
  });

  it('falls back to generic mapping', () => {
    const result = mapAiTimesheetPreviewError({ message: '', t });

    expect(result.title).toBe('aiTimesheet.previewErrors.generic.title');
    expect(result.message).toBe('aiTimesheet.previewErrors.generic.message');
    expect(result.severity).toBe('error');
    expect(result.actions).toEqual([
      'aiTimesheet.previewErrors.generic.actions.retry',
      'aiTimesheet.previewErrors.generic.actions.editPrompt',
    ]);
  });
});
