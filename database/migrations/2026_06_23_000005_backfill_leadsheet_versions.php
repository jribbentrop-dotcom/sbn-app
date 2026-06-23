<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill stage (plan §6.4).
 *
 * 1. For each leadsheet, create one "Basic" version copying the arrangement data
 *    across, and point sbn_leadsheets.default_version_id at it.
 * 2. Backfill version_id on the three detection caches → the row's leadsheet
 *    default version.
 * 3. Delete orphaned voicing-cache rows (leadsheet_id with no live leadsheet) —
 *    stale cross-ref cruft for deleted leadsheets; VoicingCrossref rebuilds these
 *    on every detect. (Decision: delete, 2026-06-23.)
 *
 * Idempotent: a leadsheet that already has a 'basic' version is skipped, so a
 * re-run (or running after some versions exist) does not duplicate.
 *
 * The old sbn_leadsheets columns are left in place for the dual-read window and
 * dropped in a later migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sbn_leadsheet_versions')) {
            return;
        }

        // ── 3. Delete orphaned voicing-cache rows first (no version to assign) ──
        foreach (['sbn_voicing_usage', 'sbn_voicing_drafts'] as $cache) {
            DB::statement(
                "DELETE FROM {$cache}
                 WHERE leadsheet_id NOT IN (SELECT id FROM sbn_leadsheets)"
            );
        }

        // ── 1. One 'basic' version per leadsheet ──
        $leadsheets = DB::table('sbn_leadsheets')->get();

        foreach ($leadsheets as $ls) {
            $existing = DB::table('sbn_leadsheet_versions')
                ->where('leadsheet_id', $ls->id)
                ->where('version_slug', 'basic')
                ->value('id');

            if ($existing) {
                $versionId = $existing;
            } else {
                $now = now();
                $versionId = DB::table('sbn_leadsheet_versions')->insertGetId([
                    'leadsheet_id'      => $ls->id,
                    'version_slug'      => 'basic',
                    'label'             => 'Basic',
                    'performer'         => null,
                    'difficulty'        => is_numeric($ls->difficulty ?? null) ? (int) $ls->difficulty : 1,
                    'sort_order'        => 0,
                    'song_key'          => $ls->song_key ?? null,
                    'rhythm'            => $ls->rhythm ?? null,
                    'tempo'             => $ls->tempo ?? null,
                    'measure_count'     => $ls->measure_count ?? 0,
                    'json_data'         => $ls->json_data ?? null,
                    'melody_tab_xml'    => $ls->tab_xml ?? null,
                    'chord_tab_xml'     => null,                 // new layer, authored later
                    'shortcode_content' => $ls->shortcode_content ?? null,
                    'status'            => $ls->status ?? 'draft',
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }

            DB::table('sbn_leadsheets')
                ->where('id', $ls->id)
                ->update(['default_version_id' => $versionId]);

            // ── 2. Point this leadsheet's cache rows at its default version ──
            foreach (['sbn_progression_occurrences', 'sbn_voicing_usage', 'sbn_voicing_drafts'] as $cache) {
                DB::table($cache)
                    ->where('leadsheet_id', $ls->id)
                    ->whereNull('version_id')
                    ->update(['version_id' => $versionId]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive teardown: drop the generated versions and unset the pointer.
        // (Deleted orphan cache rows are not restored — they were stale.)
        DB::table('sbn_leadsheets')->update(['default_version_id' => null]);

        foreach (['sbn_progression_occurrences', 'sbn_voicing_usage', 'sbn_voicing_drafts'] as $cache) {
            if (Schema::hasColumn($cache, 'version_id')) {
                DB::table($cache)->update(['version_id' => null]);
            }
        }

        DB::table('sbn_leadsheet_versions')->where('version_slug', 'basic')->delete();
    }
};
