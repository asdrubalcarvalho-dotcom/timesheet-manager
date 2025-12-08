<?php

# COPILOT GLOBAL RULES — DO NOT IGNORE
# ------------------------------------
# You MUST follow all rules strictly. Violations are NOT allowed.

# RULE 1 — DO NOT INVENT ANYTHING
# - No invented models.
# - No invented tables.
# - No invented fields.
# - No invented migrations.
# - No invented guards.
# - Only use existing models + existing auth + existing roles.

# RULE 2 — NO DATABASE SCHEMA CHANGES
# - Never create migrations.
# - Never modify existing schema.
# - Telemetry is READ-ONLY.

# RULE 3 — INTERNAL TELEMETRY API ALREADY EXISTS
# Internal endpoints (Admin\TelemetryController) exist at:
#   /api/admin/telemetry/info
#   /api/admin/telemetry/tenants
#   /api/admin/telemetry/billing
#   /api/admin/telemetry/usage
#   /api/admin/telemetry/errors
# Do NOT change their behavior or contracts.

# RULE 4 — SUPERADMIN API MUST PROXY INTERNAL ENDPOINTS
# SuperAdmin telemetry APIs MUST:
# - Call internal telemetry endpoints using Http::withHeaders(...)
# - Use X-Internal-Api-Key from config('telemetry.internal_key')
# - Use base URL from config('telemetry.internal_base_url')
# - NOT query the database directly.

# RULE 5 — FRONTEND MAY NOT USE INTERNAL API KEY
# Frontend must ONLY call:
#   /api/superadmin/telemetry/*
# Never call /api/admin/telemetry/* directly from React.

# RULE 6 — UNIFIED PAYLOAD FORMAT
# Every Telemetry response MUST be JSON:
# {
#   "success": true/false,
#   "data": {...} OR "message": "..."
# }

# RULE 7 — SUPERADMIN AUTH + DOMAIN RESTRICTIONS
# Access to /api/superadmin/telemetry/* MUST require:
# - sanctum auth
# - user email = config('telemetry.superadmin_email')
#   OR user has role "SuperAdmin"
# - request host must be one of config('telemetry.allowed_superadmin_domains')

# RULE 8 — NO TENANT CONTEXT
# NEVER call tenancy()->tenant inside any Telemetry controller or middleware.

# RULE 9 — PLACEHOLDERS ARE ALLOWED
# If a metric requires schema change, or a model that does not exist:
#   → return 0, null, or an empty array.
# Do NOT invent queries or schema.

# RULE 10 — If something is unclear or missing:
#   → STOP and ask for clarification instead of guessing.

return [
    'internal_key' => env('INTERNAL_TELEMETRY_KEY'),
    'internal_base_url' => env('INTERNAL_TELEMETRY_URL', 'http://127.0.0.1/api/admin/telemetry'),
    'superadmin_email' => env('SUPERADMIN_EMAIL', 'superadmin@upg2ai.com'),
    'allowed_superadmin_domains' => [
        'management.localhost',
        'management.vendaslive.com',
        'upg2ai.vendaslive.com',
    ],
];
