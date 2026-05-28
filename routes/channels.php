<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversations.{id}', function (User $user, int $id) {
    $conv = Conversation::find($id);
    if (!$conv) {
        return false;
    }
    return $conv->participants()->where('users.id', $user->id)->exists();
});

Broadcast::channel('users.{id}', function (User $user, int $id) {
    return (int) $user->id === (int) $id;
});
