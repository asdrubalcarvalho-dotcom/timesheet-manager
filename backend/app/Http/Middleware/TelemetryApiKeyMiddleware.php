# COPILOT INSTRUCTIONS — DO NOT IGNORE
# ------------------------------------
# You must follow these rules strictly. Violations are NOT allowed.

# RULE 1 — DO NOT INVENT ANYTHING
# - Do NOT invent models, relations, methods, migrations, tables, fields or configs.
# - Use ONLY models and structures that ALREADY exist in the repository.
# - If you are unsure whether something exists, STOP and request clarification (do NOT guess).

# RULE 2 — DO NOT CREATE MIGRATIONS OR MODIFY SCHEMA
# - Telemetry APIs MUST NOT change the database.
# - Do NOT generate migrations.
# - Do NOT add new tables or columns.

# RULE 3 — TELEMETRY API MUST USE EXISTING MODELS
# Allowed models (verify they exist before using them):
# - Tenant model used in billing
# - Subscription model (central DB)
# - Payment model (snapshot fields)
# If any model is missing or unclear → STOP and ask for confirmation.

# RULE 4 — PROTECTED ENDPOINTS
# Telemetry endpoints MUST:
# - Use middleware `telemetry.internal`
# - Authenticate via header: `X-Internal-Api-Key`
# - Return JSON ONLY (no HTML, no views)

# RULE 5 — IMPLEMENT ONLY WHAT IS REQUESTED
# Create EXACTLY these controller methods inside TelemetryController:
#   info()       — returns app/env info only
#   tenants()    — returns list of tenants using EXISTING Tenant model
#   billing()    — returns billing summary using EXISTING Subscription model
#   usage()      — return lightweight usage (placeholder: 0 metrics allowed)
#   errors()     — placeholder endpoint (returns "not implemented")

# RULE 6 — ERROR HANDLING
# - Wrapping each method in try/catch
# - On error: return 500 + JSON { success:false, message: <error message> }

# RULE 7 — NO TENANT CONTEXT
# These are CENTRAL APIs → NEVER call tenancy()->tenant or tenant DB connections.

# RULE 8 — ROUTE RULES
# Add routes under:
#   /api/admin/telemetry/*
# With:
#   prefix: 'admin/telemetry'
#   middleware: ['telemetry.internal']

# ROUTES TO PRODUCE:
# GET /api/admin/telemetry/info
# GET /api/admin/telemetry/tenants
# GET /api/admin/telemetry/billing
# GET /api/admin/telemetry/usage
# GET /api/admin/telemetry/errors

# RULE 9 — PLACEHOLDERS ARE ALLOWED
# If any metric would require new queries, models, or migrations:
#   → return 0 or an empty array
# This avoids schema changes.

# RULE 10 — OUTPUT FORMAT
# Every response MUST follow:
# {
#   "success": true/false,
#   "data": { ... } OR "message": "..."
# }

# If you cannot complete a part without inventing something → STOP.

