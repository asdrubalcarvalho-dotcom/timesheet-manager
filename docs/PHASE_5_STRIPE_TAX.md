

# PHASE 5 – STRIPE TAX INTEGRATION (AUTOMATED VAT / SALES TAX)

This document defines the full context, requirements, and implementation details for enabling Stripe Tax inside the Timesheet SaaS billing platform.  
It is intended for Copilot and future developers to understand exactly what to implement and how.

---

## 🎯 1. OBJECTIVE

Enable automated tax calculation (VAT, GST, Sales Tax) during checkout using **Stripe Tax**, for both Test and Live environments.

Goals:

- Apply correct VAT automatically based on the customer's billing address
- Support Portugal VAT (IVA 23%)
- Support global tax rules (EU OSS, digital services)
- Display VAT clearly in UI (Order Summary, Checkout Modal)
- Store tenant billing address for compliance
- Prepare for future ERP/Invoice legal compliance (Portuguese AT integration)

---

## 🧩 2. HIGH‑LEVEL ARCHITECTURE

### Frontend
- New **Billing Address Form**
- Fields:  
  - Country  
  - Postal Code  
  - City  
  - Address Line 1  
  - Address Line 2 (optional)
- Stored locally and in backend
- Required before upgrading or adding add-ons

### Backend
- New Model/Table: `tenant_billing_addresses`
- New API endpoints:
  - `POST /api/billing/address`
  - `GET /api/billing/address`
- Update Stripe PaymentIntent creation to include:
  ```php
  'automatic_tax' => ['enabled' => true],
  'customer_update' => ['address' => 'auto'],
  'shipping' => [...optional...],
  ```

### Stripe
- Activate Stripe Tax (manual step)
- Activate location: **Portugal**
- Business type: **Digital Services**
- Prices remain **tax-exclusive**
- Stripe calculates VAT based on address & rules

---

## 📌 3. BUSINESS RULES

### 3.1. Tenant must have billing address before checkout
If billing address is missing:
- User cannot checkout
- Show notification:  
  **“Please complete your billing address before proceeding with payment.”**

### 3.2. VAT is always added on top of base price
Stripe will calculate:
- VAT rate (23% for PT)
- Handling of EU VAT reverse charge
- Country‑specific exemptions

### 3.3. Add‑ons pricing
- Base formula unchanged
- VAT applied on full total

### 3.4. No ERP integration (for now)
- Stripe receipt is sent automatically
- Faturas AT will be generated manually for now

---

## 📡 4. API CHANGES

### 4.1. New endpoint: Save billing address
`POST /api/billing/address`

Request:
```json
{
  "country": "PT",
  "postal_code": "1100-150",
  "city": "Lisboa",
  "line1": "Rua do Exemplo, 123",
  "line2": "2º Esq"
}
```

Validations:
- Country required
- Postal code required
- City required
- Address line1 required

### 4.2. Fetch billing address
`GET /api/billing/address`

### 4.3. Update checkout/start
Add:

```php
'automatic_tax' => [
    'enabled' => true,
],
```

And:

```php
'customer' => $stripeCustomerId,
'customer_update' => ['address' => 'auto'],
```

---

## 🖥️ 5. FRONTEND IMPLEMENTATION

### 5.1. New billing address component
File: `frontend/src/components/Billing/BillingAddressForm.tsx`

Features:
- Display existing address
- Allow editing
- Force address completion before checkout
- Validate required fields
- Save to backend

### 5.2. Changes in CheckoutModal
Show in Order Summary:

Example:

```
Order Summary
Base plan × 2 users: €88.00
AI Add-on: €15.84

VAT (23%): €23.98

Total due now: €127.82
```

### 5.3. BillingPage changes
Add section:
- “Billing Address (Required for tax calculation)”
- Button: “Edit Billing Address”

---

## 📝 6. STRIPE CONFIG REQUIREMENTS

### Manual steps:

1. Go to  
   **https://dashboard.stripe.com/settings/tax**
2. Enable Stripe Tax
3. Set Business Location: **Portugal**
4. Confirm business type: *Digital Services Provider*
5. Set default tax behavior: **Exclusive (VAT added on top)**

---

## 🧪 7. TEST PLAN

### 7.1. Unit tests
- Missing address → checkout blocked
- Stripe PaymentIntent includes `automatic_tax`
- Address saved correctly per tenant

### 7.2. Frontend tests
- Cannot checkout until billing address present
- VAT is shown on preview
- VAT updates when country changes

### 7.3. Stripe test scenarios
Country | Expected
--------|---------
PT | VAT 23%
FR | VAT country-specific
US | No VAT
DE (with VAT number provided) | Reverse charge (0%)

---

## 🗂️ 8. REQUIRED FILES FOR COPILOT TO MODIFY

### Backend
- `app/Modules/Billing/Controllers/BillingController.php`
- `app/Services/Payments/StripeGateway.php`
- `app/Models/TenantBillingAddress.php`
- Migration: `create_tenant_billing_addresses_table.php`
- `routes/api.php`

### Frontend
- `BillingPage.tsx`
- `BillingContext.tsx`
- New: `BillingAddressForm.tsx`
- `CheckoutModal.tsx`

---

## 📘 9. DELIVERABLES FROM COPILOT

- Full implementation backend + frontend
- Migration generated
- New React component
- Updated UI with VAT summary
- Updated billing logic
- Tests passing
- Documentation updates in STRIPE_INTEGRATION.md

---

## ✅ 10. COMPLETION CRITERIA

This phase is complete when:

- VAT is shown and calculated by Stripe
- Checkout blocked unless billing address exists
- Stripe receipts include VAT
- Tenant addresses stored and editable
- No breaking changes for existing billing flows

---

End of **Phase 5 – Stripe Tax Integration Context**.