<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ConversationParticipant extends Pivot
{
    protected $table = 'conversation_participants';

    public $incrementing = true;

    protected $casts = [
        'joined_at'    => 'datetime',
        'last_read_at' => 'datetime',
        'muted'        => 'boolean',
    ];
}
