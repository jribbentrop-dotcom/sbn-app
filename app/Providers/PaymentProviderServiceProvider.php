<?php

namespace App\Providers;

use App\Services\Payments\FakeProvider;
use App\Services\Payments\PaymentProvider;
use Illuminate\Support\ServiceProvider;

class PaymentProviderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentProvider::class, function ($app) {
            // Tests always use the fake provider (no real account / network).
            if ($app->environment('testing')) {
                return new FakeProvider(config('payments.fake.signing_secret', 'fake-secret'));
            }

            $provider = config('payments.provider', 'fake');

            return match ($provider) {
                'fake' => new FakeProvider(config('payments.fake.signing_secret', 'fake-secret')),
                // Concrete providers are added once the MoR is finalized:
                // 'paddle'         => new PaddleProvider(...),
                // 'stripe_managed' => new StripeManagedProvider(...),
                default => throw new \RuntimeException("Unknown payment provider: {$provider}"),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
