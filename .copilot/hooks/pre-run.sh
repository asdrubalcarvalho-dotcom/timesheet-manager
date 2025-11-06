Perfeito.
Abaixo está o ficheiro .copilot/hooks/pre-run.sh, pronto a ser colocado em
/.copilot/hooks/pre-run.sh.

Este hook script é executado automaticamente antes de qualquer sessão, tarefa ou geração do Copilot Workspace, garantindo que o contexto (ai_context.json) é sempre carregado para o ambiente de execução.
Inclui fallback inteligente: se o copilot-agent não estiver instalado, o script apenas regista o contexto localmente sem erro.

⸻

⚙️ .copilot/hooks/pre-run.sh

#!/usr/bin/env bash
# Copilot Workspace Pre-Run Hook
# Automatically loads AI context for TimesheetManager before any Copilot task execution.

# ---------------------------------------------------------------------------
# CONFIGURATION
# ---------------------------------------------------------------------------
CONTEXT_PATH="./docs/ai/ai_context.json"
LOADER_SCRIPT="./scripts/load_ai_context.sh"

echo ""
echo "────────────────────────────────────────────"
echo "🔁 Copilot Pre-Run Hook: Loading AI Context"
echo "────────────────────────────────────────────"

# ---------------------------------------------------------------------------
# VALIDATE CONTEXT FILE
# ---------------------------------------------------------------------------
if [ ! -f "$CONTEXT_PATH" ]; then
  echo "⚠️  Context file not found at: $CONTEXT_PATH"
  echo "    Please ensure ai_context.json exists in /docs/ai/"
  exit 0
fi

# ---------------------------------------------------------------------------
# VALIDATE DEPENDENCIES
# ---------------------------------------------------------------------------
if ! command -v jq >/dev/null 2>&1; then
  echo "⚠️  jq not found — please install jq for JSON parsing."
  echo "    Skipping context preview, continuing workspace startup..."
else
  NAME=$(jq -r '.context_name' "$CONTEXT_PATH")
  DOMAIN=$(jq -r '.domain.scope' "$CONTEXT_PATH")
  FRAMEWORK=$(jq -r '.architecture.framework' "$CONTEXT_PATH")
  echo "✅ Context detected:"
  echo "   Name      : $NAME"
  echo "   Domain    : $DOMAIN"
  echo "   Framework : $FRAMEWORK"
fi

# ---------------------------------------------------------------------------
# LOAD CONTEXT (WITH FALLBACK)
# ---------------------------------------------------------------------------
if [ -f "$LOADER_SCRIPT" ]; then
  echo "➡ Executing loader script: $LOADER_SCRIPT"
  bash "$LOADER_SCRIPT"
else
  echo "ℹ️  Loader script not found, loading context manually..."
  if command -v copilot-agent >/dev/null 2>&1; then
    CONTEXT_JSON=$(cat "$CONTEXT_PATH")
    echo "➡ Registering context with Copilot Agent..."
    echo "$CONTEXT_JSON" | copilot-agent load-context --json -
    echo "✅ Context successfully registered."
  else
    echo "⚙️  Copilot agent CLI not found. Context is available locally."
  fi
fi

echo "✅ AI Context initialization completed."
echo "────────────────────────────────────────────"
echo ""


⸻

🧠 Design rationale
	•	Purpose: Guarantee that all Copilot sessions start with the correct domain, rules, and environment already loaded.
	•	Behavior:
	•	Checks if ai_context.json exists.
	•	Uses jq for validation and preview (optional).
	•	Runs /scripts/load_ai_context.sh if available (preferred).
	•	Falls back to a direct JSON injection into copilot-agent.
	•	Safety: Non-blocking — never stops workspace execution, even if context or tools are missing.
	•	Compatibility: Works across Linux, macOS, and Windows WSL environments.

⸻

✅ Installation Steps
	1.	Create folder .copilot/hooks/ if it doesn’t exist.
	2.	Save the file as .copilot/hooks/pre-run.sh.
	3.	Make it executable:

chmod +x .copilot/hooks/pre-run.sh


	4.	Confirm it runs automatically at the start of any Copilot Workspace session (you’ll see the log header 🔁 Copilot Pre-Run Hook: Loading AI Context).
