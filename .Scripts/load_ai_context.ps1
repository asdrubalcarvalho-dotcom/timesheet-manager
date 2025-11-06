<#
.SYNOPSIS
  Loads the AI development context for TimesheetManager into any compatible Copilot or LLM agent.

.DESCRIPTION
  This script reads the ai_context.json file, validates it, and registers it in the agent session.
  Intended for use before running code generation or modification tasks.

.PARAMETER ContextPath
  Optional. Path to the context JSON file. Defaults to ./docs/ai/ai_context.json

.EXAMPLE
  ./scripts/load_ai_context.ps1
  ./scripts/load_ai_context.ps1 -ContextPath "./ai_context.json"
#>

param (
    [string]$ContextPath = "./docs/ai/ai_context.json"
)

Write-Host "=== Loading AI Context for TimesheetManager ===" -ForegroundColor Cyan

if (!(Test-Path $ContextPath)) {
    Write-Host "❌ Context file not found at: $ContextPath" -ForegroundColor Red
    exit 1
}

try {
    $context = Get-Content -Path $ContextPath -Raw | ConvertFrom-Json
    Write-Host "✅ Context loaded successfully:"
    Write-Host "   Context Name : $($context.context_name)"
    Write-Host "   Domain        : $($context.domain.scope)"
    Write-Host "   Framework     : $($context.architecture.framework)"
    Write-Host "   Roles         : $($context.architecture.authentication.roles -join ', ')"
}
catch {
    Write-Host "❌ Failed to parse context JSON file." -ForegroundColor Red
    exit 1
}

# Optional integration with Copilot or external agent
if (Get-Command copilot-agent -ErrorAction SilentlyContinue) {
    Write-Host "➡ Sending context to Copilot agent..."
    $jsonContent = Get-Content -Path $ContextPath -Raw
    $null = & copilot-agent load-context --json "$jsonContent"
    Write-Host "✅ Context registered with Copilot agent."
} else {
    Write-Host "ℹ️ Copilot agent not detected. Context available locally for custom pipelines."
}

Write-Host "=== AI Context initialization complete ===" -ForegroundColor Green