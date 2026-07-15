<script setup lang="ts">
import { computed, ref, toRef } from 'vue';
import { router, usePage, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AccountLayout from '@/Layouts/AccountLayout.vue';
import ConversationList, { type ConversationRow } from '@/Components/Chat/ConversationList.vue';
import MessageList from '@/Components/Chat/MessageList.vue';
import MessageComposer from '@/Components/Chat/MessageComposer.vue';
import { useChat, type ChatMessage } from '@/composables/useChat';

defineOptions({ layout: [PublicLayout, AccountLayout] });

const props = defineProps<{
    conversations: ConversationRow[];
    activeConversationId: number | null;
    messages: ChatMessage[];
    instructor: { id: number; name: string } | null;
}>();

const page = usePage();
const currentUserId = computed<number>(() => (page.props.auth?.user?.id as number) ?? 0);

const activeId = toRef(props, 'activeConversationId');
const initial = ref<ChatMessage[]>(props.messages);

const { messages, sending, error, send } = useChat({
    conversationId: activeId,
    initialMessages: initial,
});

const hasDmWithInstructor = computed(() =>
    props.instructor !== null &&
    props.conversations.some((c) => c.other_user_id === props.instructor!.id)
);

function onSend(body: string) {
    send(body);
}

function startDmWithInstructor() {
    if (!props.instructor) return;
    router.post('/account/messages/start-dm', { recipient_id: props.instructor.id });
}
</script>

<template>
    <Head><title>Messages | Soul Bossa Nova</title></Head>
    <div class="sbn-page sbn-page-detail">
            <header class="sbn-account-pageheader">
                <div class="sbn-account-pageheader-row">
                    <div>
                        <h1>Messages</h1>
                        <p class="sbn-account-subtle">Direct messages with the instructor.</p>
                    </div>
                    <button
                        v-if="instructor && !hasDmWithInstructor"
                        type="button"
                        class="sbn-btn sbn-btn-primary"
                        @click="startDmWithInstructor"
                    >
                        Message {{ instructor.name }}
                    </button>
                </div>
            </header>

            <div class="sbn-chat-shell">
                <ConversationList
                    :conversations="conversations"
                    :active-id="activeConversationId"
                />
                <div class="sbn-chat-pane">
                    <template v-if="activeConversationId">
                        <MessageList :messages="messages" :current-user-id="currentUserId" />
                        <MessageComposer :sending="sending" :error="error" @send="onSend" />
                    </template>
                    <div v-else class="sbn-chat-empty-pane">
                        <p v-if="instructor">Pick a conversation, or start a new one with the instructor above.</p>
                        <p v-else>Pick a conversation to begin.</p>
                    </div>
                </div>
            </div>
    </div>
</template>
