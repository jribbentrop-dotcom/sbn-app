<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Non-network tests for sbn:sync-stripe-products.
 *
 * All tests run against the real sbn.db with per-test cleanup (same pattern as
 * PaymentWebhookTest / AuthTest). RefreshDatabase/:memory: is unusable here.
 *
 * The happy path (successful Stripe API call + payment_ref backfill) requires a
 * live Stripe secret and is therefore intentionally not covered — a real network
 * call in CI would be fragile and credential-dependent.
 */
class SyncStripeProductsTest extends TestCase
{
    private const TEST_SLUG = 'sync-stripe-test-product';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        Product::where('slug', self::TEST_SLUG)->delete();
    }

    // ── Helper ─────────────────────────────────────────────────────────────────

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'slug'        => self::TEST_SLUG,
            'title'       => 'Sync Test Product',
            'price_cents' => 1500,
            'status'      => 'published',
            'published_at' => now(),
        ], $overrides));
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * --dry-run must not write anything to the DB, even when a product has no
     * payment_ref and would normally be synced.
     */
    public function test_dry_run_makes_no_db_changes(): void
    {
        $product = $this->makeProduct(['payment_ref' => null]);

        $this->artisan('sbn:sync-stripe-products', [
            '--dry-run'   => true,
            '--tax-code'  => 'txcd_test',
        ])->assertExitCode(0);

        $product->refresh();
        $this->assertNull($product->payment_ref, 'payment_ref must remain null after a dry-run');
    }

    /**
     * When STRIPE_SECRET is missing the command must fail with a non-zero exit
     * code and print a human-readable error.
     */
    public function test_missing_api_key_returns_failure(): void
    {
        config(['payments.stripe_managed.api_key' => '']);

        $this->artisan('sbn:sync-stripe-products', ['--tax-code' => 'txcd_test'])
             ->assertFailed()
             ->expectsOutputToContain('STRIPE_SECRET not set');
    }

    /**
     * When no tax code is available (neither --tax-code nor config) the command
     * must fail with a human-readable error.
     */
    public function test_missing_tax_code_returns_failure(): void
    {
        // Provide a key so we get past that check.
        config([
            'payments.stripe_managed.api_key' => 'sk_test_dummy',
            'payments.stripe_managed.tax_code' => null,
        ]);

        $this->artisan('sbn:sync-stripe-products')
             ->assertFailed()
             ->expectsOutputToContain('No tax code provided');
    }

    /**
     * A product that already has payment_ref set is reported as skipped (and its
     * payment_ref is not touched) when run without --force.
     */
    public function test_already_synced_product_is_skipped_in_dry_run(): void
    {
        $product = $this->makeProduct(['payment_ref' => 'price_already_set_123']);

        $this->artisan('sbn:sync-stripe-products', [
            '--dry-run'  => true,
            '--tax-code' => 'txcd_test',
        ])->assertExitCode(0)
          ->expectsOutputToContain('already synced');

        $product->refresh();
        $this->assertSame('price_already_set_123', $product->payment_ref, 'payment_ref must not change on skip');
    }

    /**
     * --dry-run summary line always contains "created" and "skipped" counts.
     * We can't assert an exact number because other published products in sbn.db
     * (without payment_ref) are also counted — just confirm the summary is present.
     */
    public function test_dry_run_summary_shows_created_count(): void
    {
        $this->makeProduct(['payment_ref' => null]);

        $this->artisan('sbn:sync-stripe-products', [
            '--dry-run'  => true,
            '--tax-code' => 'txcd_test',
        ])->assertExitCode(0)
          ->expectsOutputToContain('no changes made');
    }
}
