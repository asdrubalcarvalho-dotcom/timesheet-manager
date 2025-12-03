<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trial Configuration
    |--------------------------------------------------------------------------
    |
    | New tenants start with a 15‑day Enterprise trial. Trial gives access to
    | ALL modules with effectively unlimited users. After the trial:
    | - If user_count ≤ 2 → downgrade to Starter
    | - If user_count > 2 → requires upgrade
    |
    */

    'trial' => [
        'enabled'     => true,
        'days'        => 15,
        'plan'        => 'enterprise',
        'user_limit'  => 999999, // effectively unlimited during trial
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans
    |--------------------------------------------------------------------------
    |
    | Official billing plans for tenants. Prices are in EUR (€).
    | These values are used by PriceCalculator + PlanManager.
    |
    */

    'plans' => [

        'starter' => [
            'name'            => 'Starter',
            'flat_monthly'    => 0,
            'price_per_user'  => null,
            'included_users'  => 2,
            'features' => [
                'timesheets' => true,
                'expenses'   => true,
                'travels'    => false,
                'planning'   => false,
                'ai'         => false,
            ],
            'addons' => [],
        ],

        'team' => [
            'name'            => 'Team',
            'flat_monthly'    => null,
            'price_per_user'  => 44, // €44 per user
            'included_users'  => null,
            'stripe_price_id' => env('STRIPE_MODE', 'test') === 'live'
                ? env('STRIPE_LIVE_TEAM_PRICE_ID')
                : env('STRIPE_TEST_TEAM_PRICE_ID'),
            'features' => [
                'timesheets' => true,
                'expenses'   => true,
                'travels'    => true,
                'planning'   => false, // planning NOT included by default
                'ai'         => false, // via add-on
            ],
            'addons' => [
                'planning' => 0.18, // +18%
                'ai'       => 0.18, // +18%
            ],
        ],

        'enterprise' => [
            'name'            => 'Enterprise',
            'flat_monthly'    => null,
            'price_per_user'  => 59, // €59 per user
            'included_users'  => null,
            'stripe_price_id' => env('STRIPE_MODE', 'test') === 'live'
                ? env('STRIPE_LIVE_ENTERPRISE_PRICE_ID')
                : env('STRIPE_TEST_ENTERPRISE_PRICE_ID'),
            'features' => [
                'timesheets' => true,
                'expenses'   => true,
                'travels'    => true,
                'planning'   => true,
                'ai'         => true,
            ],
            'addons' => [], // everything included
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Limits per Plan
    |--------------------------------------------------------------------------
    |
    | Minimum and maximum user limits for each plan tier.
    |
    */

    'user_limits' => [
        'starter' => [
            'min' => 1,
            'max' => 2, // Hard cap, cannot be increased
        ],
        'team' => [
            'min' => 1,
            'max' => 99999, // Flexible limit with high ceiling
        ],
        'enterprise' => [
            'min' => 1,
            'max' => 99999, // Flexible limit with high ceiling
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Add-ons Configuration
    |--------------------------------------------------------------------------
    |
    | Add-ons available for Team plan (Enterprise includes everything).
    | Each addon has a percentage-based price and a Stripe Price ID.
    |
    */

    'addons' => [
        'planning' => [
            'percentage' => 0.18, // +18% of base plan price
            'stripe_price_id' => env('STRIPE_MODE', 'test') === 'live'
                ? env('STRIPE_LIVE_ADDON_PLANNING_PRICE_ID')
                : env('STRIPE_TEST_ADDON_PLANNING_PRICE_ID'),
        ],
        'ai' => [
            'percentage' => 0.18, // +18% of base plan price
            'stripe_price_id' => env('STRIPE_MODE', 'test') === 'live'
                ? env('STRIPE_LIVE_ADDON_AI_PRICE_ID')
                : env('STRIPE_TEST_ADDON_AI_PRICE_ID'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Mapping for Pennant
    |--------------------------------------------------------------------------
    |
    | PlanManager will activate/deactivate these automatically.
    | Add-ons are handled separately for Team.
    |
    */

    'feature_mapping' => [
        'starter' => [
            'timesheets' => true,
            'expenses'   => true,
            'travels'    => false,
            'planning'   => false,
            'ai'         => false,
        ],
        'team' => [
            'timesheets' => true,
            'expenses'   => true,
            'travels'    => true,
            'planning'   => false, // addon-based
            'ai'         => false, // addon-based
        ],
        'enterprise' => [
            'timesheets' => true,
            'expenses'   => true,
            'travels'    => true,
            'planning'   => true,
            'ai'         => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Subscriptions Configuration
    |--------------------------------------------------------------------------
    |
    | Feature flag to enable Stripe Subscription-based recurring billing.
    | When enabled, the system creates actual Stripe Subscription objects
    | instead of using one-time PaymentIntents for recurring charges.
    |
    | IMPORTANT: For safety during rollout, subscriptions are only enabled
    | when BOTH conditions are met:
    | 1. BILLING_SUBSCRIPTIONS_ENABLED=true in .env
    | 2. STRIPE_MODE=test (test mode only for now)
    |
    */

    'subscriptions' => [
        // Global feature flag for using Stripe Subscriptions
        'enabled' => env('BILLING_SUBSCRIPTIONS_ENABLED', false),

        // Safety: only allow subscriptions in test mode during initial rollout
        'test_mode_only' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Configuration
    |--------------------------------------------------------------------------
    |
    | Choose which payment gateway to use: 'fake' or 'stripe'
    | - fake: For development/testing (FakeCreditCardGateway)
    | - stripe: For production (StripeGateway)
    |
    */

    'gateway' => env('BILLING_GATEWAY', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    */

    'gateways' => [
        'default' => env('BILLING_GATEWAY', 'fake_card'),

        'fake_card' => [
            'name'   => 'Fake Credit Card (Testing)',
            'enabled'=> env('APP_ENV') !== 'production',
            'auto_approve' => true,
        ],

        'stripe' => [
            'name'   => 'Stripe',
            'enabled'=> false,
            // Keys loaded dynamically based on STRIPE_MODE (test/live)
            // Uses env() directly to avoid circular dependencies
            'public_key' => env('STRIPE_MODE', 'test') === 'live' 
                ? env('STRIPE_LIVE_PUBLISHABLE_KEY') 
                : env('STRIPE_TEST_PUBLISHABLE_KEY'),
            'secret_key' => env('STRIPE_MODE', 'test') === 'live' 
                ? env('STRIPE_LIVE_SECRET_KEY') 
                : env('STRIPE_TEST_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_MODE', 'test') === 'live'
                ? env('STRIPE_LIVE_WEBHOOK_SECRET')
                : env('STRIPE_TEST_WEBHOOK_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax Configuration (Phase 3)
    |--------------------------------------------------------------------------
    |
    | Stripe Automatic Tax integration.
    | When enabled, Stripe will calculate taxes based on customer location.
    |
    | SAFETY: test_mode_only constraint ensures tax is only applied in test mode.
    |
    */

    'tax' => [
        'enabled' => env('BILLING_TAX_ENABLED', false),
        'test_mode_only' => true, // Safety: requires STRIPE_MODE=test
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Configuration (Phase 3)
    |--------------------------------------------------------------------------
    |
    | Enable Stripe Invoice generation for:
    | - Subscription renewals (automatic)
    | - Addon purchases (manual invoice creation)
    | - Plan upgrades/downgrades with prorations
    |
    | SAFETY: test_mode_only constraint ensures invoices only in test mode.
    |
    */

    'invoices' => [
        'enabled' => env('BILLING_INVOICES_ENABLED', false),
        'test_mode_only' => true, // Safety: requires STRIPE_MODE=test
    ],

    /*
    |--------------------------------------------------------------------------
    | ERP Sync Configuration (Phase 3)
    |--------------------------------------------------------------------------
    |
    | Synchronize Stripe invoices to local database for ERP integration.
    | Tracks invoice status, PDF URLs, and deadlines for accounting systems.
    |
    | notify_email: Email address to notify when invoices need ERP processing
    | legal_deadline_days: Days before invoice must be processed (default: 15)
    |
    */

    'erp_sync' => [
        'enabled' => env('BILLING_ERP_SYNC_ENABLED', false),
        'notify_email' => env('BILLING_ERP_NOTIFY_EMAIL', null),
        'legal_deadline_days' => 15, // Portuguese tax law compliance
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Portal Configuration (Phase 4)
    |--------------------------------------------------------------------------
    |
    | Enable Stripe Customer Billing Portal for self-service management.
    | Allows tenants to:
    | - Update payment methods
    | - View invoices and payment history
    | - Manage subscription (pause, cancel, update quantity)
    | - Download receipts
    |
    | IMPORTANT: Portal configuration must be set in Stripe Dashboard.
    |
    */

    'portal' => [
        'enabled' => env('BILLING_PORTAL_ENABLED', true),
        'return_url' => env('APP_URL') . '/billing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dunning Configuration (Phase 4)
    |--------------------------------------------------------------------------
    |
    | Intelligent payment failure reminder system.
    | Sends automated emails to tenants with failed payments.
    |
    | Reminder Schedule:
    | - Day 3: First reminder
    | - Day 7: Second reminder
    | - Day 14: Final warning
    | - Day 21: Subscription auto-paused
    |
    */

    'dunning' => [
        'enabled' => env('BILLING_DUNNING_ENABLED', true),
        'days' => [
            'reminder_1' => 3,
            'reminder_2' => 7,
            'final_warning' => 14,
            'pause' => 21,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Pause/Resume (Phase 4)
    |--------------------------------------------------------------------------
    |
    | Allow tenants to temporarily pause subscriptions without canceling.
    | Useful for seasonal businesses or temporary inactivity.
    |
    | When paused:
    | - No charges occur
    | - Access may be restricted (configurable)
    | - Subscription can be resumed anytime
    |
    */

    'pause_resume' => [
        'enabled' => env('BILLING_PAUSE_RESUME_ENABLED', true),
        'restrict_access_when_paused' => true, // Show banner + limit features
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    */

    'currency' => [
        'code'     => 'EUR',
        'symbol'   => '€',
        'position' => 'after',
    ],

];
