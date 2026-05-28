<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'read_only'       => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public const TYPE_DM = 'dm';
    public const TYPE_CHANNEL = 'channel';

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['joined_at', 'last_read_at', 'muted'])
            ->using(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isDm(): bool
    {
        return $this->type === self::TYPE_DM;
    }

    public function isChannel(): bool
    {
        return $this->type === self::TYPE_CHANNEL;
    }
}
