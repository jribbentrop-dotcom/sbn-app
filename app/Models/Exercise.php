<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    protected $table = 'sbn_exercises';

    protected $fillable = [
        'slug',
        'title',
        'key_center',
        'time_sig',
        'bpm_default',
        'type',
        'content_json',
    ];

    protected $casts = [
        'content_json' => 'array',
        'bpm_default' => 'integer',
    ];
}
