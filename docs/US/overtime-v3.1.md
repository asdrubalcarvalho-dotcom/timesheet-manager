# US Overtime v3.1 (Tenant-driven)

## Source of truth (tenant settings)
Overtime policy is resolved from `tenants.settings`:

- `settings.region`: "US" | "EU"
- `settings.state`: "CA" | "NY" | null

Supported formats:
- Preferred: `settings.region="US"` + `settings.state="CA"|"NY"`
- Backward compatible: `settings.region="US-CA"` or `"US-NY"`

If region is missing or not `"US"`, US overtime rules do not apply.

State must come from existing backend domain data (tenant config / work location).  
Do not infer from browser, IP, or user input.

---

## Rules

### California (CA)

**Daily overtime (per day):**
- 0–8h: regular
- 8–12h: overtime @ 1.5x (max 4h)
- 12h+: double time @ 2.0x

**7th consecutive working day (within the tenant workweek):**
- Working day = a day with > 0 worked hours
- If 7 consecutive working days occur in the same workweek:
  - On the 7th day:
    - First 8h: overtime @ 1.5x
    - Beyond 8h: double time @ 2.0x

**Weekly overtime:**
- Over 40h/week: overtime @ 1.5x

**Combination rule (no double counting):**
1) Compute daily buckets first (regular / OT 1.5x / DT 2.0x), including 7th day rule.
2) Then compute weekly overtime: if total hours > 40, convert ONLY remaining "regular" hours into weekly overtime @ 1.5x until the excess is covered.

---

### New York (NY)
Weekly overtime only:
- Over 40h/week: overtime @ 1.5x
- No daily overtime, no double time, no 7th day rule

---

### Federal fallback (FLSA)
For other/unknown US states:
- Weekly overtime only
- Over 40h/week: overtime @ 1.5x

---

## Guarantees
- Deterministic, tenant-driven and state-aware
- Test-backed (CA daily + 7th day, NY weekly-only, FLSA fallback)
- No UI/locale/browser dependencies
