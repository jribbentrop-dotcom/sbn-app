<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class RhythmPattern extends Model
{
    /**
     * The table associated with the model.
     * (Matches the imported WordPress table — no wp_ prefix.)
     */
    protected $table = 'sbn_rhythm_patterns';

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'intro',
        'details',
        'category',
        'time_signature',
        'beats',
        'grid_type',
        'rhythm_pattern',
        'thumb_pattern',
        'picking_mode',
        'finger_index',
        'finger_middle',
        'finger_ring',
        'default_bpm',
        'sound',
        'perc_top',
        'perc_bass',
        'mp3_file',
        'video_snippets',
        'is_default',
        'sort_order',
        'difficulty',
    ];

    /**
     * Attribute defaults for new patterns.
     */
    protected $attributes = [
        'category'       => 'pop',
        'time_signature' => '4/4',
        'beats'          => 8,
        'grid_type'      => 'sixteenth',
        'rhythm_pattern' => '........',
        'thumb_pattern'  => '',
        'default_bpm'    => 120,
        'sound'          => 'guitar',
        'perc_top'       => 'none',
        'perc_bass'      => 'none',
        'mp3_file'       => '',
        'is_default'     => 0,
        'sort_order'     => 0,
        'difficulty'     => null,
        'picking_mode'   => false,
        'finger_index'   => null,
        'finger_middle'  => null,
        'finger_ring'    => null,
    ];

    /**
     * Cast columns to proper PHP types.
     */
    protected $casts = [
        'beats'          => 'integer',
        'default_bpm'    => 'integer',
        'is_default'     => 'boolean',
        'picking_mode'   => 'boolean',
        'sort_order'     => 'integer',
        'difficulty'     => 'integer',
        'video_snippets' => 'array',
    ];

    // ──────────────────────────────────────────
    // Constants
    // ──────────────────────────────────────────

    public const CATEGORIES = [
        'jazz', 'bossa-nova', 'classical', 'pop',
    ];

    public const CATEGORY_LABELS = [
        'jazz'      => 'Jazz',
        'bossa-nova'=> 'Bossa Nova',
        'classical' => 'Classical',
        'pop'       => 'Pop',
    ];

    public const PRESET_TAGS = [
        'blues', 'modal', 'latin', 'cuban', 'brazilian',
        'swing', 'afro-cuban', 'ballad', 'samba',
    ];

    // ──────────────────────────────────────────
    // Relations
    // ──────────────────────────────────────────

    public function tags(): MorphToMany
    {
        return $this->morphToMany(SbnTag::class, 'taggable', 'sbn_taggables', 'taggable_id', 'tag_id');
    }

    /** Skill nodes this pattern helps build (reverse of SkillNode::rhythmPatterns). */
    public function skillNodes(): MorphToMany
    {
        return $this->morphToMany(SkillNode::class, 'content', 'sbn_skill_node_content', 'content_id', 'skill_node_id');
    }

    // ──────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────

    public function scopeOrdered($query)
    {
        return $query->orderBy('category')->orderBy('sort_order')->orderBy('name');
    }

    public static function withSongCounts(): \Illuminate\Support\Collection
    {
        return static::query()
            ->select('sbn_rhythm_patterns.*')
            ->selectRaw('COUNT(DISTINCT ls.id) as song_count')
            ->leftJoin('sbn_leadsheets as ls', function ($join) {
                $join->on('ls.rhythm', '=', 'sbn_rhythm_patterns.slug')
                     ->where('ls.status', '=', 'publish');
            })
            ->groupBy('sbn_rhythm_patterns.id')
            ->orderBy('sbn_rhythm_patterns.category')
            ->orderBy('sbn_rhythm_patterns.sort_order')
            ->orderBy('sbn_rhythm_patterns.name')
            ->get();
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeDifficulty($query, int $level)
    {
        return $query->where('difficulty', $level);
    }

    public function scopeDefaults($query)
    {
        return $query->where('is_default', true);
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    /**
     * All distinct categories currently in the table.
     */
    public static function categories(): array
    {
        return static::query()
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->toArray();
    }

    private const CATEGORY_TO_STYLE = [
        'bossa-nova' => 'bossa-nova',
        'bossa'      => 'bossa-nova',
        'jazz'       => 'jazz',
        'classical'  => 'classical',
        'pop'        => 'pop',
    ];

    public function styleSlug(): string
    {
        return self::CATEGORY_TO_STYLE[$this->category] ?? 'pop';
    }

    /**
     * Data array formatted for the leadsheet parser / frontend player.
     */
    public function toPlayerData(): array
    {
        $data = [
            'name'          => $this->name,
            'category'      => $this->category,
            'beats'         => $this->beats,
            'gridType'      => $this->grid_type,
            'thumb'         => $this->thumb_pattern,
            'fingers'       => $this->rhythm_pattern,
            'bpm'           => $this->default_bpm,
            'timeSignature' => $this->time_signature,
            'percTop'       => $this->perc_top,
            'percBass'      => $this->perc_bass,
            'styleSlug'     => $this->category ?: 'pop',
            'demoUrl'       => $this->mp3_file ? asset('audio/rhythm-demos/' . $this->mp3_file) : null,
            'pickingMode'   => (bool) $this->picking_mode,
        ];

        if ($this->picking_mode) {
            $data['fingerIndex']  = $this->finger_index;
            $data['fingerMiddle'] = $this->finger_middle;
            $data['fingerRing']   = $this->finger_ring;
        }

        return $data;
    }

    /**
     * Slug-indexed key→value list for dropdowns / selects.
     */
    public static function forSelect(): array
    {
        return static::ordered()
            ->get(['slug', 'name', 'category'])
            ->mapWithKeys(fn ($p) => [
                $p->slug => ['name' => $p->name, 'category' => $p->category],
            ])
            ->toArray();
    }
}
