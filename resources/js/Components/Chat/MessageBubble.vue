<script setup lang="ts">
import { computed } from 'vue';
import type { ChatMessage } from '@/composables/useChat';

const props = defineProps<{
    message: ChatMessage;
    mine: boolean;
    showAuthor?: boolean;
    canDelete?: boolean;
}>();

const emit = defineEmits<{ (e: 'delete', id: number): void }>();

const isDeleted = computed(() => !!props.message.deleted_at);

function formatTime(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function onDelete() {
    if (confirm('Delete this message?')) {
        emit('delete', props.message.id);
    }
}
</script>

<template>
    <div class="sbn-chat-bubble-row" :class="{ 'is-mine': mine }">
        <div class="sbn-chat-bubble" :class="{ 'is-deleted': isDeleted }">
            <div v-if="showAuthor && !mine && message.user_name" class="sbn-chat-bubble-author">
                {{ message.user_name }}
            </div>
            <div class="sbn-chat-bubble-body">
                <em v-if="isDeleted" class="sbn-chat-bubble-deleted">message removed</em>
                <template v-else>{{ message.body }}</template>
            </div>
            <div class="sbn-chat-bubble-time">
                {{ formatTime(message.created_at) }}
                <button
                    v-if="canDelete && !isDeleted"
                    type="button"
                    class="sbn-chat-bubble-delete"
                    @click="onDelete"
                >
                    delete
                </button>
            </div>
        </div>
    </div>
</template>
