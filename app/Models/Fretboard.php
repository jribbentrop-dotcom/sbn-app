<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fretboard extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'description',
        'display_mode',
        'theme',
        'fret_count',
        'start_fret',
        'show_guide_tones',
        'show_rh_fingers',
        'voicings',
        'windows',
    ];

    protected $casts = [
        'voicings'        => 'array',
        'windows'         => 'array',
        'show_guide_tones' => 'boolean',
        'show_rh_fingers'  => 'boolean',
        'fret_count'      => 'integer',
        'start_fret'      => 'integer',
    ];

    /**
     * Return the first voicing frame, or an empty default.
     */
    public function firstVoicing(): array
    {
        $v = $this->voicings;
        if (! empty($v) && isset($v[0])) {
            return $v[0];
        }
        return ['label' => '', 'frets' => 'xxxxxx', 'fingers' => '000000', 'interval_labels' => ''];
    }
}
