# US Product Guidelines – Timeperk

## Purpose
This document defines how Timeperk should behave and feel for US customers.
The goal is to make the product feel native to the US market, not adapted.

---

## Market Positioning
Timeperk is a US-ready SaaS product for workforce scheduling, time tracking, and operations.
Users should never feel that the product originated outside the US.

---

## Locale & Regional Defaults

### United States (en-US)
- Language: en-US
- Time format: 12-hour (AM/PM)
- Date format: MM/DD/YYYY
- Week starts on: Sunday
- Currency: USD
- Terminology:
  - Employee (not Technician)
  - Scheduling (not Planning)
  - Log time (not Submit timesheet)
  - Workspace (not Tenant)

### Portugal (pt-PT)
- Language: pt-PT
- Time format: 24-hour
- Date format: DD/MM/YYYY
- Week starts on: Monday
- Currency: EUR

---

## UX & Copy Principles (US)

- Use direct, action-oriented language
- Avoid formal or bureaucratic phrasing
- Always show the next action clearly
- Prefer short sentences
- Prefer verbs over nouns

### Examples
❌ "Operation completed successfully"
✅ "You're all set"

❌ "Submit"
✅ "Save", "Continue", "Log time"

---

## Product Behavior Rules

- Defaults must match the user's region automatically
- Users should not need to configure basic regional settings
- Configuration is allowed but not required
- Never block the user unnecessarily; allow fixing later

---

## Technical Constraints

- Business logic must remain unchanged
- Security rules must not be weakened
- Multi-tenancy must not be affected
- Changes must be reversible and isolated

---

## AI Assistant Instructions (Copilot / GPT)

When suggesting code or UI changes:
- Prefer US defaults when locale is en-US
- Never introduce European-specific terminology for US users
- Do not add complexity to support US behavior
- If unsure, choose the simplest, most explicit solution