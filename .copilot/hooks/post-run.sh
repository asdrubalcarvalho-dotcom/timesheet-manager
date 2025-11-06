#!/usr/bin/env bash
# Copilot Workspace Post-Run Hook — Laravel + React
# Logs session results, validates backend/frontend, optionally triggers CI, and appends a Markdown entry.

LOG_DIR="./logs"
LOG_FILE="$LOG_DIR/session_$(date +'%Y%m%d_%H%M%S').log"
CONTEXT_FILE="./docs/ai/ai_context.json"
CI_WORKFLOW=".github/workflows/build.yml"
SUMMARY_FILE=".copilot/hooks/session-log-summary.md"

echo ""
echo "────────────────────────────────────────────"
echo "📄 Copilot Post-Run Hook: Session Summary"
echo "────────────────────────────────────────────"

mkdir -p "$LOG_DIR"

# Context summary
if [ -f "$CONTEXT_FILE" ]; then
  if command -v jq >/dev/null 2>&1; then
    NAME=$(jq -r '.context_name' "$CONTEXT_FILE")
    DOMAIN=$(jq -r '.domain.scope' "$CONTEXT_FILE")
    FRAMEWORK=$(jq -r '.architecture.framework // "Laravel + React"' "$CONTEXT_FILE")
    echo "🧠 Context used: $NAME ($DOMAIN | $FRAMEWORK)"
    echo "Context: $NAME ($DOMAIN | $FRAMEWORK)" >> "$LOG_FILE"
  else
    echo "🧠 Context file detected (install jq to parse details)."
    echo "Context: Detected (no jq)" >> "$LOG_FILE"
  fi
else
  echo "⚠️  Context file not found."
  echo "Context: Missing" >> "$LOG_FILE"
fi

# Backend validation (Laravel)
BUILD_STATUS="Skipped"
if [ -d "./backend" ]; then
  echo "🔍 Checking Laravel backend..."
  pushd backend >/dev/null 2>&1
  php -v >/dev/null 2>&1 && composer --version >/dev/null 2>&1
  if [ $? -eq 0 ]; then
    php artisan --version >/dev/null 2>&1
    if [ $? -eq 0 ]; then
      echo "✅ Laravel environment OK"
      BUILD_STATUS="Laravel OK"
      # Minimal syntax check: run a lightweight command
      php artisan list >/dev/null 2>&1
    else
      echo "❌ Laravel check failed."
      BUILD_STATUS="Laravel Failed"
    fi
  else
    echo "⚠️  PHP/Composer not available in this runner."
    BUILD_STATUS="Env Missing"
  fi
  popd >/dev/null 2>&1
fi

# Frontend validation (React)
FRONT_STATUS="Skipped"
if [ -d "./frontend" ]; then
  echo "🔍 Building React frontend..."
  pushd frontend >/dev/null 2>&1
  if command -v npm >/dev/null 2>&1; then
    npm run build >/dev/null 2>&1
    if [ $? -eq 0 ]; then
      echo "✅ React build OK"
      FRONT_STATUS="React OK"
    else
      echo "❌ React build failed"
      FRONT_STATUS="React Failed"
    fi
  else
    echo "⚠️  npm not available."
    FRONT_STATUS="Env Missing"
  fi
  popd >/dev/null 2>&1
fi

# Persist statuses
echo "Build: $BUILD_STATUS / $FRONT_STATUS" >> "$LOG_FILE"

# Optional CI trigger
if [ -f "$CI_WORKFLOW" ]; then
  echo "⚙️  CI workflow detected. Triggering build..."
  if command -v gh >/dev/null 2>&1; then
    gh workflow run build.yml >/dev/null 2>&1
    if [ $? -eq 0 ]; then
      echo "🚀 CI workflow triggered successfully."
      echo "CI: Triggered" >> "$LOG_FILE"
    else
      echo "⚠️  CI trigger failed — check GitHub CLI authentication."
      echo "CI: Failed" >> "$LOG_FILE"
    fi
  else
    echo "ℹ️  GitHub CLI not found — skipping CI trigger."
    echo "CI: Skipped" >> "$LOG_FILE"
  fi
else
  echo "ℹ️  No GitHub Actions workflow found."
  echo "CI: None" >> "$LOG_FILE"
fi

# Timestamp and log save
echo "Timestamp: $(date)" >> "$LOG_FILE"
echo "────────────────────────────" >> "$LOG_FILE"
echo "✅ Session summary saved to $LOG_FILE"

# Append to Markdown summary
DATE_TIME=$(date +'%Y-%m-%d %H:%M:%S')
if [ -f "$SUMMARY_FILE" ]; then
  echo "🗂 Appending entry to $SUMMARY_FILE..."
  {
    echo "### 🕒 Session — $DATE_TIME"
    if [ -f "$CONTEXT_FILE" ] && command -v jq >/dev/null 2>&1; then
      NAME=$(jq -r '.context_name' "$CONTEXT_FILE")
      DOMAIN=$(jq -r '.domain.scope' "$CONTEXT_FILE")
      FRAMEWORK=$(jq -r '.architecture.framework // "Laravel + React"' "$CONTEXT_FILE")
      echo "**Context:** $NAME ($DOMAIN | $FRAMEWORK)  "
    else
      echo "**Context:** Unknown  "
    fi
    LAST_BUILD=$(grep '^Build:' "$LOG_FILE" | tail -1 | cut -d':' -f2- | xargs)
    LAST_CI=$(grep '^CI:' "$LOG_FILE" | tail -1 | cut -d':' -f2- | xargs)
    echo "**Build:** ${LAST_BUILD:-N/A}  "
    echo "**CI:** ${LAST_CI:-N/A}  "
    echo "**Notes:**"
    echo "- Automatic session logged by post-run hook."
    echo "- See $LOG_FILE for details."
    echo ""
    echo "---"
    echo ""
  } >> "$SUMMARY_FILE"
  echo "✅ Markdown summary updated."
else
  echo "⚠️  $SUMMARY_FILE not found — skipping summary append."
fi

echo ""
echo "────────────────────────────────────────────"
echo "✅ Copilot Post-Run Hook completed"
echo "────────────────────────────────────────────"
echo ""