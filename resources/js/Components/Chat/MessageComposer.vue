<script setup lang="ts">
import { ref } from 'vue';

const props = defineProps<{
    sending: boolean;
    error: string | null;
}>();

const emit = defineEmits<{ (e: 'send', body: string): void }>();

const body = ref('');

function submit() {
    const trimmed = body.value.trim();
    if (!trimmed || props.sending) return;
    emit('send', trimmed);
    body.value = '';
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submit();
    }
}
</script>

<template>
    <form class="sbn-chat-composer" @submit.prevent="submit">
        <textarea
            v-model="body"
            class="sbn-chat-composer-input"
            rows="2"
            placeholder="Write a message…"
            @keydown="onKey"
        />
        <div class="sbn-chat-composer-bar">
            <span v-if="error" class="sbn-chat-composer-error">{{ error }}</span>
            <span v-else class="sbn-chat-composer-hint">Enter to send, Shift+Enter for newline</span>
            <button type="submit" class="sbn-btn-primary" :disabled="sending || !body.trim()">
                {{ sending ? 'Sending…' : 'Send' }}
            </button>
        </div>
    </form>
</template>
