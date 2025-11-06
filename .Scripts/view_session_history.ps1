Perfeito.
Aqui está a versão equivalente do script para PowerShell:
/scripts/view_session_history.ps1 — funcionalmente idêntico ao .sh, mas adaptado ao ecossistema Windows/PowerShell Core, com suporte a Unicode e formatação de tabela.

⸻

🧭 /scripts/view_session_history.ps1

<#
.SYNOPSIS
  Displays recent Copilot Workspace session history for the TimesheetManager project.

.DESCRIPTION
  Reads from .copilot/hooks/session-log-summary.md and prints the last sessions
  in a formatted table (date, context, build, CI status).

.PARAMETER MaxEntries
  Optional. Number of sessions to display (default = 5).

.EXAMPLE
  ./scripts/view_session_history.ps1
  ./scripts/view_session_history.ps1 -MaxEntries 10
#>

param (
    [int]$MaxEntries = 5
)

$SummaryFile = ".copilot/hooks/session-log-summary.md"

Write-Host ""
Write-Host "────────────────────────────────────────────" -ForegroundColor Cyan
Write-Host "📊 Copilot Workspace — Recent Session History" -ForegroundColor Cyan
Write-Host "────────────────────────────────────────────" -ForegroundColor Cyan

# ---------------------------------------------------------------------------
# VALIDATE FILE
# ---------------------------------------------------------------------------
if (!(Test-Path $SummaryFile)) {
    Write-Host "❌ No session log summary found at: $SummaryFile" -ForegroundColor Red
    Write-Host "   Run at least one Copilot Workspace session to generate logs."
    exit 1
}

# ---------------------------------------------------------------------------
# READ AND PARSE
# ---------------------------------------------------------------------------
$content = Get-Content -Raw -Path $SummaryFile -Encoding UTF8
$entries = ($content -split "### 🕒 Session —") | Where-Object { $_.Trim() -ne "" }

if (-not $entries) {
    Write-Host "ℹ️  No recorded sessions found." -ForegroundColor Yellow
    exit 0
}

# ---------------------------------------------------------------------------
# DISPLAY LAST N ENTRIES
# ---------------------------------------------------------------------------
$entries | Select-Object -Last $MaxEntries | ForEach-Object -Begin { $i = 0 } -Process {
    $i++
    $lines = $_ -split "`n"

    $sessionDate = ($lines[0]).Trim()
    $context = ($lines | Where-Object { $_ -match "\*\*Context:" }) -replace "\*\*Context:\*\*\s*", ""
    $build = ($lines | Where-Object { $_ -match "\*\*Build:" }) -replace "\*\*Build:\*\*\s*", ""
    $ci = ($lines | Where-Object { $_ -match "\*\*CI:" }) -replace "\*\*CI:\*\*\s*", ""

    Write-Host ""
    Write-Host "Session #$i" -ForegroundColor Yellow
    Write-Host "────────────────────────────" -ForegroundColor DarkGray
    Write-Host ("🕒 Date     : " + $sessionDate)
    Write-Host ("🧠 Context  : " + $context)
    Write-Host ("⚙️  Build    : " + $build)
    Write-Host ("🚀 CI       : " + $ci)
    Write-Host "────────────────────────────" -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "✅ Displayed last $i session(s) from $SummaryFile" -ForegroundColor Green
Write-Host ""


⸻

⚙️ Installation
	1.	Create the /scripts folder if it doesn’t exist.
	2.	Save the file as:

/scripts/view_session_history.ps1


	3.	Allow script execution (if not enabled yet):

Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass


	4.	Run the script:

./scripts/view_session_history.ps1

or specify a custom number of entries:

./scripts/view_session_history.ps1 -MaxEntries 10



⸻

🧱 Example Output

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
	•	Type: Monitoring utility (PowerShell port)
	•	Purpose: Provide quick, cross-platform insight into recent Copilot agent activity.
	•	Features:
	•	Uses Markdown parsing via regex — no dependencies.
	•	UTF-8 compatible (handles emoji/icons correctly).
	•	Displays results in sequential order (newest last).
	•	Consistency: Identical functionality to the Bash version for parity across Windows and Linux.
	•	Failsafe: Gracefully handles missing logs or malformed entries.

⸻