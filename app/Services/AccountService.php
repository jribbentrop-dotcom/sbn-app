<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public static function unreadCountFor(User $user): int
    {
        return (int) Cache::remember("account:unread:{$user->id}", 60, function () use ($user) {
            return DB::table('messages')
                ->join('conversation_participants as cp', 'cp.conversation_id', '=', 'messages.conversation_id')
                ->where('cp.user_id', $user->id)
                ->whereNull('messages.deleted_at')
                ->where('messages.user_id', '!=', $user->id)
                ->where(function ($q) {
                    $q->whereNull('cp.last_read_at')
                      ->orWhereColumn('messages.created_at', '>', 'cp.last_read_at');
                })
                ->count();
        });
    }

    public static function invalidateUnread(int $userId): void
    {
        Cache::forget("account:unread:{$userId}");
    }
}
