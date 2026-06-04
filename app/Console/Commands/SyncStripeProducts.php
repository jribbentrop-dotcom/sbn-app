<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncStripeProducts extends Command
{
    protected $signature = 'sbn:sync-stripe-products
                            {--dry-run : Show what would be created without calling Stripe}
                            {--force : Re-create even if payment_ref is already set}
                            {--tax-code= : Stripe tax code to assign (overrides config default)}';

    protected $description = 'Create Stripe products + prices for all published SBN products and backfill payment_ref';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        // ── Resolve Stripe API key ──────────────────────────────────────────────
        // Not required for --dry-run (no Stripe calls are made).
        $apiKey = config('payments.stripe_managed.api_key');

        if (empty($apiKey) && !$isDryRun) {
            $this->error('STRIPE_SECRET not set. Add it to your .env (or config/payments.php) before running this command.');
            return self::FAILURE;
        }

        // ── Resolve tax code ───────────────────────────────────────────────────
        $taxCode = $this->option('tax-code') ?: config('payments.stripe_managed.tax_code');

        if (empty($taxCode)) {
            $this->error(
                'No tax code provided. Pass --tax-code=<code> or set STRIPE_TAX_CODE in .env. ' .
                'Use a Managed-Payments-eligible code (e.g. txcd_10000000 for digital goods). ' .
                'See: https://stripe.com/docs/tax/tax-codes'
            );
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        // ── Build product query ────────────────────────────────────────────────
        // Default: only published products. With --force: all products regardless of status.
        $query = $force
            ? Product::query()
            : Product::where('status', 'published');

        $products = $query->get();

        if ($products->isEmpty()) {
            $this->warn('No products found to sync.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s%d product(s) to process (tax-code: %s)',
            $isDryRun ? '[DRY-RUN] ' : '',
            $products->count(),
            $taxCode,
        ));

        // ── Stripe client (only instantiated when not dry-run) ─────────────────
        $client = $isDryRun ? null : new \Stripe\StripeClient($apiKey);

        $created = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($products as $p) {
            // Skip already-synced products unless --force
            if (!empty($p->payment_ref) && !$force) {
                $this->line("  skip  {$p->slug} (already synced: {$p->payment_ref})");
                $skipped++;
                continue;
            }

            $priceEur = number_format($p->price_cents / 100, 2);

            if ($isDryRun) {
                $this->line(sprintf(
                    '  [DRY-RUN] would create  %-40s  €%s  tax-code=%s',
                    $p->slug,
                    $priceEur,
                    $taxCode,
                ));
                $created++;
                continue;
            }

            // ── Create Stripe product with inline default_price_data ──────────
            try {
                $description = Str::limit(strip_tags($p->excerpt ?? $p->description ?? ''), 200) ?: null;

                $stripeProduct = $client->products->create([
                    'name'        => $p->title,
                    'description' => $description,
                    'tax_code'    => $taxCode,
                    'default_price_data' => [
                        'currency'     => 'eur',
                        'unit_amount'  => $p->price_cents,   // integer cents, already EUR
                        'tax_behavior' => 'inclusive',        // our prices are tax-inclusive
                    ],
                    'metadata' => [
                        'sbn_product_id' => $p->id,
                        'sbn_slug'       => $p->slug,
                    ],
                ]);

                // default_price is the Price id ("price_...") created inline above.
                $priceId = $stripeProduct->default_price;

                $p->update(['payment_ref' => $priceId]);

                $this->info("  created {$p->slug} → {$priceId}");
                $created++;
            } catch (\Throwable $e) {
                $this->warn("  failed  {$p->slug}: " . $e->getMessage());
                $failed++;
            }
        }

        // ── Summary ────────────────────────────────────────────────────────────
        $label = $isDryRun ? ' (dry-run — no changes made)' : '';
        $this->info(sprintf(
            'Done%s: %d created, %d skipped, %d failed.',
            $label,
            $created,
            $skipped,
            $failed,
        ));

        // Fail only if every attempted product failed (and we tried at least one).
        $attempted = $created + $failed;
        if (!$isDryRun && $attempted > 0 && $failed === $attempted) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
