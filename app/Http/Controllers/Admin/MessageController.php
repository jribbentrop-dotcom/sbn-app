<?php

namespace App\Http\Controllers\Admin;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Notifications\NewMessageNotification;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $conversations = $user->conversations()
            ->where('conversations.type', Conversation::TYPE_DM)
            ->orderByDesc('last_message_at')
            ->with(['participants' => fn ($q) => $q->where('users.id', '!=', $user->id)])
            ->get();

        $activeId = (int) $request->query('conversation', 0) ?: ($conversations->first()?->id ?? 0);

        $active = $activeId ? $conversations->firstWhere('id', $activeId) : null;
        $messages = [];

        if ($active) {
            $user->conversations()->updateExistingPivot($active->id, ['last_read_at' => now()]);
            AccountService::invalidateUnread($user->id);

            $messages = Message::withTrashed()
                ->with('user:id,name')
                ->where('conversation_id', $active->id)
                ->orderBy('id')
                ->limit(500)
                ->get();
        }

        return view('admin.messages.index', [
            'conversations' => $conversations,
            'active'        => $active,
            'messages'      => $messages,
            'currentUserId' => $user->id,
        ]);
    }

    public function store(Request $request, Conversation $conversation)
    {
        $user = $request->user();
        abort_unless(
            $conversation->participants()->where('users.id', $user->id)->exists(),
            403
        );

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id'         => $user->id,
            'body'            => $data['body'],
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        broadcast(new MessageSent($message))->toOthers();

        foreach ($conversation->participants()->where('users.id', '!=', $user->id)->get() as $recipient) {
            AccountService::invalidateUnread($recipient->id);

            $pivot = DB::table('conversation_participants')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $recipient->id)
                ->first();

            $muted = (bool) ($pivot->muted ?? false);
            $lastRead = $pivot->last_read_at ? \Carbon\Carbon::parse($pivot->last_read_at) : null;
            $idleEnough = $lastRead === null || $lastRead->lt(now()->subMinutes(10));

            if (!$muted && $idleEnough) {
                $recipient->notify(new NewMessageNotification($message));
            }
        }

        return redirect()->route('admin.messages.index', ['conversation' => $conversation->id]);
    }

    public function destroy(Request $request, Conversation $conversation, Message $message)
    {
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);
        $message->delete();
        return back();
    }
}
