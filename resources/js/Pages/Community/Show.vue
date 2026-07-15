<script setup lang="ts">
import { computed, ref, toRef } from 'vue';
import { router, usePage, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AccountLayout from '@/Layouts/AccountLayout.vue';
import MessageList from '@/Components/Chat/MessageList.vue';
import MessageComposer from '@/Components/Chat/MessageComposer.vue';
import { useChat, type ChatMessage } from '@/composables/useChat';

defineOptions({ layout: [PublicLayout, AccountLayout] });

const props = defineProps<{
    channel: { id: number; title: string; read_only: boolean };
    messages: ChatMessage[];
    isInstructor: boolean;
    muted: boolean;
}>();

const page = usePage();
const currentUserId = computed<number>(() => (page.props.auth?.user?.id as number) ?? 0);

const channelId = ref<number | null>(props.channel.id);
const initial = ref<ChatMessage[]>(props.messages);

const { messages, sending, error, send } = useChat({
    conversationId: channelId,
    initialMessages: initial,
    baseUrl: () => '/community',
});

function onSend(body: string) {
    send(body);
}

function canDelete(m: ChatMessage): boolean {
    return props.isInstructor || m.user_id === currentUserId.value;
}

function onDelete(id: number) {
    router.delete(`/community/messages/${id}`, { preserveScroll: true });
}

function toggleReadOnly() {
    router.post('/community/read-only', {}, { preserveScroll: true });
}

function toggleMute() {
    router.post('/community/mute', {}, { preserveScroll: true });
}

const canPost = computed(() => !props.channel.read_only || props.isInstructor);
</script>

<template>
    <Head><title>{{ channel.title }} | Community | Soul Bossa Nova</title></Head>
    <div class="sbn-page sbn-page-detail">
            <header class="sbn-account-pageheader">
                <h1>{{ channel.title }}</h1>
                <p class="sbn-account-subtle">
                    The community channel. Be kind.
                    <span v-if="channel.read_only" class="sbn-community-banner">Announcements only — only the instructor can post.</span>
                </p>
            </header>

            <div class="sbn-community-bar">
                <button type="button" class="sbn-account-section-link" @click="toggleMute">
                    {{ muted ? 'Unmute notifications' : 'Mute notifications' }}
                </button>
                <button
                    v-if="isInstructor"
                    type="button"
                    class="sbn-account-section-link"
                    @click="toggleReadOnly"
                >
                    {{ channel.read_only ? 'Open to everyone' : 'Set announcements-only' }}
                </button>
            </div>

            <div class="sbn-chat-shell sbn-chat-shell--single">
                <div class="sbn-chat-pane">
                    <MessageList
                        :messages="messages"
                        :current-user-id="currentUserId"
                        :show-author="true"
                        :can-delete="canDelete"
                        @delete="onDelete"
                    />
                    <MessageComposer
                        v-if="canPost"
                        :sending="sending"
                        :error="error"
                        @send="onSend"
                    />
                    <div v-else class="sbn-chat-readonly">Announcements only.</div>
                </div>
            </div>
    </div>
</template>
