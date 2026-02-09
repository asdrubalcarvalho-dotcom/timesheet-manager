import { describe, expect, it } from 'vitest';
import { looksLikeTimesheetBuilder } from './looksLikeTimesheetBuilder';

describe('looksLikeTimesheetBuilder', () => {
  it('detects multiline labeled prompt (PT)', () => {
    const prompt = [
      'Criar timesheets para a proxima semana (Seg-Sex)',
      'Projeto: "Mobile Banking App"',
      'Tarefa: "iOS App Development"',
      'Descricao: "Sprint 12"',
      'Bloco 1: 09:00-13:00',
      'Bloco 2: 14:00-18:00',
    ].join('\n');

    expect(looksLikeTimesheetBuilder(prompt)).toBe(true);
  });

  it('detects DATE_RANGE token', () => {
    expect(looksLikeTimesheetBuilder('DATE_RANGE=2026-02-10..2026-02-14')).toBe(true);
  });

  it('detects time range schedule', () => {
    expect(looksLikeTimesheetBuilder('09:00-13:00 e 14:00-18:00 projeto ACME')).toBe(true);
  });

  it('does not match normal insight questions', () => {
    expect(looksLikeTimesheetBuilder('Which approvals are pending?')).toBe(false);
  });
});
