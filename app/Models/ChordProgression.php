<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;

class ChordProgression extends Model
{
    protected $table = 'sbn_chord_progressions';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'slug',
        'category',
        'numerals',
        'alt_numerals',
        'description',
        'tags',
        'tonality',
        'match_mode',
        'sort_order',
        'featured',
        'video_snippets',
        'difficulty',
    ];

    protected $casts = [
        'sort_order'     => 'integer',
        'featured'       => 'boolean',
        'difficulty'     => 'integer',
        'alt_numerals'   => 'array',
        'video_snippets' => 'array',
    ];

    /* ── Categories ─────────────────────────────────────────── */

    public const CATEGORIES = [
        'jazz', 'bossa-nova', 'classical', 'pop',
    ];

    public const CATEGORY_LABELS = [
        'jazz'      => 'Jazz',
        'bossa-nova'=> 'Bossa Nova',
        'classical' => 'Classical',
        'pop'       => 'Pop',
    ];

    public const CATEGORY_COLORS = [
        'jazz'      => 'var(--clr-style-jazz)',
        'bossa-nova'=> 'var(--clr-style-bossa)',
        'classical' => 'var(--clr-style-classical)',
        'pop'       => 'var(--clr-style-pop)',
    ];

    /* ── Preset Tags ────────────────────────────────────────── */

    public const PRESET_TAGS = [
        'ascending bass',
        'backdoor dominant',
        'blues',
        'bossa nova',
        'cadence',
        'chromatic',
        'coltrane changes',
        'cycle of fifths',
        'deceptive cadence',
        'descending bass',
        'diminished',
        'half cadence',
        'minor subdominant',
        'modal interchange',
        'pedal point',
        'rhythm changes',
        'secondary dominant',
        'tritone substitution',
        'turnaround',
    ];

    /* ── Relations ─────────────────────────────────────────── */

    public function tags(): MorphToMany
    {
        return $this->morphToMany(SbnTag::class, 'taggable', 'sbn_taggables', 'taggable_id', 'tag_id');
    }

    /** Skill nodes this progression helps build (reverse of SkillNode::chordProgressions). */
    public function skillNodes(): MorphToMany
    {
        return $this->morphToMany(SkillNode::class, 'content', 'sbn_skill_node_content', 'content_id', 'skill_node_id');
    }

    /* ── Scopes ─────────────────────────────────────────────── */

    public function scopeCategory($query, $category)
    {
        if ($category) {
            return $query->where('category', $category);
        }
        return $query;
    }

    public function scopeDifficulty($query, int $level)
    {
        return $query->where('difficulty', $level);
    }

    public function scopeSearch($query, $term)
    {
        if ($term) {
            $like = '%' . $term . '%';
            return $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                  ->orWhere('numerals', 'like', $like)
                  ->orWhere('description', 'like', $like)
                  ->orWhere('tags', 'like', $like);
            });
        }
        return $query;
    }

    /* ── Accessors ──────────────────────────────────────────── */

    /**
     * Get numerals formatted for display (comma → en-dash).
     */
    public function getNumeralsDisplayAttribute(): string
    {
        return str_replace(',', ' – ', $this->numerals);
    }

    /**
     * Get tags as array.
     */
    public function getTagsArrayAttribute(): array
    {
        if (empty($this->tags)) return [];
        return array_filter(array_map('trim', explode(',', $this->tags)));
    }

    /**
     * Get category color.
     */
    public function getCategoryColorAttribute(): string
    {
        return self::CATEGORY_COLORS[$this->category] ?? 'var(--clr-style-general)';
    }

    /* ── Query Helpers ──────────────────────────────────────── */

    /**
     * Get all progressions with their distinct song counts (leadsheets).
     */
    public static function withSongCounts($category = null, $search = null)
    {
        $query = self::query()
            ->select('sbn_chord_progressions.*')
            ->selectRaw('COUNT(DISTINCT o.leadsheet_id) as song_count')
            ->leftJoin('sbn_progression_occurrences as o', 'sbn_chord_progressions.id', '=', 'o.progression_id')
            ->groupBy('sbn_chord_progressions.id')
            ->category($category)
            ->search($search)
            ->orderBy('sort_order')
            ->orderBy('category')
            ->orderBy('name');

        return $query->get();
    }

    /**
     * Get stats for the dashboard/reprocess area.
     */
    public static function getStats(): array
    {
        return [
            'total_progressions'      => self::count(),
            'total_occurrences'       => DB::table('sbn_progression_occurrences')->count(),
            'leadsheets_with_matches' => DB::table('sbn_progression_occurrences')->distinct('leadsheet_id')->count('leadsheet_id'),
            'total_leadsheets'        => DB::table('sbn_leadsheets')->count(),
            'most_common'             => DB::table('sbn_chord_progressions as p')
                ->join('sbn_progression_occurrences as o', 'p.id', '=', 'o.progression_id')
                ->select('p.id', 'p.name', 'p.category', 'p.numerals')
                ->selectRaw('COUNT(o.id) as occurrence_count')
                ->groupBy('p.id', 'p.name', 'p.category', 'p.numerals')
                ->orderByDesc('occurrence_count')
                ->limit(10)
                ->get(),
        ];
    }

    /**
     * Get all occurrences grouped by leadsheet, for the occurrences tab.
     * Each leadsheet group contains its occurrence rows with progression data.
     */
    public static function getOccurrencesGrouped($filterProgId = null, $filterLeadsheetId = null): array
    {
        $query = DB::table('sbn_progression_occurrences as o')
            ->join('sbn_chord_progressions as p', 'p.id', '=', 'o.progression_id')
            ->join('sbn_leadsheets as l', 'l.id', '=', 'o.leadsheet_id')
            ->select(
                'o.*',
                'p.name as prog_name',
                'p.numerals',
                'p.category',
                'l.title as leadsheet_title',
                'l.song_key'
            )
            ->orderBy('l.title')
            ->orderBy('o.section_id')
            ->orderBy('o.start_measure');

        if ($filterProgId) {
            $query->where('o.progression_id', $filterProgId);
        }
        if ($filterLeadsheetId) {
            $query->where('o.leadsheet_id', $filterLeadsheetId);
        }

        $rows = $query->get();

        // Group by leadsheet
        $grouped = [];
        foreach ($rows as $row) {
            $key = $row->leadsheet_id;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'leadsheet_id'    => $row->leadsheet_id,
                    'leadsheet_title' => $row->leadsheet_title,
                    'song_key'        => $row->song_key ?? '',
                    'occurrences'     => [],
                ];
            }
            $grouped[$key]['occurrences'][] = $row;
        }

        return array_values($grouped);
    }

    /**
     * Get filter options for the occurrences tab.
     */
    public static function getOccurrenceFilters(): array
    {
        return [
            'progressions' => DB::table('sbn_chord_progressions')
                ->select('id', 'name')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'leadsheets' => DB::table('sbn_progression_occurrences as o')
                ->join('sbn_leadsheets as l', 'l.id', '=', 'o.leadsheet_id')
                ->select('o.leadsheet_id', 'l.title')
                ->distinct()
                ->orderBy('l.title')
                ->get(),
        ];
    }

    /**
     * Get distinct categories actually used in the table.
     */
    public static function usedCategories(): array
    {
        return self::distinct()->pluck('category')->sort()->values()->toArray();
    }
}
