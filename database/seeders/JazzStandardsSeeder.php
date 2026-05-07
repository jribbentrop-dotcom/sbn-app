<?php

namespace Database\Seeders;

use App\Services\OliphantImporter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * JazzStandardsSeeder — seeds sbn_jazz_standards from the Oliphant repository.
 *
 * Run once:
 *   php artisan db:seed --class=JazzStandardsSeeder
 *
 * Re-seed after Oliphant updates (DEFERRED decision — use truncate+reimport):
 *   php artisan db:seed --class=JazzStandardsSeeder --force
 *   (with --force the seeder truncates first)
 *
 * Offline / CI use — place the JSON at storage/app/jazz-standards.json:
 *   The seeder automatically uses the local file when present, so no
 *   network access is required in CI or air-gapped environments.
 */
class JazzStandardsSeeder extends Seeder
{
    public function run(): void
    {
        // Skip if already seeded (idempotent by default).
        // Use --force flag to truncate and re-import.
        $existing = DB::table('sbn_jazz_standards')->count();

        if ($existing > 0) {
            $this->command->info("sbn_jazz_standards already has {$existing} rows. Use --force to re-seed.");
            return;
        }

        $this->command->info('Importing Jazz Standards from Oliphant repository...');

        // Local file support (for offline/CI use)
        $localPath = storage_path('app/jazz-standards.json');
        $usedLocal = file_exists($localPath);

        $importer = new OliphantImporter();
        $result   = $importer->import($usedLocal ? $localPath : null);

        if ($usedLocal) {
            $this->command->line("  (Using local file: {$localPath})");
        }

        $this->command->info("  ✅ Imported: {$result['imported']}");

        if ($result['skipped'] > 0) {
            $this->command->warn("  ⚠️  Skipped: {$result['skipped']}");
        }

        if (!empty($result['errors'])) {
            $this->command->warn('  Errors (first 5):');
            foreach (array_slice($result['errors'], 0, 5) as $err) {
                $this->command->line("    - {$err}");
            }
        }
    }
}
