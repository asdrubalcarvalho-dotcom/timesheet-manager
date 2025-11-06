#!/usr/bin/env bash
# Loads the AI development context for TimesheetManager into Copilot or any compatible AI agent.

CONTEXT_PATH="./docs/ai/ai_context.json"

echo "=== Loading AI Context for TimesheetManager ==="

if [ ! -f "$CONTEXT_PATH" ]; then
  echo "❌ Context file not found at $CONTEXT_PATH"
  exit 1
fi

# Validate and preview key fields
NAME=$(jq -r '.context_name' "$CONTEXT_PATH")
DOMAIN=$(jq -r '.domain.scope' "$CONTEXT_PATH")
FRAMEWORK=$(jq -r '.architecture.framework' "$CONTEXT_PATH")
ROLES=$(jq -r '.architecture.authentication.roles | join(", ")' "$CONTEXT_PATH")

echo "✅ Context Loaded:"
echo "   Name       : $NAME"
echo "   Domain     : $DOMAIN"
echo "   Framework  : $FRAMEWORK"
echo "   Roles      : $ROLES"

# Optional: send context to Copilot Agent CLI if available
if command -v copilot-agent >/dev/null 2>&1; then
  echo "➡ Registering context with Copilot Agent..."
  CONTEXT_JSON=$(cat "$CONTEXT_PATH")
  echo "$CONTEXT_JSON" | copilot-agent load-context --json -
  echo "✅ Context registered with Copilot Agent."
else
  echo "ℹ️ Copilot agent CLI not found — context loaded locally."
fi

echo "=== AI Context initialization complete ==="