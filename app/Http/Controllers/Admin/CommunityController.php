<?php

namespace App\Http\Controllers\Admin;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CommunityMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AccountService;
use Illuminate\Http\Request;

class CommunityController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $channel = $this->channel();

        $this->ensureParticipant($channel, $user->id);

        $user->conversations()->updateExistingPivot($channel->id, ['last_read_at' => now()]);
        AccountService::invalidateUnread($user->id);

        $messages = Message::withTrashed()
            ->with('user:id,name')
            ->where('conversation_id', $channel->id)
            ->orderBy('id')
            ->limit(500)
            ->get();

        return view('admin.community.show', [
            'channel'       => $channel,
            'messages'      => $messages,
            'currentUserId' => $user->id,
        ]);
    }

    public function store(CommunityMessageRequest $request)
    {
        $user = $request->user();
        $channel = $this->channel();

        $data = $request->validated();

        $message = Message::create([
            'conversation_id' => $channel->id,
            'user_id'         => $user->id,
            'body'            => $data['body'],
        ]);

        $channel->forceFill(['last_message_at' => now()])->save();
        broadcast(new MessageSent($message))->toOthers();

        return back();
    }

    public function toggleReadOnly(Request $request)
    {
        $channel = $this->channel();
        $channel->read_only = !$channel->read_only;
        $channel->save();
        return back();
    }

    public function destroyMessage(Request $request, Message $message)
    {
        $channel = $this->channel();
        abort_unless((int) $message->conversation_id === (int) $channel->id, 404);
        $message->delete();
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
        if (!$channel->participants()->where('users.id', $userId)->exists()) {
            $channel->participants()->attach($userId, ['joined_at' => now()]);
        }
    }
}
