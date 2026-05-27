<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Consolidates all musical style categories to 4 canonical slugs:
 *   bossa-nova, jazz, classical, pop
 *
 * Creates a polymorphic tag system (sbn_tags + sbn_taggables) so that
 * niche sub-genres (blues, modal, cuban, latin, etc.) are preserved as
 * searchable hashtags rather than primary categories.
 */
return new class extends Migration
{
    // ── Canonical mapping: old value → new category ──────────────────────────

    private const PROGRESSION_MAP = [
        'blues'     => ['category' => 'jazz',       'tag' => 'blues'],
        'latin'     => ['category' => 'bossa-nova',  'tag' => 'latin'],
        'modal'     => ['category' => 'jazz',        'tag' => 'modal'],
        'other'     => ['category' => 'pop',         'tag' => null],
        // already canonical — no change needed for jazz, classical, pop
    ];

    private const RHYTHM_MAP = [
        // raw DB value  → new category,   auto-tag (null = no tag)
        'Cuban'          => ['category' => 'jazz',      'tag' => 'cuban'],
        'Jazz'           => ['category' => 'jazz',      'tag' => null],
        'bossa nova'     => ['category' => 'bossa-nova','tag' => null],
        'brazilian'      => ['category' => 'bossa-nova','tag' => 'brazilian'],
        'general'        => ['category' => 'pop',       'tag' => null],
    ];

    // ── Tags to pre-seed ──────────────────────────────────────────────────────

    private const SEED_TAGS = [
        ['slug' => 'blues',     'label' => 'Blues'],
        ['slug' => 'modal',     'label' => 'Modal'],
        ['slug' => 'latin',     'label' => 'Latin'],
        ['slug' => 'cuban',     'label' => 'Cuban'],
        ['slug' => 'brazilian', 'label' => 'Brazilian'],
        ['slug' => 'swing',     'label' => 'Swing'],
        ['slug' => 'afro-cuban','label' => 'Afro-Cuban'],
        ['slug' => 'ballad',    'label' => 'Ballad'],
        ['slug' => 'samba',     'label' => 'Samba'],
    ];

    // ─────────────────────────────────────────────────────────────────────────

    public function up(): void
    {
        // 1. Create sbn_tags ──────────────────────────────────────────────────
        Schema::create('sbn_tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->timestamps();
        });

        // 2. Create polymorphic pivot ─────────────────────────────────────────
        Schema::create('sbn_taggables', function (Blueprint $table) {
            $table->unsignedBigInteger('tag_id');
            $table->unsignedBigInteger('taggable_id');
            $table->string('taggable_type');

            $table->primary(['tag_id', 'taggable_id', 'taggable_type']);
            $table->foreign('tag_id')->references('id')->on('sbn_tags')->onDelete('cascade');
            $table->index(['taggable_type', 'taggable_id']);
        });

        // 3. Seed canonical tags ──────────────────────────────────────────────
        $now = now();
        foreach (self::SEED_TAGS as $tag) {
            DB::table('sbn_tags')->insert([
                'slug'       => $tag['slug'],
                'label'      => $tag['label'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 4. Remap chord_progressions categories + attach tags ────────────────
        foreach (self::PROGRESSION_MAP as $oldCat => $spec) {
            $rows = DB::table('sbn_chord_progressions')
                ->where('category', $oldCat)
                ->pluck('id');

            if ($rows->isEmpty()) continue;

            DB::table('sbn_chord_progressions')
                ->where('category', $oldCat)
                ->update(['category' => $spec['category']]);

            if ($spec['tag']) {
                $tagId = DB::table('sbn_tags')->where('slug', $spec['tag'])->value('id');
                if ($tagId) {
                    $pivots = $rows->map(fn ($id) => [
                        'tag_id'        => $tagId,
                        'taggable_id'   => $id,
                        'taggable_type' => 'App\\Models\\ChordProgression',
                    ])->all();
                    DB::table('sbn_taggables')->insertOrIgnore($pivots);
                }
            }
        }

        // 5. Remap rhythm_patterns categories + attach tags ───────────────────
        foreach (self::RHYTHM_MAP as $oldCat => $spec) {
            $rows = DB::table('sbn_rhythm_patterns')
                ->where('category', $oldCat)
                ->pluck('id');

            if ($rows->isEmpty()) continue;

            DB::table('sbn_rhythm_patterns')
                ->where('category', $oldCat)
                ->update(['category' => $spec['category']]);

            if ($spec['tag']) {
                $tagId = DB::table('sbn_tags')->where('slug', $spec['tag'])->value('id');
                if ($tagId) {
                    $pivots = $rows->map(fn ($id) => [
                        'tag_id'        => $tagId,
                        'taggable_id'   => $id,
                        'taggable_type' => 'App\\Models\\RhythmPattern',
                    ])->all();
                    DB::table('sbn_taggables')->insertOrIgnore($pivots);
                }
            }
        }

        // 6. Remap leadsheet rhythm field (raw values → canonical slugs) ──────
        $leadsheetRhythmMap = [
            'samba'      => ['rhythm' => 'bossa-nova', 'tag' => 'samba'],
            'latin'      => ['rhythm' => 'bossa-nova', 'tag' => 'latin'],
            'afro-cuban' => ['rhythm' => 'bossa-nova', 'tag' => 'afro-cuban'],
            'swing'      => ['rhythm' => 'jazz',       'tag' => 'swing'],
            'blues'      => ['rhythm' => 'jazz',       'tag' => 'blues'],
            'ballad'     => ['rhythm' => 'pop',        'tag' => 'ballad'],
        ];

        foreach ($leadsheetRhythmMap as $oldRhythm => $spec) {
            $rows = DB::table('sbn_leadsheets')
                ->where('rhythm', $oldRhythm)
                ->pluck('id');

            if ($rows->isEmpty()) continue;

            DB::table('sbn_leadsheets')
                ->where('rhythm', $oldRhythm)
                ->update(['rhythm' => $spec['rhythm']]);

            if ($spec['tag']) {
                $tagId = DB::table('sbn_tags')->where('slug', $spec['tag'])->value('id');
                if ($tagId) {
                    $pivots = $rows->map(fn ($id) => [
                        'tag_id'        => $tagId,
                        'taggable_id'   => $id,
                        'taggable_type' => 'App\\Models\\Leadsheet',
                    ])->all();
                    DB::table('sbn_taggables')->insertOrIgnore($pivots);
                }
            }
        }
    }

    public function down(): void
    {
        // Reverse data remaps (best-effort — tags cannot be perfectly un-seeded
        // but the structural rollback restores the schema)

        // Restore progression categories from tag presence
        $reverseProgression = [
            'jazz'      => ['blues' => 'blues', 'modal' => 'modal'],
            'bossa-nova' => ['latin' => 'latin'],
            'pop'       => [], // 'other' had no tag, cannot distinguish
        ];

        foreach ($reverseProgression as $newCat => $tagMap) {
            foreach ($tagMap as $tagSlug => $oldCat) {
                $tagId = DB::table('sbn_tags')->where('slug', $tagSlug)->value('id');
                if (!$tagId) continue;
                $ids = DB::table('sbn_taggables')
                    ->where('tag_id', $tagId)
                    ->where('taggable_type', 'App\\Models\\ChordProgression')
                    ->pluck('taggable_id');
                if ($ids->isNotEmpty()) {
                    DB::table('sbn_chord_progressions')
                        ->whereIn('id', $ids)
                        ->update(['category' => $oldCat]);
                }
            }
        }

        // Restore rhythm categories
        $reverseRhythm = [
            'cuban'     => ['category' => 'Cuban',    'type' => 'App\\Models\\RhythmPattern'],
            'brazilian' => ['category' => 'brazilian','type' => 'App\\Models\\RhythmPattern'],
        ];
        foreach ($reverseRhythm as $tagSlug => $spec) {
            $tagId = DB::table('sbn_tags')->where('slug', $tagSlug)->value('id');
            if (!$tagId) continue;
            $ids = DB::table('sbn_taggables')
                ->where('tag_id', $tagId)
                ->where('taggable_type', $spec['type'])
                ->pluck('taggable_id');
            if ($ids->isNotEmpty()) {
                DB::table('sbn_rhythm_patterns')
                    ->whereIn('id', $ids)
                    ->update(['category' => $spec['category']]);
            }
        }

        Schema::dropIfExists('sbn_taggables');
        Schema::dropIfExists('sbn_tags');
    }
};
