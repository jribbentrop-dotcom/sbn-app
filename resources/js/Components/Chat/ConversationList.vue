<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export interface ConversationRow {
    id: number;
    type: 'dm' | 'channel';
    title: string;
    other_user_id: number | null;
    last_message_at: string | null;
}

defineProps<{
    conversations: ConversationRow[];
    activeId: number | null;
}>();

function formatWhen(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    const today = new Date();
    if (d.toDateString() === today.toDateString()) {
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    return d.toLocaleDateString();
}
</script>

<template>
    <div class="sbn-chat-list">
        <div v-if="conversations.length === 0" class="sbn-chat-list-empty">
            No conversations yet.
        </div>
        <Link
            v-for="c in conversations"
            :key="c.id"
            :href="`/account/messages/${c.id}`"
            class="sbn-chat-list-item"
            :class="{ 'is-active': c.id === activeId }"
            preserve-scroll
        >
            <div class="sbn-chat-list-title">{{ c.title }}</div>
            <div class="sbn-chat-list-when">{{ formatWhen(c.last_message_at) }}</div>
        </Link>
    </div>
</template>
