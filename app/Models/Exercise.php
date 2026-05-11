<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    protected $table = 'sbn_exercises';

    protected $fillable = [
        'slug',
        'title',
        'composer',
        'key_center',
        'time_sig',
        'bpm_default',
        'rhythm',
        'measure_count',
        'course_id',
        'type',
        'content_json',
        'shortcode_content',
        'tab_xml',
        'description',
        'harmony_notes',
        'form_notes',
        'voicing_notes',
        'popularity',
    ];

    protected $casts = [
        'content_json' => 'array',
        'bpm_default' => 'integer',
        'measure_count' => 'integer',
        'course_id' => 'integer',
        'popularity' => 'integer',
    ];
}
