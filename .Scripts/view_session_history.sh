Perfeito.
Abaixo tens o ficheiro /scripts/view_session_history.sh, criado para permitir-te visualizar rapidamente o histórico das sessões do Copilot Workspace diretamente no terminal.
O script lê o ficheiro .copilot/hooks/session-log-summary.md, extrai automaticamente as últimas entradas e apresenta uma tabela formatada com os campos principais: data, contexto, build, CI e observações.

⸻

🧭 /scripts/view_session_history.sh

#!/usr/bin/env bash
# View summarized Copilot Workspace session history in terminal.
# Reads from .copilot/hooks/session-log-summary.md and displays formatted output.

SUMMARY_FILE=".copilot/hooks/session-log-summary.md"
MAX_ENTRIES=5

echo ""
echo "────────────────────────────────────────────"
echo "📊 Copilot Workspace — Recent Session History"
echo "────────────────────────────────────────────"

# ---------------------------------------------------------------------------
# VALIDATE FILE
# ---------------------------------------------------------------------------
if [ ! -f "$SUMMARY_FILE" ]; then
  echo "❌ No session log summary found at: $SUMMARY_FILE"
  echo "   Run at least one Copilot Workspace session to generate logs."
  exit 1
fi

# ---------------------------------------------------------------------------
# EXTRACT ENTRIES
# ---------------------------------------------------------------------------
# Extract all sessions into temporary structure
ENTRIES=$(grep -E '^### 🕒 Session' "$SUMMARY_FILE" | tail -n $MAX_ENTRIES)

if [ -z "$ENTRIES" ]; then
  echo "ℹ️  No recorded sessions yet."
  exit 0
fi

# ---------------------------------------------------------------------------
# PARSE AND DISPLAY
# ---------------------------------------------------------------------------
COUNTER=0
while IFS= read -r line; do
  SESSION_DATE=$(echo "$line" | sed 's/^### 🕒 Session — //')
  CONTEXT=$(grep -A4 "$line" "$SUMMARY_FILE" | grep -m1 '\*\*Context:' | sed 's/\*\*Context:\*\* //')
  BUILD=$(grep -A4 "$line" "$SUMMARY_FILE" | grep -m1 '\*\*Build:' | sed 's/\*\*Build:\*\* //')
  CI=$(grep -A4 "$line" "$SUMMARY_FILE" | grep -m1 '\*\*CI:' | sed 's/\*\*CI:\*\* //')

  COUNTER=$((COUNTER + 1))
  echo ""
  echo "Session #$COUNTER"
  echo "────────────────────────────"
  echo "🕒 Date     : $SESSION_DATE"
  echo "🧠 Context  : $CONTEXT"
  echo "⚙️  Build    : $BUILD"
  echo "🚀 CI       : $CI"
  echo "────────────────────────────"
done <<< "$ENTRIES"

echo ""
echo "✅ Displayed last $COUNTER session(s) from $SUMMARY_FILE"
echo ""


⸻

⚙️ Installation
	1.	Create the /scripts folder if not yet present.
	2.	Save the script as:

/scripts/view_session_history.sh


	3.	Make it executable:

chmod +x /scripts/view_session_history.sh


	4.	Run it anytime to view the last sessions:

./scripts/view_session_history.sh



⸻

🧱 Output Example

📊 Copilot Workspace — Recent Session History
────────────────────────────────────────────

Session #1
────────────────────────────
🕒 Date     : 2025-11-03 18:42:10
🧠 Context  : TimesheetManager_AI_Context (Timesheet and Expense Management | .NET 8 Blazor Mixed)
⚙️  Build    : Success
🚀 CI       : Triggered
────────────────────────────

✅ Displayed last 1 session(s) from .copilot/hooks/session-log-summary.md


⸻

🧠 Design rationale
	•	Type: Observational utility + Self-checking
	•	Purpose: Quick local visibility into recent Copilot agent activity.
	•	Data source: .copilot/hooks/session-log-summary.md
	•	Features:
	•	Shows last 5 sessions by default (modifiable via MAX_ENTRIES).
	•	Cross-platform (Bash ≥ 4).
	•	Non-destructive — read-only operation.
	•	Behavior: If no log exists, the script informs the user and exits gracefully.
