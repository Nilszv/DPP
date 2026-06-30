<?php

namespace App\Providers;

use App\Billing\BillingProvider;
use App\Billing\ManualBillingProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolve the billing driver from config. Stripe is added later; until then 'manual'.
        $this->app->bind(BillingProvider::class, function () {
            return match (config('billing.driver')) {
                'stripe' => throw new RuntimeException('Stripe billing is not configured yet. Set BILLING_DRIVER=manual or add StripeBillingProvider.'),
                default => new ManualBillingProvider,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
