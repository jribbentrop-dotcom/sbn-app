<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Policies\MessagePolicy;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CommunityController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        if ($user->isInstructor()) {
            return redirect()->route('admin.community.show');
        }
        $channel = $this->channel();

        $this->ensureParticipant($channel, $user->id);

        $user->conversations()
            ->updateExistingPivot($channel->id, ['last_read_at' => now()]);
        AccountService::invalidateUnread($user->id);

        $messages = Message::withTrashed()
            ->with('user:id,name')
            ->where('conversation_id', $channel->id)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (Message $m) => [
                'id'         => $m->id,
                'user_id'    => $m->user_id,
                'user_name'  => $m->user?->name,
                'body'       => $m->trashed() ? '' : $m->body,
                'created_at' => $m->created_at?->toIso8601String(),
                'edited_at'  => $m->edited_at?->toIso8601String(),
                'deleted_at' => $m->deleted_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Community/Show', [
            'channel' => [
                'id'        => $channel->id,
                'title'     => $channel->title,
                'read_only' => (bool) $channel->read_only,
            ],
            'messages'     => $messages,
            'isInstructor' => $user->isInstructor(),
            'muted'        => (bool) ($user->conversations()
                ->where('conversations.id', $channel->id)
                ->first()?->pivot?->muted ?? false),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $channel = $this->channel();
        abort_unless(app(MessagePolicy::class)->createInConversation($user, $channel), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $message = Message::create([
            'conversation_id' => $channel->id,
            'user_id'         => $user->id,
            'body'            => $data['body'],
        ]);

        $channel->forceFill(['last_message_at' => now()])->save();
        broadcast(new MessageSent($message))->toOthers();

        if ($request->wantsJson()) {
            return response()->json(['id' => $message->id]);
        }

        return back();
    }

    public function fetch(Request $request)
    {
        $user = $request->user();
        $channel = $this->channel();
        abort_unless(app(MessagePolicy::class)->viewConversation($user, $channel), 403);

        $after = (int) $request->query('after', 0);

        $messages = Message::withTrashed()
            ->with('user:id,name')
            ->where('conversation_id', $channel->id)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (Message $m) => [
                'id'         => $m->id,
                'user_id'    => $m->user_id,
                'user_name'  => $m->user?->name,
                'body'       => $m->trashed() ? '' : $m->body,
                'created_at' => $m->created_at?->toIso8601String(),
                'edited_at'  => $m->edited_at?->toIso8601String(),
                'deleted_at' => $m->deleted_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['messages' => $messages]);
    }

    public function markRead(Request $request)
    {
        $user = $request->user();
        $channel = $this->channel();
        $this->ensureParticipant($channel, $user->id);

        $user->conversations()
            ->updateExistingPivot($channel->id, ['last_read_at' => now()]);
        AccountService::invalidateUnread($user->id);

        return response()->json(['ok' => true]);
    }

    public function destroyMessage(Request $request, Message $message)
    {
        $user = $request->user();
        $channel = $this->channel();
        abort_unless((int) $message->conversation_id === (int) $channel->id, 404);
        abort_unless(app(MessagePolicy::class)->delete($user, $message), 403);

        $message->delete();

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    public function toggleReadOnly(Request $request)
    {
        $user = $request->user();
        $channel = $this->channel();
        abort_unless(app(MessagePolicy::class)->moderate($user, $channel), 403);

        $channel->read_only = !$channel->read_only;
        $channel->save();

        return back();
    }

    public function toggleMute(Request $request)
    {
        $user = $request->user();
        $channel = $this->channel();

        $current = $user->conversations()
            ->where('conversations.id', $channel->id)
            ->first()?->pivot?->muted ?? false;

        $user->conversations()
            ->updateExistingPivot($channel->id, ['muted' => !$current]);

        return back();
    }

    private function channel(): Conversation
    {
        return Conversation::firstOrCreate(
            ['type' => Conversation::TYPE_CHANNEL],
            ['title' => 'The Practice Room']
        );
    }

    private function ensureParticipant(Conversation $channel, int $userId): void
    {
        $exists = $channel->participants()->where('users.id', $userId)->exists();
        if (!$exists) {
            $channel->participants()->attach($userId, ['joined_at' => now()]);
        }
    }
}
