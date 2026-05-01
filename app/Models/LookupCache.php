<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LookupCache extends Model
{
    protected $table = 'sbn_lookup_cache';

    protected $fillable = [
        'cache_key',
        'mode',
        'title',
        'analysis',
        'expires_at',
    ];

    protected $casts = [
        'analysis'   => 'array',
        'expires_at' => 'datetime',
    ];
}
