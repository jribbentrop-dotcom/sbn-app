<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import MessageBubble from './MessageBubble.vue';
import type { ChatMessage } from '@/composables/useChat';

const props = defineProps<{
    messages: ChatMessage[];
    currentUserId: number;
    showAuthor?: boolean;
    canDelete?: (m: ChatMessage) => boolean;
}>();

const emit = defineEmits<{ (e: 'delete', id: number): void }>();

const scroller = ref<HTMLElement | null>(null);

watch(
    () => props.messages.length,
    async () => {
        await nextTick();
        if (scroller.value) {
            scroller.value.scrollTop = scroller.value.scrollHeight;
        }
    },
    { immediate: true }
);
</script>

<template>
    <div ref="scroller" class="sbn-chat-scroller">
        <div v-if="messages.length === 0" class="sbn-chat-empty">
            Say hello.
        </div>
        <MessageBubble
            v-for="m in messages"
            :key="m.id"
            :message="m"
            :mine="m.user_id === currentUserId"
            :show-author="showAuthor"
            :can-delete="canDelete ? canDelete(m) : false"
            @delete="(id) => emit('delete', id)"
        />
    </div>
</template>
