<?php

namespace App\Http\Controllers\Account;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use App\Policies\MessagePolicy;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->isInstructor()) {
            return redirect()->route('admin.messages.index');
        }

        $conversations = $user->conversations()
            ->where('conversations.type', Conversation::TYPE_DM)
            ->orderByDesc('last_message_at')
            ->with(['participants' => fn ($q) => $q->where('users.id', '!=', $user->id)])
            ->get()
            ->map(fn (Conversation $c) => $this->conversationRow($c, $user->id));

        return Inertia::render('Account/Messages/Index', [
            'conversations'        => $conversations,
            'activeConversationId' => null,
            'messages'             => [],
            'instructor'           => $this->instructorContact($user),
        ]);
    }

    public function show(Request $request, Conversation $conversation)
    {
        $user = $request->user();
        if ($user->isInstructor()) {
            return redirect()->route('admin.messages.index', ['conversation' => $conversation->id]);
        }
        abort_unless(app(MessagePolicy::class)->viewConversation($user, $conversation), 403);

        $user->conversations()
            ->updateExistingPivot($conversation->id, ['last_read_at' => now()]);
        AccountService::invalidateUnread($user->id);

        $conversations = $user->conversations()
            ->where('conversations.type', Conversation::TYPE_DM)
            ->orderByDesc('last_message_at')
            ->with(['participants' => fn ($q) => $q->where('users.id', '!=', $user->id)])
            ->get()
            ->map(fn (Conversation $c) => $this->conversationRow($c, $user->id));

        $messages = $this->fetchMessages($conversation);

        return Inertia::render('Account/Messages/Index', [
            'conversations'        => $conversations,
            'activeConversationId' => $conversation->id,
            'messages'             => $messages,
            'instructor'           => $this->instructorContact($user),
        ]);
    }

    private function instructorContact(User $user): ?array
    {
        if ($user->is_instructor) {
            return null;
        }
        $instructor = User::where('is_instructor', true)->first();
        return $instructor ? [
            'id'   => $instructor->id,
            'name' => $instructor->name,
        ] : null;
    }

    public function fetch(Request $request, Conversation $conversation)
    {
        $user = $request->user();
        abort_unless(app(MessagePolicy::class)->viewConversation($user, $conversation), 403);

        $after = (int) $request->query('after', 0);
        return response()->json([
            'messages' => $this->fetchMessages($conversation, $after),
        ]);
    }

    public function store(Request $request, Conversation $conversation)
    {
        $user = $request->user();
        abort_unless(app(MessagePolicy::class)->createInConversation($user, $conversation), 403);

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

        if ($request->wantsJson()) {
            return response()->json(['id' => $message->id]);
        }

        return back();
    }

    public function destroy(Request $request, Conversation $conversation, Message $message)
    {
        $user = $request->user();
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);
        abort_unless(app(MessagePolicy::class)->delete($user, $message), 403);

        $message->delete();

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    public function markRead(Request $request, Conversation $conversation)
    {
        $user = $request->user();
        abort_unless(app(MessagePolicy::class)->viewConversation($user, $conversation), 403);

        $user->conversations()
            ->updateExistingPivot($conversation->id, ['last_read_at' => now()]);
        AccountService::invalidateUnread($user->id);

        return response()->json(['ok' => true]);
    }

    public function startDm(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'different:' . $user->id],
        ]);

        $target = User::findOrFail($data['recipient_id']);
        abort_unless(
            $user->id !== $target->id && ($user->isInstructor() || $target->isInstructor()),
            403,
            'DMs are only allowed between customers and the instructor.'
        );

        $existing = Conversation::query()
            ->where('type', Conversation::TYPE_DM)
            ->whereHas('participants', fn ($q) => $q->where('users.id', $user->id))
            ->whereHas('participants', fn ($q) => $q->where('users.id', $target->id))
            ->first();

        if ($existing) {
            return redirect()->route('account.messages.show', $existing->id);
        }

        $conversation = Conversation::create(['type' => Conversation::TYPE_DM]);
        $conversation->participants()->attach([
            $user->id   => ['joined_at' => now()],
            $target->id => ['joined_at' => now()],
        ]);

        return redirect()->route('account.messages.show', $conversation->id);
    }

    private function fetchMessages(Conversation $conversation, int $afterId = 0): array
    {
        return Message::withTrashed()
            ->with('user:id,name')
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', $afterId)
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
    }

    private function conversationRow(Conversation $c, int $viewerId): array
    {
        $other = $c->participants->firstWhere('id', '!=', $viewerId);
        return [
            'id'              => $c->id,
            'type'            => $c->type,
            'title'           => $c->title ?? ($other?->name ?? 'Direct message'),
            'other_user_id'   => $other?->id,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ];
    }
}
