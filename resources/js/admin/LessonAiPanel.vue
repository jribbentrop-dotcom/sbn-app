<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { hasSelection } from './editorSelection';

// ── Editor bridge ─────────────────────────────────────────────────────────────

interface EditorBridge {
    getSelection(): string;
    getContext(): string;
    insertAtCursor(html: string): void;
    replaceSelection(html: string): void;
}

function bridge(): EditorBridge | null {
    return (window as any).__sbnEditor ?? null;
}

// ── Lesson metadata (read from data attributes on the mount element) ──────────

const meta = ref<Record<string, string>>({});

onMounted(() => {
    const el = document.getElementById('lesson-ai-panel');
    if (el?.dataset) {
        const d = el.dataset;
        if (d.lessonTitle)  meta.value.lessonTitle  = d.lessonTitle;
        if (d.courseTitle)  meta.value.courseTitle   = d.courseTitle;
        if (d.courseGenre)  meta.value.courseGenre   = d.courseGenre;
        if (d.sectionTitle) meta.value.sectionTitle  = d.sectionTitle;
    }
});

// ── State ─────────────────────────────────────────────────────────────────────

interface ChatMessage {
    role: 'user' | 'assistant';
    text: string;
    html?: string;
}

const QUICK_ACTIONS: { label: string; prompt: string }[] = [
    { label: 'Proofread',  prompt: 'Proofread the selected passage for grammar, spelling, and clarity. Return the corrected version as HTML.' },
    { label: 'Improve',    prompt: 'Rewrite the selected passage to read more clearly and engagingly, keeping the same meaning and length. Return it as HTML.' },
    { label: 'Shorten',    prompt: 'Make the selected passage more concise without losing key information. Return it as HTML.' },
];

function lessonLabel(): string {
    return meta.value.lessonTitle ? `"${meta.value.lessonTitle}"` : 'this lesson';
}

function prefillActions() {
    const label = lessonLabel();
    return [
        { label: '✦ Draft intro',         prompt: `Write an engaging opening paragraph for ${label}. Be specific about the musical concept — name the technique, feel, or historical context. Return as HTML.` },
        { label: '✦ Explain the concept', prompt: `Write a clear, in-depth explanation of the main musical concept covered in ${label}. Include historical context, musical character, and why it matters. Return as HTML.` },
        { label: '✦ Continue writing',    prompt: `Continue writing the lesson content for ${label} from where it currently ends. Match the existing tone and style. Return as HTML.` },
        { label: '✦ Practice tips',       prompt: `Write a practical "How to practice this" section for ${label} with 3–5 specific, actionable tips for a guitar student. Return as HTML.` },
    ];
}

const messages  = ref<ChatMessage[]>([]);
const draft     = ref('');
const loading   = ref(false);
const errorMsg  = ref('');
const scrollRef = ref<HTMLElement | null>(null);
const inputRef  = ref<HTMLTextAreaElement | null>(null);

async function scrollToBottom() {
    await nextTick();
    if (scrollRef.value) scrollRef.value.scrollTop = scrollRef.value.scrollHeight;
}

// ── Send ──────────────────────────────────────────────────────────────────────

async function send(preset?: string) {
    const text = (preset ?? draft.value).trim();
    if (!text || loading.value) return;

    const ed        = bridge();
    const selection = ed?.getSelection() ?? '';
    const context   = ed?.getContext() ?? '';
    const history   = messages.value.map(m => ({ role: m.role, text: m.text }));

    messages.value.push({ role: 'user', text });
    draft.value  = '';
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
                lessonMeta: meta.value,
            }),
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);

        messages.value.push({
            role: 'assistant',
            text: data.reply || '(no reply)',
            html: (data.html && data.html.trim()) ? data.html : undefined,
        });
    } catch {
        errorMsg.value = 'AI request failed. Please try again.';
    } finally {
        loading.value = false;
        scrollToBottom();
    }
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
}

function insert(msg: ChatMessage)  { if (msg.html) bridge()?.insertAtCursor(msg.html); }
function replace(msg: ChatMessage) { if (msg.html) bridge()?.replaceSelection(msg.html); }
function clearChat() { messages.value = []; errorMsg.value = ''; }
</script>

<template>
  <div class="sbn-ai-inline">

    <div class="sbn-ai-inline-header">
      <span class="sbn-ai-inline-title">✨ AI Assistant</span>
      <button v-if="messages.length" type="button" class="sbn-ai-text-btn" @click="clearChat">Clear</button>
    </div>

    <!-- Quick-start buttons — shown only before first message -->
    <div v-if="!messages.length" class="sbn-ai-quickstart">
      <p class="sbn-ai-quickstart-hint">Generate content, or type a question below.</p>
      <div class="sbn-ai-quickstart-grid">
        <button v-for="a in prefillActions()" :key="a.label"
                type="button" class="sbn-ai-prefill-btn"
                :disabled="loading" @click="send(a.prompt)">
          {{ a.label }}
        </button>
      </div>
    </div>

    <!-- Message thread -->
    <div ref="scrollRef" class="sbn-ai-messages">

      <div v-for="(msg, i) in messages" :key="i"
           class="sbn-ai-msg" :class="`sbn-ai-msg--${msg.role}`">
        <div class="sbn-ai-bubble">{{ msg.text }}</div>

        <div v-if="msg.role === 'assistant' && msg.html" class="sbn-ai-suggestion">
          <div class="sbn-ai-suggestion-preview" v-html="msg.html" />
          <div class="sbn-ai-suggestion-actions">
            <button type="button" class="sbn-btn sbn-btn-primary sbn-btn-sm" @click="insert(msg)">
              Insert at cursor
            </button>
            <button type="button" class="sbn-btn sbn-btn-secondary sbn-btn-sm"
                    :disabled="!hasSelection"
                    :title="hasSelection ? 'Replace the selected text' : 'Select text in the editor first'"
                    @click="replace(msg)">
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

    <!-- Quick actions when text is selected -->
    <div v-if="hasSelection" class="sbn-ai-quick">
      <span class="sbn-ai-quick-label">Selection:</span>
      <button v-for="qa in QUICK_ACTIONS" :key="qa.label" type="button"
              class="sbn-ai-quick-btn" :disabled="loading"
              @click="send(qa.prompt)">{{ qa.label }}</button>
    </div>

    <!-- Input -->
    <div class="sbn-ai-input">
      <textarea ref="inputRef" v-model="draft" rows="2"
                placeholder="Ask the AI… (Enter to send, Shift+Enter for new line)"
                @keydown="onKeydown" />
      <button type="button" class="sbn-btn sbn-btn-primary"
              :disabled="loading || !draft.trim()"
              @click="send()">Send</button>
    </div>

  </div>
</template>

<style scoped>
.sbn-ai-inline {
    display: flex;
    flex-direction: column;
    border-top: 1px solid var(--clr-border, #e2e8f0);
    background: var(--clr-surface, #fff);
}

.sbn-ai-inline-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px 8px;
    flex-shrink: 0;
}

.sbn-ai-inline-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--clr-text, #1e293b);
}

.sbn-ai-text-btn {
    background: none; border: none; cursor: pointer;
    font-size: 12px; color: var(--clr-text-muted, #64748b); padding: 2px 6px;
}
.sbn-ai-text-btn:hover { color: var(--clr-text, #1e293b); }

.sbn-ai-quickstart {
    padding: 10px 16px 12px;
    border-bottom: 1px solid var(--clr-border, #e2e8f0);
    flex-shrink: 0;
}
.sbn-ai-quickstart-hint {
    margin: 0 0 8px;
    font-size: 12px;
    color: var(--clr-text-muted, #64748b);
}
.sbn-ai-quickstart-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}
.sbn-ai-prefill-btn {
    background: var(--clr-surface-2, #f7fafc);
    border: 1px solid var(--clr-border, #e2e8f0);
    border-radius: 6px;
    color: var(--clr-text, #1e293b);
    font-size: 12px;
    font-weight: 500;
    padding: 7px 10px;
    cursor: pointer;
    text-align: left;
    line-height: 1.35;
    transition: border-color 0.12s, background 0.12s;
}
.sbn-ai-prefill-btn:hover:not(:disabled) {
    border-color: var(--clr-primary, #6366f1);
    background: var(--clr-white, #fff);
    color: var(--clr-primary, #6366f1);
}
.sbn-ai-prefill-btn:disabled { opacity: 0.5; cursor: default; }

.sbn-ai-messages {
    max-height: 320px;
    overflow-y: auto;
    padding: 0 16px 12px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.sbn-ai-empty {
    margin: 0;
    font-size: 13px;
    line-height: 1.6;
    color: var(--clr-text-muted, #64748b);
}

.sbn-ai-msg { display: flex; flex-direction: column; gap: 6px; }
.sbn-ai-msg--user      { align-items: flex-end; }
.sbn-ai-msg--assistant { align-items: flex-start; }

.sbn-ai-bubble {
    max-width: 88%;
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

.sbn-ai-suggestion {
    width: 100%;
    border: 1px solid var(--clr-border, #e2e8f0);
    border-radius: 8px;
    overflow: hidden;
    background: var(--clr-white, #fff);
}
.sbn-ai-suggestion-preview {
    padding: 10px 12px;
    max-height: 200px;
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

.sbn-ai-error { margin: 0; font-size: 12px; color: var(--clr-danger, #dc2626); }

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
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--clr-text-muted, #64748b);
}
.sbn-ai-quick-btn {
    padding: 4px 10px; font-size: 12px; font-weight: 600;
    border: 1px solid var(--clr-border, #e2e8f0); border-radius: 999px;
    background: var(--clr-white, #fff); color: var(--clr-text, #1e293b);
    cursor: pointer; transition: border-color 0.12s, color 0.12s;
}
.sbn-ai-quick-btn:hover:not(:disabled) { border-color: var(--clr-primary, #6366f1); color: var(--clr-primary, #6366f1); }
.sbn-ai-quick-btn:disabled { opacity: 0.5; cursor: default; }

.sbn-ai-input {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    padding: 10px 12px;
    border-top: 1px solid var(--clr-border, #e2e8f0);
    flex-shrink: 0;
}
.sbn-ai-input textarea {
    flex: 1; resize: none; padding: 8px 10px; font-size: 13px;
    font-family: inherit; line-height: 1.5;
    border: 1px solid var(--clr-border, #e2e8f0); border-radius: 6px; outline: none;
}
.sbn-ai-input textarea:focus { border-color: var(--clr-primary, #6366f1); }
</style>
