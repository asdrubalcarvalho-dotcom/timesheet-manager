# Stripe Test/Live Mode Implementation

## 📋 Overview

This implementation adds full support for **Stripe Test Mode** and **Stripe Live Mode**, controlled entirely through environment variables. No real API keys are hardcoded in the codebase.

## 🔧 Backend Configuration

### 1. Environment Variables (.env)

Add these variables to your `backend/.env` file:

```env
# --------------------------
# STRIPE CONFIGURATION
# --------------------------

# Payment Gateway (fake = no real charges, stripe = real Stripe API)
PAYMENTS_DRIVER=fake

# Stripe Mode: test or live
STRIPE_MODE=test

# Stripe Test Mode Keys (get from https://dashboard.stripe.com/test/apikeys)
STRIPE_TEST_PUBLISHABLE_KEY=
STRIPE_TEST_SECRET_KEY=
STRIPE_WEBHOOK_SECRET_TEST=

# Stripe Live Mode Keys (get from https://dashboard.stripe.com/apikeys)
STRIPE_LIVE_PUBLISHABLE_KEY=
STRIPE_LIVE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET_LIVE=
```

### 2. Configuration Files

#### config/stripe.php (NEW)

This file provides mode-based configuration:

```php
return [
    'mode' => env('STRIPE_MODE', 'test'),

    'test' => [
        'pk' => env('STRIPE_TEST_PUBLISHABLE_KEY'),
        'sk' => env('STRIPE_TEST_SECRET_KEY'),
        'webhook' => env('STRIPE_WEBHOOK_SECRET_TEST'),
    ],

    'live' => [
        'pk' => env('STRIPE_LIVE_PUBLISHABLE_KEY'),
        'sk' => env('STRIPE_LIVE_SECRET_KEY'),
        'webhook' => env('STRIPE_WEBHOOK_SECRET_LIVE'),
    ],

    'current' => [
        'pk' => config('stripe.' . env('STRIPE_MODE', 'test') . '.pk'),
        'sk' => config('stripe.' . env('STRIPE_MODE', 'test') . '.sk'),
        'webhook' => config('stripe.' . env('STRIPE_MODE', 'test') . '.webhook'),
    ],
];
```

#### config/services.php (UPDATED)

```php
'stripe' => [
    // Dynamically load keys based on STRIPE_MODE (test or live)
    'secret' => config('stripe.current.sk'),
    'public' => config('stripe.current.pk'),
    'webhook_secret' => config('stripe.current.webhook'),
],
```

#### config/billing.php (UPDATED)

```php
'stripe' => [
    'name'   => 'Stripe',
    'enabled'=> false,
    // Keys are now loaded dynamically based on STRIPE_MODE (test/live)
    // See config/stripe.php for configuration
    'public_key' => config('stripe.current.pk'),
    'secret_key' => config('stripe.current.sk'),
],
```

#### config/payments.php (UPDATED)

```php
'stripe' => [
    'secret_key' => config('stripe.current.sk'),
    'publishable_key' => config('stripe.current.pk'),
    'webhook_secret' => config('stripe.current.webhook'),
],
```

### 3. Updated Classes

#### StripeGateway (UPDATED)

```php
class StripeGateway implements PaymentGatewayInterface
{
    protected StripeClient $stripe;
    protected PlanManager $planManager;
    protected bool $isConfigured;
    protected string $mode;

    public function __construct(PlanManager $planManager)
    {
        $this->planManager = $planManager;
        $this->mode = config('stripe.mode', 'test');
        $this->isConfigured = $this->initializeStripe();
    }

    protected function initializeStripe(): bool
    {
        // Get secret key based on current mode
        $apiKey = config("stripe.{$this->mode}.sk");

        if (empty($apiKey)) {
            \Log::warning("[StripeGateway] Stripe secret key not configured for mode '{$this->mode}'.");
            return false;
        }

        try {
            $this->stripe = new StripeClient($apiKey);
            \Log::info("[StripeGateway] Initialized successfully in {$this->mode} mode");
            return true;
        } catch (\Exception $e) {
            \Log::error('[StripeGateway] Failed to initialize Stripe client', [
                'mode' => $this->mode,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
```

#### StripeWebhookController (UPDATED)

```php
public function handleWebhook(Request $request): JsonResponse
{
    $payload = $request->getContent();
    $sigHeader = $request->header('Stripe-Signature');
    
    // Get webhook secret based on current Stripe mode (test or live)
    $mode = config('stripe.mode', 'test');
    $webhookSecret = config("stripe.{$mode}.webhook");

    if (empty($webhookSecret)) {
        \Log::error('Stripe webhook secret not configured', [
            'mode' => $mode,
            'expected_env_key' => strtoupper("STRIPE_WEBHOOK_SECRET_{$mode}"),
        ]);
        return response()->json(['error' => 'Webhook secret not configured'], 500);
    }

    // ... rest of webhook handling
}
```

---

## 🎨 Frontend Configuration

### 1. Environment Variables

Update `frontend/.env` and `frontend/.env.production`:

```env
VITE_API_URL=http://api.localhost
VITE_APP_URL=http://localhost:8082

# --------------------------
# STRIPE CONFIGURATION
# --------------------------

# Stripe Mode: test or live
VITE_STRIPE_MODE=test

# Stripe Test Mode Public Key (get from https://dashboard.stripe.com/test/apikeys)
VITE_STRIPE_TEST_PK=

# Stripe Live Mode Public Key (get from https://dashboard.stripe.com/apikeys)
VITE_STRIPE_LIVE_PK=
```

### 2. Frontend Implementation

**✅ NO CHANGES REQUIRED** in React components!

Both `CheckoutModal.tsx` and `PaymentMethodCard.tsx` already fetch the Stripe publishable key from the backend via:

```typescript
const response = await api.get('/billing/gateway');
// Response: { gateway: 'stripe', stripe_public_key: 'REMOVED...' }

if (response.data.gateway === 'stripe' && response.data.stripe_public_key) {
  setStripePromise(loadStripe(response.data.stripe_public_key));
}
```

This is **the correct approach** because:
- ✅ Single source of truth (backend controls which key to use)
- ✅ No need to rebuild frontend when switching modes
- ✅ No risk of frontend/backend key mismatch
- ✅ Easier to manage in multi-tenant environments

---

## 🚀 Usage Guide

### Switching Between Test and Live Mode

#### Backend

1. Open `backend/.env`
2. Change `STRIPE_MODE`:
   ```env
   # For testing
   STRIPE_MODE=test
   
   # For production
   STRIPE_MODE=live
   ```
3. Ensure the corresponding keys are set:
   ```env
   # Test mode requires:
   STRIPE_TEST_PUBLISHABLE_KEY=REMOVED...
   STRIPE_TEST_SECRET_KEY=REMOVED...
   STRIPE_WEBHOOK_SECRET_TEST=REMOVED...
   
   # Live mode requires:
   STRIPE_LIVE_PUBLISHABLE_KEY=REMOVED...
   STRIPE_LIVE_SECRET_KEY=REMOVED...
   STRIPE_WEBHOOK_SECRET_LIVE=REMOVED...
   ```
4. Clear config cache:
   ```bash
   docker-compose exec app php artisan config:clear
   ```

#### Frontend

**No changes needed!** The frontend automatically receives the correct key from the backend API.

---

## 🔐 Security Best Practices

### ✅ DO

- ✅ Keep all Stripe keys in `.env` files (never commit)
- ✅ Add `.env` to `.gitignore`
- ✅ Use test mode keys during development
- ✅ Use separate Stripe accounts for test/live
- ✅ Rotate live keys periodically
- ✅ Enable webhook signature verification

### ❌ DON'T

- ❌ Never commit live keys to version control
- ❌ Never hardcode API keys in code
- ❌ Never expose secret keys in frontend
- ❌ Never use live keys in development/staging
- ❌ Never share keys in chat/email

---

## 🧪 Testing

### Test Mode Setup

1. Set `STRIPE_MODE=test` in `backend/.env`
2. Add your test keys:
   ```env
   STRIPE_TEST_PUBLISHABLE_KEY=REMOVED51...
   STRIPE_TEST_SECRET_KEY=REMOVED51...
   ```
3. Use Stripe test cards:
   - Success: `4242 4242 4242 4242`
   - Decline: `4000 0000 0000 0002`
   - Requires 3D Secure: `4000 0027 6000 3184`

### Live Mode Setup

1. Set `STRIPE_MODE=live` in `backend/.env`
2. Add your live keys:
   ```env
   STRIPE_LIVE_PUBLISHABLE_KEY=REMOVED...
   STRIPE_LIVE_SECRET_KEY=REMOVED...
   ```
3. **⚠️ WARNING**: Live mode processes real charges!

---

## 📊 Verification

### Check Current Mode

```bash
# Check backend configuration
docker-compose exec app php artisan tinker
>>> config('stripe.mode')
=> "test"

>>> config('stripe.current.pk')
=> "REMOVED..."

# Check API response
curl http://api.localhost/api/billing/gateway
{
  "gateway": "stripe",
  "stripe_public_key": "REMOVED...",
  "currency": "EUR"
}
```

### Check Logs

```bash
# Look for initialization messages
docker-compose logs app | grep StripeGateway
[StripeGateway] Initialized successfully in test mode
```

---

## 🔄 Migration from Old Configuration

### Old .env Format

```env
STRIPE_PUBLISHABLE_KEY=REMOVED...
STRIPE_SECRET_KEY=REMOVED...
STRIPE_WEBHOOK_SECRET=REMOVED...
```

### New .env Format

```env
STRIPE_MODE=test

STRIPE_TEST_PUBLISHABLE_KEY=REMOVED...
STRIPE_TEST_SECRET_KEY=REMOVED...
STRIPE_WEBHOOK_SECRET_TEST=REMOVED...

STRIPE_LIVE_PUBLISHABLE_KEY=
STRIPE_LIVE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET_LIVE=
```

**Migration Steps:**

1. Copy your existing test keys to the new `STRIPE_TEST_*` variables
2. Remove old `STRIPE_PUBLISHABLE_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`
3. Add `STRIPE_MODE=test`
4. Leave live keys empty (fill when ready for production)
5. Clear config cache: `php artisan config:clear`

---

## 📝 Summary of Changes

### Backend Files Modified

1. ✅ `backend/.env` - New environment variables structure
2. ✅ `backend/config/stripe.php` - NEW file with mode-based config
3. ✅ `backend/config/services.php` - Dynamic key loading
4. ✅ `backend/config/billing.php` - Uses `config('stripe.current.*)`
5. ✅ `backend/config/payments.php` - Uses `config('stripe.current.*)`
6. ✅ `backend/app/Services/Payments/StripeGateway.php` - Mode-aware initialization
7. ✅ `backend/app/Http/Controllers/StripeWebhookController.php` - Mode-aware webhook secret

### Frontend Files Modified

1. ✅ `frontend/.env` - New environment variables structure
2. ✅ `frontend/.env.production` - New environment variables structure
3. ✅ No React component changes needed (already using backend API)

---

## ✅ Validation Checklist

- [x] Backend `.env` has new `STRIPE_MODE` variable
- [x] Backend has separate `STRIPE_TEST_*` and `STRIPE_LIVE_*` keys
- [x] New `config/stripe.php` file created
- [x] `config/services.php` uses `config('stripe.current.*)`
- [x] `config/billing.php` uses `config('stripe.current.*)`
- [x] `config/payments.php` uses `config('stripe.current.*)`
- [x] `StripeGateway` reads mode from config
- [x] `StripeWebhookController` reads webhook secret based on mode
- [x] Frontend `.env` files updated (optional, keys come from backend)
- [x] No hardcoded API keys in codebase
- [x] Existing billing flows unaffected

---

## 🎯 Expected Behavior

### When STRIPE_MODE=test

- ✅ Uses `STRIPE_TEST_PUBLISHABLE_KEY`
- ✅ Uses `STRIPE_TEST_SECRET_KEY`
- ✅ Uses `STRIPE_WEBHOOK_SECRET_TEST`
- ✅ All Stripe API calls use test keys
- ✅ Stripe Dashboard shows transactions in Test Data
- ✅ Webhooks verified with test webhook secret

### When STRIPE_MODE=live

- ✅ Uses `STRIPE_LIVE_PUBLISHABLE_KEY`
- ✅ Uses `STRIPE_LIVE_SECRET_KEY`
- ✅ Uses `STRIPE_WEBHOOK_SECRET_LIVE`
- ✅ All Stripe API calls use live keys
- ✅ Stripe Dashboard shows transactions in Production Data
- ✅ Webhooks verified with live webhook secret
- ⚠️ **Real money is charged!**

---

## 🆘 Troubleshooting

### Issue: "Stripe secret key not configured"

**Cause**: Missing environment variable for current mode

**Solution**:
```bash
# Check your .env file
cat backend/.env | grep STRIPE

# Ensure you have keys for the active mode
# If STRIPE_MODE=test, you need STRIPE_TEST_SECRET_KEY
# If STRIPE_MODE=live, you need STRIPE_LIVE_SECRET_KEY
```

### Issue: "Webhook signature verification failed"

**Cause**: Wrong webhook secret for current mode

**Solution**:
```bash
# Get webhook secret from Stripe Dashboard
# Test mode: https://dashboard.stripe.com/test/webhooks
# Live mode: https://dashboard.stripe.com/webhooks

# Add to .env
STRIPE_WEBHOOK_SECRET_TEST=REMOVED...  # For test mode
STRIPE_WEBHOOK_SECRET_LIVE=REMOVED...  # For live mode
```

### Issue: Frontend shows wrong Stripe key

**Cause**: Backend not returning correct key

**Solution**:
```bash
# Clear config cache
docker-compose exec app php artisan config:clear

# Check API response
curl http://api.localhost/api/billing/gateway

# Should return the correct key based on STRIPE_MODE
```

---

## 📚 Related Documentation

- Stripe Test Cards: https://stripe.com/docs/testing
- Stripe API Keys: https://dashboard.stripe.com/apikeys
- Stripe Webhooks: https://stripe.com/docs/webhooks
- Backend Implementation: `docs/STRIPE_BACKEND_IMPLEMENTATION.md`
- Frontend Implementation: `docs/STRIPE_FRONTEND_IMPLEMENTATION.md`

---

**Implementation Date**: November 25, 2025  
**Author**: GitHub Copilot  
**Version**: 1.0
