# Stripe Payment Integration - Complete Setup Guide

## 📋 Overview

This guide covers the complete Stripe payment integration for TimePerk Cortex. The integration supports:
- **PaymentIntent Flow**: Secure payment processing with SCA compliance
- **SetupIntent Flow**: Save payment methods without charging
- **Payment Method Management**: List, add, remove, and set default cards
- **Webhook Processing**: Automatic payment confirmation and status updates
- **Multi-Gateway Support**: Switch between Stripe and Fake gateway via configuration

## 🏗️ Architecture

### Gateway Pattern
```
PaymentGatewayInterface
├── FakeCreditCardGateway (development/testing)
└── StripeGateway (production payments)
```

### Flow Diagram
```
Frontend                 Backend                    Stripe
   │                        │                          │
   ├──GET /billing/gateway──>│                          │
   │<────gateway config──────┤                          │
   │                        │                          │
   ├─POST /checkout/start───>│                          │
   │                        ├─createPaymentIntent()────>│
   │<─payment_id,client_sec─┤<────PaymentIntent────────┤
   │                        │                          │
   ├─[Stripe.js confirms]───┼─────────────────────────>│
   │                        │<───webhook: succeeded────┤
   │                        │ applyPlan()              │
   │                        │                          │
   ├─POST /checkout/confirm─>│                          │
   │<────success─────────────┤                          │
```

## ⚙️ Backend Configuration

### 1. Environment Variables

Add to `.env`:

```bash
# Payment Gateway Selection
BILLING_GATEWAY=stripe  # Options: 'fake' | 'stripe'

# Stripe API Keys
STRIPE_SECRET_KEY=REMOVEDXXXXXXXXXXXXXXXXXXXXXXXX
STRIPE_PUBLIC_KEY=REMOVEDXXXXXXXXXXXXXXXXXXXXXXXX
STRIPE_WEBHOOK_SECRET=REMOVEDXXXXXXXXXXXXXXXXXXXXXXXX
```

**Where to find these keys:**
1. Go to [Stripe Dashboard](https://dashboard.stripe.com/test/apikeys)
2. Copy **Secret key** → `STRIPE_SECRET_KEY`
3. Copy **Publishable key** → `STRIPE_PUBLIC_KEY`
4. Go to [Webhooks](https://dashboard.stripe.com/test/webhooks) → Create endpoint → Copy **Signing secret** → `STRIPE_WEBHOOK_SECRET`

### 2. Test Mode vs Live Mode

**Test Mode** (development):
```bash
STRIPE_SECRET_KEY=REMOVED...
STRIPE_PUBLIC_KEY=REMOVED...
```

**Live Mode** (production):
```bash
STRIPE_SECRET_KEY=REMOVED...
STRIPE_PUBLIC_KEY=REMOVED...
```

⚠️ **IMPORTANT**: Never commit live keys to git! Keep them in environment-specific `.env` files.

### 3. Configuration Files

Already configured in:
- `config/billing.php` - Gateway selection
- `config/services.php` - Stripe credentials
- `bootstrap/providers.php` - PaymentGatewayServiceProvider registered

## 🔧 Stripe Webhook Setup

### Step 1: Configure Webhook Endpoint in Stripe

1. Go to [Stripe Dashboard → Webhooks](https://dashboard.stripe.com/test/webhooks)
2. Click **+ Add endpoint**
3. Enter endpoint URL:
   ```
   https://yourdomain.com/api/stripe/webhook
   ```
   For local testing:
   ```
   https://your-ngrok-url.ngrok.io/api/stripe/webhook
   ```

4. Select events to listen for:
   - ✅ `payment_intent.succeeded`
   - ✅ `payment_intent.payment_failed`
   - ✅ `payment_intent.canceled`
   - ✅ `charge.refunded`

5. Click **Add endpoint**
6. Copy the **Signing secret** (starts with `REMOVED...`)
7. Add to `.env`:
   ```bash
   STRIPE_WEBHOOK_SECRET=REMOVEDXXXXXXXXXXXXXXXXXXXXXXXX
   ```

### Step 2: Test Webhook Locally (Optional)

Use Stripe CLI for local testing:

```bash
# Install Stripe CLI
brew install stripe/stripe-cli/stripe

# Login to Stripe
stripe login

# Forward webhooks to local server
stripe listen --forward-to http://localhost:80/api/stripe/webhook
```

The CLI will output a webhook signing secret starting with `REMOVED...`. Use this for local testing.

### Step 3: Verify Webhook Reception

Send a test event from Stripe Dashboard:
1. Go to your webhook endpoint in Stripe Dashboard
2. Click **Send test webhook**
3. Select `payment_intent.succeeded`
4. Check Laravel logs:
   ```bash
   docker-compose exec app tail -f storage/logs/laravel.log | grep "Stripe webhook"
   ```

You should see:
```
[timestamp] Stripe webhook event received {"type":"payment_intent.succeeded","id":"evt_..."}
```

## 🧪 Testing the Integration

### Test with Fake Gateway (Development)

1. Set in `.env`:
   ```bash
   BILLING_GATEWAY=fake
   ```

2. Restart containers:
   ```bash
   docker-compose down -v && docker-compose up -d --build
   ```

3. Test checkout:
   ```bash
   # Get gateway config
   curl http://api.localhost/api/billing/gateway \
     -H "X-Tenant: test-company" \
     -H "Authorization: Bearer YOUR_TOKEN"
   
   # Response: {"gateway":"fake","currency":"EUR"}
   
   # Start checkout
   curl -X POST http://api.localhost/api/billing/checkout/start \
     -H "X-Tenant: test-company" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"mode":"plan","plan":"team"}'
   
   # Response includes payment_id and session_id (fake)
   
   # Confirm payment
   curl -X POST http://api.localhost/api/billing/checkout/confirm \
     -H "X-Tenant: test-company" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"payment_id":1,"card_number":"4242424242424242"}'
   
   # Response: {"success":true,"payment_id":1,"status":"paid"}
   ```

### Test with Stripe (Test Mode)

1. Set in `.env`:
   ```bash
   BILLING_GATEWAY=stripe
   STRIPE_SECRET_KEY=REMOVED...
   STRIPE_PUBLIC_KEY=REMOVED...
   ```

2. Restart containers:
   ```bash
   docker-compose down -v && docker-compose up -d --build
   ```

3. Test checkout:
   ```bash
   # Get gateway config
   curl http://api.localhost/api/billing/gateway \
     -H "X-Tenant: test-company" \
     -H "Authorization: Bearer YOUR_TOKEN"
   
   # Response: {"gateway":"stripe","stripe_public_key":"REMOVED...","currency":"EUR"}
   
   # Start checkout (creates PaymentIntent)
   curl -X POST http://api.localhost/api/billing/checkout/start \
     -H "X-Tenant: test-company" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"mode":"plan","plan":"enterprise","user_limit":50}'
   
   # Response: {
   #   "payment_id": 123,
   #   "client_secret": "pi_XXX_secret_XXX",
   #   "gateway": "stripe",
   #   "amount": 500.00,
   #   "currency": "EUR"
   # }
   ```

4. Use Stripe test cards:
   - **Success**: `4242 4242 4242 4242`
   - **Requires authentication**: `4000 0025 0000 3155`
   - **Declined**: `4000 0000 0000 9995`
   - CVC: Any 3 digits
   - Expiry: Any future date
   - ZIP: Any 5 digits

5. Complete payment (frontend would use Stripe.js):
   ```javascript
   // Frontend code example (not API)
   const stripe = Stripe('REMOVED...');
   const {error} = await stripe.confirmCardPayment(clientSecret, {
     payment_method: {
       card: cardElement,
       billing_details: {name: 'Tenant Name'}
     }
   });
   ```

6. Verify webhook fired:
   ```bash
   docker-compose exec app tail -f storage/logs/laravel.log | grep "Payment marked as paid"
   ```

### Test Payment Method Management

```bash
# Create SetupIntent for adding card
curl http://api.localhost/api/billing/payment-methods/setup-intent \
  -H "X-Tenant: test-company" \
  -H "Authorization: Bearer YOUR_TOKEN"

# List saved payment methods
curl http://api.localhost/api/billing/payment-methods \
  -H "X-Tenant: test-company" \
  -H "Authorization: Bearer YOUR_TOKEN"

# Set default payment method
curl -X POST http://api.localhost/api/billing/payment-methods/pm_XXX/default \
  -H "X-Tenant: test-company" \
  -H "Authorization: Bearer YOUR_TOKEN"

# Remove payment method
curl -X DELETE http://api.localhost/api/billing/payment-methods/pm_XXX \
  -H "X-Tenant: test-company" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## 📊 Database Schema

### Payments Table
```sql
CREATE TABLE payments (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    tenant_id CHAR(26) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'EUR',
    status ENUM('pending','paid','failed','refunded','canceled') DEFAULT 'pending',
    metadata JSON,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
```

### Tenants Table Addition
```sql
ALTER TABLE tenants ADD COLUMN stripe_customer_id VARCHAR(255) NULL;
ALTER TABLE tenants ADD INDEX idx_stripe_customer (stripe_customer_id);
```

## 🔍 Monitoring & Logs

### Laravel Logs
```bash
# View all logs
docker-compose exec app tail -f storage/logs/laravel.log

# Filter Stripe-related logs
docker-compose exec app tail -f storage/logs/laravel.log | grep "Stripe"

# Filter payment confirmations
docker-compose exec app tail -f storage/logs/laravel.log | grep "Payment marked as paid"
```

### Stripe Dashboard
- View payments: [Dashboard → Payments](https://dashboard.stripe.com/test/payments)
- View customers: [Dashboard → Customers](https://dashboard.stripe.com/test/customers)
- View events: [Dashboard → Events](https://dashboard.stripe.com/test/events)
- Test webhooks: [Dashboard → Webhooks](https://dashboard.stripe.com/test/webhooks)

## 🐛 Troubleshooting

### Problem: "Gateway not configured"
**Solution**: Check `.env` has correct `BILLING_GATEWAY=stripe` and Stripe keys are set.

```bash
# Verify config
docker-compose exec app php artisan tinker
>>> config('billing.gateway')
=> "stripe"
>>> config('services.stripe.secret')
=> "REMOVED..."
```

### Problem: "Stripe customer creation failed"
**Solution**: Verify Stripe API keys are valid and not revoked.

```bash
# Test API key directly
curl https://api.stripe.com/v1/customers \
  -u REMOVEDXXXXXXXXXXXXXXXXXXXXXXXX: \
  -d "description=Test Customer"
```

### Problem: Webhook signature verification fails
**Solution**: Ensure `STRIPE_WEBHOOK_SECRET` matches the endpoint in Stripe Dashboard.

```bash
# Check webhook secret
docker-compose exec app php artisan tinker
>>> config('services.stripe.webhook_secret')
=> "REMOVED..."
```

### Problem: Payments not confirmed automatically
**Solution**: Check webhook is firing and reaching your server.

1. Verify webhook endpoint is accessible (not blocked by firewall)
2. Check Laravel logs for webhook reception
3. Test with Stripe CLI: `stripe trigger payment_intent.succeeded`

### Problem: Frontend shows "fake" gateway instead of Stripe
**Solution**: Clear Laravel config cache and rebuild containers.

```bash
docker-compose down -v && docker-compose up -d --build
```

## 🚀 Deployment Checklist

### Pre-Production
- [ ] Stripe account verified and activated
- [ ] Live API keys generated
- [ ] Webhook endpoint registered with live keys
- [ ] Test all payment flows in test mode
- [ ] Verify webhook signature validation works
- [ ] Test refund handling
- [ ] Review error handling and logging

### Production
- [ ] Update `.env` with live Stripe keys
- [ ] Set `BILLING_GATEWAY=stripe`
- [ ] Configure webhook endpoint with production URL
- [ ] Verify webhook secret is correct
- [ ] Monitor first live transactions
- [ ] Set up Stripe Dashboard alerts
- [ ] Document payment reconciliation process

## 📚 API Reference

### GET /api/billing/gateway
Returns active gateway configuration.

**Response:**
```json
{
  "gateway": "stripe",
  "stripe_public_key": "REMOVED...",
  "currency": "EUR"
}
```

### POST /api/billing/checkout/start
Creates payment intent.

**Request:**
```json
{
  "mode": "plan",
  "plan": "enterprise",
  "user_limit": 50
}
```

**Response:**
```json
{
  "payment_id": 123,
  "client_secret": "pi_XXX_secret_XXX",
  "gateway": "stripe",
  "amount": 500.00,
  "currency": "EUR"
}
```

### POST /api/billing/checkout/confirm
Confirms payment (called after Stripe.js confirmation).

**Request:**
```json
{
  "payment_id": 123,
  "payment_method_id": "pm_XXX"
}
```

**Response:**
```json
{
  "success": true,
  "payment_id": 123,
  "status": "paid",
  "message": "Plan upgraded to enterprise successfully"
}
```

### POST /api/stripe/webhook
Receives Stripe webhook events (called by Stripe servers).

**Headers:**
```
Stripe-Signature: t=XXX,v1=XXX
```

**Body:**
```json
{
  "id": "evt_XXX",
  "type": "payment_intent.succeeded",
  "data": {
    "object": { ... }
  }
}
```

## 🔐 Security Best Practices

1. **Never commit Stripe keys** to version control
2. **Use webhook signature verification** (already implemented)
3. **Validate amounts** on backend before creating PaymentIntent
4. **Log all payment events** for audit trail
5. **Use HTTPS** in production (required by Stripe)
6. **Restrict webhook endpoint** to Stripe IPs (optional, via firewall)
7. **Monitor for suspicious activity** in Stripe Dashboard
8. **Implement rate limiting** on payment endpoints (already configured)

## 📖 Additional Resources

- [Stripe API Documentation](https://stripe.com/docs/api)
- [Stripe PaymentIntents Guide](https://stripe.com/docs/payments/payment-intents)
- [Stripe Webhooks Guide](https://stripe.com/docs/webhooks)
- [Stripe Test Cards](https://stripe.com/docs/testing)
- [Stripe Dashboard](https://dashboard.stripe.com)

---

**Implementation Status:** ✅ Backend complete | ⏳ Frontend pending | ⏳ Webhooks configured

**Last Updated:** 2025-11-25
