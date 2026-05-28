<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
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

        $messages = $channel->messages()
            ->with('user:id,name')
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn ($m) => [
                'id'           => $m->id,
                'user_id'      => $m->user_id,
                'user_name'    => $m->user?->name,
                'body'         => $m->body,
                'created_at'   => $m->created_at?->toIso8601String(),
                'edited_at'    => $m->edited_at?->toIso8601String(),
                'deleted_at'   => $m->deleted_at?->toIso8601String(),
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
