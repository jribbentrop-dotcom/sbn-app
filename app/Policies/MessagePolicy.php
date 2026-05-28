<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    public function viewConversation(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()->where('users.id', $user->id)->exists();
    }

    public function createInConversation(User $user, Conversation $conversation): bool
    {
        if (!$this->viewConversation($user, $conversation)) {
            return false;
        }

        if ($conversation->read_only && !$user->isInstructor()) {
            return false;
        }

        return true;
    }

    public function createDmTo(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return false;
        }

        // v1 policy: one end of every DM must be the instructor.
        return $user->isInstructor() || $target->isInstructor();
    }

    public function delete(User $user, Message $message): bool
    {
        return $user->id === $message->user_id || $user->isInstructor();
    }

    public function moderate(User $user, Conversation $conversation): bool
    {
        return $user->isInstructor();
    }
}
