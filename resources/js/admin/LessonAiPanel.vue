<script setup lang="ts">
import { ref, nextTick } from 'vue';
import { hasSelection } from './editorSelection';

// ── Editor bridge ─────────────────────────────────────────────────────────────
// LessonEditor.vue exposes window.__sbnEditor. The panel only ever touches the
// document through these methods, so an AI reply never lands without a click.

interface EditorBridge {
    getSelection(): string;
    getContext(): string;
    insertAtCursor(html: string): void;
    replaceSelection(html: string): void;
}

function bridge(): EditorBridge | null {
    return (window as any).__sbnEditor ?? null;
}

// ── State ─────────────────────────────────────────────────────────────────────

interface ChatMessage {
    role: 'user' | 'assistant';
    text: string;       // conversational text shown in the bubble
    html?: string;      // insertable HTML the AI produced (assistant only)
}

// Canned instructions for the selection quick-action buttons. The selected
// text itself reaches the AI via the `selection` field — these just say what
// to do with it. The reply's HTML can then be applied via "Replace selection".
const QUICK_ACTIONS: { label: string; prompt: string }[] = [
    { label: 'Proofread', prompt: 'Proofread the selected passage for grammar, spelling, and clarity. Return the corrected version as HTML.' },
    { label: 'Improve',   prompt: 'Rewrite the selected passage to read more clearly and engagingly, keeping the same meaning and length. Return it as HTML.' },
    { label: 'Shorten',   prompt: 'Make the selected passage more concise without losing key information. Return it as HTML.' },
];

const open     = ref(false);
const messages = ref<ChatMessage[]>([]);
const draft    = ref('');
const loading  = ref(false);
const errorMsg = ref('');

const scrollRef = ref<HTMLElement | null>(null);
const inputRef  = ref<HTMLTextAreaElement | null>(null);

function toggle() {
    open.value = !open.value;
    // The drawer sits alongside the editor (no backdrop) — push the page
    // content left so the editor stays fully visible and interactive.
    document.body.classList.toggle('sbn-ai-drawer-open', open.value);
    if (open.value) nextTick(() => inputRef.value?.focus());
}

async function scrollToBottom() {
    await nextTick();
    if (scrollRef.value) scrollRef.value.scrollTop = scrollRef.value.scrollHeight;
}

// ── Send ──────────────────────────────────────────────────────────────────────

/**
 * Send a message. With no argument, sends the draft textarea content.
 * `preset` lets quick-action buttons send a canned instruction instead.
 */
async function send(preset?: string) {
    const text = (preset ?? draft.value).trim();
    if (!text || loading.value) return;

    const ed = bridge();
    const selection = ed?.getSelection() ?? '';
    const context   = ed?.getContext() ?? '';

    // History sent to the API = everything before this new turn.
    const history = messages.value.map(m => ({ role: m.role, text: m.text }));

    messages.value.push({ role: 'user', text });
    draft.value = '';
    loading.value = true;
    errorMsg.value = '';
    scrollToBottom();

    try {
        const res = await fetch('/admin/ai/process', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                action: 'chat',
                content: text,
                context,
                history,
                selection,
            }),
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);

        messages.value.push({
            role: 'assistant',
            text: data.reply || '(no reply)',
            html: (data.html && data.html.trim()) ? data.html : undefined,
        });
    } catch (e: any) {
        errorMsg.value = 'AI request failed. Please try again.';
    } finally {
        loading.value = false;
        scrollToBottom();
    }
}

function onKeydown(e: KeyboardEvent) {
    // Enter sends; Shift+Enter makes a newline.
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        send();
    }
}

// ── Apply to document ─────────────────────────────────────────────────────────

function insert(msg: ChatMessage) {
    if (msg.html) bridge()?.insertAtCursor(msg.html);
}

function replace(msg: ChatMessage) {
    if (msg.html) bridge()?.replaceSelection(msg.html);
}

function clearChat() {
    messages.value = [];
    errorMsg.value = '';
}
</script>

<template>
  <!-- Toggle tab — fixed to the right edge -->
  <button
    type="button"
    class="sbn-ai-toggle"
    :class="{ 'is-open': open }"
    :title="open ? 'Close AI assistant' : 'Open AI assistant'"
    @click="toggle"
  >
    <span class="sbn-ai-toggle-icon">✨</span>
    <span class="sbn-ai-toggle-label">AI</span>
  </button>

  <!-- Drawer — no backdrop: the editor stays interactive while it's open -->
  <aside class="sbn-ai-drawer" :class="{ 'is-open': open }">
    <header class="sbn-ai-header">
      <h3>✨ AI Assistant</h3>
      <div class="sbn-ai-header-actions">
        <button v-if="messages.length" type="button" class="sbn-ai-text-btn" @click="clearChat">Clear</button>
        <button type="button" class="sbn-ai-close" aria-label="Close" @click="toggle">✕</button>
      </div>
    </header>

    <div ref="scrollRef" class="sbn-ai-messages">
      <p v-if="!messages.length" class="sbn-ai-empty">
        Ask for a draft, a rewrite, or feedback. Nothing changes your lesson
        until you click <strong>Insert</strong> or <strong>Replace selection</strong>.
      </p>

      <div
        v-for="(msg, i) in messages"
        :key="i"
        class="sbn-ai-msg"
        :class="`sbn-ai-msg--${msg.role}`"
      >
        <div class="sbn-ai-bubble">{{ msg.text }}</div>

        <!-- Insertable content + apply controls (assistant only) -->
        <div v-if="msg.role === 'assistant' && msg.html" class="sbn-ai-suggestion">
          <div class="sbn-ai-suggestion-preview" v-html="msg.html" />
          <div class="sbn-ai-suggestion-actions">
            <button type="button" class="sbn-btn sbn-btn-primary sbn-btn-sm" @click="insert(msg)">
              Insert at cursor
            </button>
            <button
              type="button"
              class="sbn-btn sbn-btn-secondary sbn-btn-sm"
              :disabled="!hasSelection"
              :title="hasSelection ? 'Replace the selected text' : 'Select text in the editor first'"
              @click="replace(msg)"
            >
              Replace selection
            </button>
          </div>
        </div>
      </div>

      <div v-if="loading" class="sbn-ai-msg sbn-ai-msg--assistant">
        <div class="sbn-ai-bubble sbn-ai-bubble--loading">Thinking…</div>
      </div>
      <p v-if="errorMsg" class="sbn-ai-error">{{ errorMsg }}</p>
    </div>

    <!-- Quick actions — only meaningful when text is selected in the editor -->
    <div v-if="hasSelection" class="sbn-ai-quick">
      <span class="sbn-ai-quick-label">Selection:</span>
      <button
        v-for="qa in QUICK_ACTIONS"
        :key="qa.label"
        type="button"
        class="sbn-ai-quick-btn"
        :disabled="loading"
        @click="send(qa.prompt)"
      >{{ qa.label }}</button>
    </div>

    <footer class="sbn-ai-input">
      <textarea
        ref="inputRef"
        v-model="draft"
        rows="2"
        placeholder="Ask the AI… (Enter to send, Shift+Enter for a new line)"
        @keydown="onKeydown"
      />
      <button
        type="button"
        class="sbn-btn sbn-btn-primary"
        :disabled="loading || !draft.trim()"
        @click="send()"
      >Send</button>
    </footer>
  </aside>
</template>

<style scoped>
/* Toggle tab — vertical pill anchored to the right edge */
.sbn-ai-toggle {
    position: fixed;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    z-index: 1200;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 12px 7px;
    border: none;
    border-radius: 8px 0 0 8px;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.03em;
    cursor: pointer;
    box-shadow: -2px 2px 8px rgba(15, 23, 42, 0.18);
    transition: transform 0.15s, opacity 0.15s;
}
.sbn-ai-toggle:hover { transform: translateY(-50%) scale(1.04); }
.sbn-ai-toggle.is-open { opacity: 0; pointer-events: none; }
.sbn-ai-toggle-icon { font-size: 16px; }
.sbn-ai-toggle-label { writing-mode: vertical-rl; }

/* Drawer */
.sbn-ai-drawer {
    position: fixed;
    top: 0;
    right: 0;
    z-index: 1300;
    display: flex;
    flex-direction: column;
    width: 380px;
    max-width: 92vw;
    height: 100vh;
    background: var(--clr-white, #fff);
    border-left: 1px solid var(--clr-border, #e2e8f0);
    box-shadow: -8px 0 28px rgba(15, 23, 42, 0.16);
    transform: translateX(100%);
    transition: transform 0.22s ease;
}
.sbn-ai-drawer.is-open { transform: translateX(0); }

/* Header */
.sbn-ai-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid var(--clr-border, #e2e8f0);
    flex-shrink: 0;
}
.sbn-ai-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
}
.sbn-ai-header-actions { display: flex; align-items: center; gap: 4px; }
.sbn-ai-text-btn {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 12px;
    color: var(--clr-text-muted, #64748b);
    padding: 4px 6px;
}
.sbn-ai-text-btn:hover { color: var(--clr-text, #1e293b); }
.sbn-ai-close {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 15px;
    color: var(--clr-text-muted, #64748b);
    padding: 2px 6px;
    line-height: 1;
}
.sbn-ai-close:hover { color: var(--clr-text, #1e293b); }

/* Message list */
.sbn-ai-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.sbn-ai-empty {
    margin: 0;
    font-size: 13px;
    line-height: 1.6;
    color: var(--clr-text-muted, #64748b);
}

.sbn-ai-msg { display: flex; flex-direction: column; gap: 6px; }
.sbn-ai-msg--user { align-items: flex-end; }
.sbn-ai-msg--assistant { align-items: flex-start; }

.sbn-ai-bubble {
    max-width: 90%;
    padding: 8px 12px;
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.55;
    white-space: pre-wrap;
    word-break: break-word;
}
.sbn-ai-msg--user .sbn-ai-bubble {
    background: var(--clr-primary, #6366f1);
    color: #fff;
    border-bottom-right-radius: 4px;
}
.sbn-ai-msg--assistant .sbn-ai-bubble {
    background: var(--clr-surface-3, #eef1f5);
    color: var(--clr-text, #1e293b);
    border-bottom-left-radius: 4px;
}
.sbn-ai-bubble--loading { opacity: 0.7; font-style: italic; }

/* Suggestion card — the insertable HTML + apply buttons */
.sbn-ai-suggestion {
    width: 100%;
    border: 1px solid var(--clr-border, #e2e8f0);
    border-radius: 8px;
    overflow: hidden;
    background: var(--clr-white, #fff);
}
.sbn-ai-suggestion-preview {
    padding: 10px 12px;
    max-height: 220px;
    overflow-y: auto;
    font-size: 13px;
    line-height: 1.6;
    border-bottom: 1px solid var(--clr-border, #e2e8f0);
}
.sbn-ai-suggestion-preview :first-child { margin-top: 0; }
.sbn-ai-suggestion-preview :last-child  { margin-bottom: 0; }
.sbn-ai-suggestion-actions {
    display: flex;
    gap: 8px;
    padding: 8px 10px;
    background: var(--clr-surface-2, #f7fafc);
}

.sbn-ai-error {
    margin: 0;
    font-size: 12px;
    color: var(--clr-danger, #dc2626);
}

/* Quick-action bar */
.sbn-ai-quick {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    padding: 8px 12px;
    border-top: 1px solid var(--clr-border, #e2e8f0);
    background: var(--clr-surface-2, #f7fafc);
    flex-shrink: 0;
}
.sbn-ai-quick-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--clr-text-muted, #64748b);
}
.sbn-ai-quick-btn {
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid var(--clr-border, #e2e8f0);
    border-radius: 999px;
    background: var(--clr-white, #fff);
    color: var(--clr-text, #1e293b);
    cursor: pointer;
    transition: border-color 0.12s, color 0.12s;
}
.sbn-ai-quick-btn:hover:not(:disabled) {
    border-color: var(--clr-primary, #6366f1);
    color: var(--clr-primary, #6366f1);
}
.sbn-ai-quick-btn:disabled { opacity: 0.5; cursor: default; }

/* Input */
.sbn-ai-input {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    padding: 12px;
    border-top: 1px solid var(--clr-border, #e2e8f0);
    flex-shrink: 0;
}
.sbn-ai-input textarea {
    flex: 1;
    resize: none;
    padding: 8px 10px;
    font-size: 13px;
    font-family: inherit;
    line-height: 1.5;
    border: 1px solid var(--clr-border, #e2e8f0);
    border-radius: 6px;
    outline: none;
}
.sbn-ai-input textarea:focus {
    border-color: var(--clr-primary, #6366f1);
}
</style>
