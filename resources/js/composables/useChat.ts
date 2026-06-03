import { ref, onBeforeUnmount, watch, type Ref } from 'vue';

export interface ChatMessage {
    id: number;
    user_id: number;
    user_name?: string | null;
    body: string;
    created_at: string | null;
    edited_at: string | null;
    deleted_at?: string | null;
}

declare global {
    interface Window {
        Echo?: {
            private(channel: string): {
                listen(event: string, cb: (payload: unknown) => void): void;
                stopListening(event: string): void;
            };
            leave(channel: string): void;
            connector?: { pusher?: { connection?: { state?: string } } };
        };
    }
}

interface UseChatOptions {
    conversationId: Ref<number | null>;
    initialMessages: Ref<ChatMessage[]>;
    pollIntervalMs?: number;
    /** Override the base path; defaults to /account/messages/{id} */
    baseUrl?: (id: number) => string;
}

function csrfToken(): string {
    const el = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    return el?.content ?? '';
}

async function jsonFetch(url: string, init: RequestInit = {}): Promise<Response> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...((init.headers as Record<string, string>) ?? {}),
    };
    if (init.method && init.method !== 'GET') {
        headers['X-CSRF-TOKEN'] = csrfToken();
        if (init.body && !(init.body instanceof FormData) && !headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
        }
    }
    return fetch(url, { credentials: 'same-origin', ...init, headers });
}

export function useChat({ conversationId, initialMessages, pollIntervalMs = 8000, baseUrl }: UseChatOptions) {
    const resolveBase = (id: number) => baseUrl ? baseUrl(id) : `/account/messages/${id}`;
    const messages = ref<ChatMessage[]>([...initialMessages.value]);
    const sending = ref(false);
    const error = ref<string | null>(null);

    let pollTimer: number | null = null;
    let echoChannelName: string | null = null;

    function lastId(): number {
        return messages.value.length ? messages.value[messages.value.length - 1].id : 0;
    }

    async function pull() {
        if (!conversationId.value) return;
        try {
            const res = await jsonFetch(`${resolveBase(conversationId.value)}/fetch?after=${lastId()}`);
            if (!res.ok) return;
            const data = await res.json();
            const fresh = (data?.messages ?? []) as ChatMessage[];
            for (const m of fresh) {
                const existingIdx = messages.value.findIndex((x) => x.id === m.id);
                if (existingIdx >= 0) {
                    messages.value[existingIdx] = m;
                } else {
                    messages.value.push(m);
                }
            }
        } catch {
            // Polling keeps trying.
        }
    }

    function startPolling() {
        if (pollTimer !== null) return;
        pollTimer = window.setInterval(pull, pollIntervalMs);
    }

    function stopPolling() {
        if (pollTimer !== null) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function attachEcho(id: number) {
        const echo = window.Echo;
        if (!echo) return false;

        echoChannelName = `conversations.${id}`;
        echo.private(echoChannelName).listen('.MessageSent', () => {
            pull();
        });
        return true;
    }

    function detachEcho() {
        if (window.Echo && echoChannelName) {
            window.Echo.leave(`private-${echoChannelName}`);
            echoChannelName = null;
        }
    }

    function bind(id: number) {
        const echoAttached = attachEcho(id);
        startPolling();
        if (echoAttached) {
            stopPolling();
            pollTimer = window.setInterval(pull, 60_000);
        }
        markRead();
    }

    function unbind() {
        detachEcho();
        stopPolling();
    }

    async function send(body: string): Promise<boolean> {
        if (!conversationId.value || !body.trim()) return false;
        sending.value = true;
        error.value = null;
        try {
            const res = await jsonFetch(`${resolveBase(conversationId.value)}`, {
                method: 'POST',
                body: JSON.stringify({ body }),
            });
            if (!res.ok) {
                error.value = `Failed to send (HTTP ${res.status})`;
                return false;
            }
            await pull();
            return true;
        } catch (e: unknown) {
            error.value = 'Failed to send';
            return false;
        } finally {
            sending.value = false;
        }
    }

    function markRead() {
        if (!conversationId.value) return;
        jsonFetch(`${resolveBase(conversationId.value)}/read`, { method: 'PATCH' }).catch(() => {});
    }

    watch(
        conversationId,
        (id, prev) => {
            if (prev) {
                unbind();
            }
            messages.value = [...initialMessages.value];
            if (id) {
                bind(id);
            }
        },
        { immediate: true }
    );

    onBeforeUnmount(() => {
        unbind();
    });

    return { messages, sending, error, send, markRead };
}
