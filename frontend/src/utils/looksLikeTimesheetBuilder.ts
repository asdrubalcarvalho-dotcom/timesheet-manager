type PromptCheck = (prompt: string) => boolean;

const normalizePrompt = (prompt: string): string => {
  const normalized = prompt.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  return normalized.toLowerCase();
};

export const looksLikeTimesheetBuilder: PromptCheck = (prompt) => {
  const trimmed = prompt.trim();
  if (!trimmed) return false;

  const normalized = normalizePrompt(trimmed);

  if (normalized.includes('date_range=')) {
    return true;
  }

  if (/(criar\s+timesheets|create\s+timesheets)/i.test(normalized)) {
    return true;
  }

  if (/(^|\n)\s*(projeto|project|tarefa|task|descricao|description|notes?|observacoes?|observacao)\s*[:=]/i.test(normalized)) {
    return true;
  }

  if (/(\bbloco\b|\bblock\b)/i.test(normalized)) {
    return true;
  }

  if (/\b\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}\b/.test(normalized)) {
    return true;
  }

  return false;
};
