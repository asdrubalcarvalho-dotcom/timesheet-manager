<?php

namespace App\Providers;

use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\FakeCreditCardGateway;
use App\Services\Payments\StripeGateway;
use App\Services\Billing\PlanManager;
use Illuminate\Support\ServiceProvider;

/**
 * PaymentGatewayServiceProvider
 *
 * Registers payment gateways and resolves the active gateway
 * based on BILLING_GATEWAY environment variable.
 *
 * Supported gateways:
 * - fake: FakeCreditCardGateway (for development/testing)
 * - stripe: StripeGateway (for production)
 */
class PaymentGatewayServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the payment gateway singleton
        $this->app->singleton(PaymentGatewayInterface::class, function ($app) {
            // Use PAYMENTS_DRIVER env variable (NOT billing.gateway)
            $driver = env('PAYMENTS_DRIVER', 'fake');
            $planManager = $app->make(PlanManager::class);

            return match ($driver) {
                'stripe' => new StripeGateway($planManager),
                'fake' => new FakeCreditCardGateway($planManager),
                default => throw new \RuntimeException("Unsupported payment gateway: {$driver}"),
            };
        });

        // Also register by alias for explicit DI
        $this->app->alias(PaymentGatewayInterface::class, 'payment.gateway');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Log which gateway is configured
        $driver = env('PAYMENTS_DRIVER', 'fake');
        \Log::info("[PaymentGatewayServiceProvider] Payment gateway configured: {$driver}");
    }
}
