<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    initial: string;
    placeholder?: string;
    eventName?: string;
    // AI context — caller passes whatever metadata it has
    entityType?: 'rhythm' | 'progression' | 'chord' | 'leadsheet' | 'course';
    entityMeta?: Record<string, any>;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

// ── Editor ────────────────────────────────────────────────────────────────────

let editor: Editor | null = null;
const editorEl = ref<HTMLElement | null>(null);

const fmt = ref({
    bold: false, italic: false,
    h2: false, h3: false,
    ul: false, ol: false,
    blockquote: false,
});

function updateFmt() {
    if (!editor) return;
    fmt.value = {
        bold:       editor.isActive('bold'),
        italic:     editor.isActive('italic'),
        h2:         editor.isActive('heading', { level: 2 }),
        h3:         editor.isActive('heading', { level: 3 }),
        ul:         editor.isActive('bulletList'),
        ol:         editor.isActive('orderedList'),
        blockquote: editor.isActive('blockquote'),
    };
}

function save() {
    if (!editor) return;
    document.dispatchEvent(new CustomEvent(props.eventName ?? 'desc-editor:save', { detail: editor.getHTML() }));
    emit('close');
}

function close() { emit('close'); }

onMounted(() => {
    editor = new Editor({
        element: editorEl.value!,
        extensions: [
            StarterKit,
            Placeholder.configure({ placeholder: props.placeholder ?? 'Write a description…' }),
        ],
        content: props.initial || '',
        onUpdate() { updateFmt(); },
        onSelectionUpdate() { updateFmt(); },
    });
    updateFmt();
});

onBeforeUnmount(() => { editor?.destroy(); editor = null; });

const cmd = (fn: () => void) => { fn(); editor?.commands.focus(); updateFmt(); };

// ── AI strip ──────────────────────────────────────────────────────────────────

interface AiMessage { role: 'user' | 'assistant'; text: string; html?: string; }

const aiMessages = ref<AiMessage[]>([]);
const aiDraft   = ref('');
const aiLoading = ref(false);
const aiError   = ref('');
const aiInputRef  = ref<HTMLTextAreaElement | null>(null);
const aiScrollRef = ref<HTMLElement | null>(null);

function clearAi() { aiMessages.value = []; aiError.value = ''; }

async function scrollAi() {
    await nextTick();
    if (aiScrollRef.value) aiScrollRef.value.scrollTop = aiScrollRef.value.scrollHeight;
}

function currentHtml() { return editor?.getHTML() ?? ''; }

async function aiSend(preset?: string) {
    const text = (preset ?? aiDraft.value).trim();
    if (!text || aiLoading.value) return;

    const history = aiMessages.value.map(m => ({ role: m.role, text: m.text }));
    aiMessages.value.push({ role: 'user', text });
    aiDraft.value = '';
    aiLoading.value = true;
    aiError.value = '';
    scrollAi();

    try {
        const res = await fetch('/admin/ai/process', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
            },
            body: JSON.stringify({
                action: 'describe',
                content: text,
                entityType: props.entityType ?? '',
                entityMeta: props.entityMeta ?? {},
                history,
            }),
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);

        aiMessages.value.push({ role: 'assistant', text: data.reply ?? '', html: data.html?.trim() || undefined });
    } catch (e: any) {
        aiError.value = 'AI request failed. Please try again.';
    } finally {
        aiLoading.value = false;
        scrollAi();
    }
}

function applyHtml(html: string) {
    editor?.commands.setContent(html, false);
    updateFmt();
}

function aiKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); aiSend(); }
}

// Build a prefill prompt that names the entity explicitly so the model can't miss it
function prefillPrompt(): string {
    const m = props.entityMeta ?? {};
    const name = m.title || m.name || '';
    const composer = m.composer ? ` by ${m.composer}` : '';
    const subject = name ? `"${name}${composer}"` : 'this item';
    return `Write a full, rich, educational description for ${subject}. Be specific — name historical context, musical character, key techniques, notable artists or recordings. Aim for 2 to 4 paragraphs.`;
}

function improvePrompt(): string {
    const m = props.entityMeta ?? {};
    const name = m.title || m.name || '';
    const subject = name ? `"${name}"` : 'this item';
    return `Improve and expand the current description of ${subject}. Keep accurate facts but make it richer, more specific, and more engaging.`;
}
</script>

<template>
    <div class="desc-modal-backdrop" @mousedown.self="close">
        <div class="desc-modal" role="dialog" aria-modal="true" aria-label="Edit Description">

            <!-- Header -->
            <div class="desc-modal-header">
                <span class="desc-modal-title">Edit Description</span>
                <button type="button" class="desc-modal-close" @click="close" aria-label="Close">✕</button>
            </div>

            <!-- Toolbar -->
            <div class="desc-toolbar" role="toolbar" aria-label="Formatting">
                <button type="button" :class="{ active: fmt.bold }"
                    @mousedown.prevent="cmd(() => editor?.chain().toggleBold().run())"
                    title="Bold (Ctrl+B)"><strong>B</strong></button>
                <button type="button" :class="{ active: fmt.italic }"
                    @mousedown.prevent="cmd(() => editor?.chain().toggleItalic().run())"
                    title="Italic (Ctrl+I)"><em>I</em></button>
                <div class="desc-toolbar-sep"></div>
                <button type="button" :class="{ active: fmt.h2 }"
                    @mousedown.prevent="cmd(() => editor?.chain().toggleHeading({ level: 2 }).run())"
                    title="Heading 2">H2</button>
                <button type="button" :class="{ active: fmt.h3 }"
                    @mousedown.prevent="cmd(() => editor?.chain().toggleHeading({ level: 3 }).run())"
                    title="Heading 3">H3</button>
                <div class="desc-toolbar-sep"></div>
                <button type="button" :class="{ active: fmt.ul }"
                    @mousedown.prevent="cmd(() => editor?.chain().toggleBulletList().run())"
                    title="Bullet list">&#8226; List</button>
                <button type="button" :class="{ active: fmt.ol }"
                    @mousedown.prevent="cmd(() => editor?.chain().toggleOrderedList().run())"
                    title="Numbered list">1. List</button>
                <div class="desc-toolbar-sep"></div>
                <button type="button" :class="{ active: fmt.blockquote }"
                    @mousedown.prevent="cmd(() => editor?.chain().toggleBlockquote().run())"
                    title="Blockquote">&ldquo; Quote</button>
            </div>

            <!-- Main area: editor + AI side by side -->
            <div class="desc-modal-body has-ai">

                <!-- Editor -->
                <div ref="editorEl" class="desc-editor-body"></div>

                <!-- AI panel -->
                <aside class="desc-ai-panel">
                    <div class="desc-ai-panel-header">
                        <span>✨ AI Assistant</span>
                        <button v-if="aiMessages.length" type="button" class="desc-ai-text-btn" @click="clearAi">Clear</button>
                    </div>

                    <!-- Quick start — shown when no messages yet -->
                    <div v-if="!aiMessages.length" class="desc-ai-quickstart">
                        <p>Generate a description from scratch, or ask anything.</p>
                        <button type="button" class="desc-ai-prefill-btn" :disabled="aiLoading"
                                @click="aiSend(prefillPrompt())">
                            ✨ Pre-fill description
                        </button>
                        <button v-if="currentHtml().length > 10" type="button" class="desc-ai-prefill-btn" :disabled="aiLoading"
                                @click="aiSend(improvePrompt())">
                            ✦ Improve current
                        </button>
                    </div>

                    <!-- Message thread -->
                    <div ref="aiScrollRef" class="desc-ai-messages">
                        <div v-for="(msg, i) in aiMessages" :key="i"
                             class="desc-ai-msg" :class="`desc-ai-msg--${msg.role}`">
                            <div class="desc-ai-bubble">{{ msg.text }}</div>
                            <div v-if="msg.role === 'assistant' && msg.html" class="desc-ai-suggestion">
                                <div class="desc-ai-suggestion-preview sbn-prose" v-html="msg.html" />
                                <div class="desc-ai-suggestion-actions">
                                    <button type="button" class="sbn-btn sbn-btn-primary sbn-btn-sm"
                                            @click="applyHtml(msg.html!)">
                                        Apply to editor
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-if="aiLoading" class="desc-ai-msg desc-ai-msg--assistant">
                            <div class="desc-ai-bubble desc-ai-bubble--loading">Thinking…</div>
                        </div>
                        <p v-if="aiError" class="desc-ai-error">{{ aiError }}</p>
                    </div>

                    <!-- Input -->
                    <div class="desc-ai-input">
                        <textarea ref="aiInputRef" v-model="aiDraft" rows="2"
                                  placeholder="Ask the AI… (Enter to send)"
                                  @keydown="aiKeydown" />
                        <button type="button" class="sbn-btn sbn-btn-primary"
                                :disabled="aiLoading || !aiDraft.trim()"
                                @click="aiSend()">Send</button>
                    </div>
                </aside>
            </div>

            <!-- Footer -->
            <div class="desc-modal-footer">
                <button type="button" class="sbn-btn sbn-btn-secondary" @click="close">Cancel</button>
                <button type="button" class="sbn-btn sbn-btn-primary" @click="save">Save</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.desc-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.desc-modal {
    background: var(--clr-surface);
    border: 1px solid var(--clr-surface-3);
    border-radius: 10px;
    width: min(1040px, 96vw);
    max-height: 86vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Header ──────────────────────────────────────────────────── */
.desc-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px 12px;
    border-bottom: 1px solid var(--clr-surface-3);
    flex-shrink: 0;
}
.desc-modal-title { font-size: 14px; font-weight: 600; color: var(--clr-text); }
.desc-modal-close {
    background: none; border: none; color: var(--clr-text-muted);
    font-size: 16px; cursor: pointer; line-height: 1; padding: 2px 6px;
}
.desc-modal-close:hover { color: var(--clr-text); }

/* ── Toolbar ─────────────────────────────────────────────────── */
.desc-toolbar {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--clr-surface-3);
    flex-shrink: 0;
    flex-wrap: wrap;
}
.desc-toolbar button {
    background: none; border: 1px solid transparent; border-radius: 4px;
    color: var(--clr-text-dim); cursor: pointer; font-size: 13px;
    padding: 4px 8px; line-height: 1.2; transition: background 0.1s;
}
.desc-toolbar button:hover { background: var(--clr-surface-2); color: var(--clr-text); }
.desc-toolbar button.active {
    background: var(--clr-accent-bg); border-color: var(--clr-accent-border); color: var(--clr-accent);
}
.desc-toolbar-sep { width: 1px; height: 18px; background: var(--clr-surface-3); margin: 0 4px; }

/* ── Body: editor + AI side by side ─────────────────────────── */
.desc-modal-body {
    display: flex;
    flex: 1;
    overflow: hidden;
}

.desc-editor-body {
    flex: 1;
    overflow-y: auto;
    padding: 18px 22px;
    min-width: 0;
}

.desc-editor-body :deep(.tiptap) {
    outline: none;
    min-height: 200px;
    color: var(--clr-text);
    font-size: 15px;
    line-height: 1.7;
}
.desc-editor-body :deep(.tiptap) p           { margin: 0 0 0.9em; }
.desc-editor-body :deep(.tiptap) p:last-child { margin-bottom: 0; }
.desc-editor-body :deep(.tiptap) h2 { font-size: 1.2em; font-weight: 700; margin: 1.4em 0 0.5em; }
.desc-editor-body :deep(.tiptap) h3 { font-size: 1.05em; font-weight: 600; margin: 1.2em 0 0.4em; }
.desc-editor-body :deep(.tiptap) ul,
.desc-editor-body :deep(.tiptap) ol { padding-left: 1.4em; margin: 0 0 0.9em; }
.desc-editor-body :deep(.tiptap) li { margin-bottom: 0.25em; }
.desc-editor-body :deep(.tiptap) blockquote {
    border-left: 3px solid var(--clr-accent-border);
    padding-left: 1em; color: var(--clr-text-dim); margin: 0 0 0.9em;
}
.desc-editor-body :deep(.tiptap) strong { font-weight: 700; }
.desc-editor-body :deep(.tiptap) em     { font-style: italic; }
.desc-editor-body :deep(.tiptap p.is-editor-empty:first-child::before) {
    content: attr(data-placeholder);
    color: var(--clr-text-muted);
    pointer-events: none;
    float: left;
    height: 0;
}

/* ── AI panel ────────────────────────────────────────────────── */
.desc-ai-panel {
    width: 320px;
    flex-shrink: 0;
    border-left: 1px solid var(--clr-surface-3);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--clr-surface);
}

.desc-ai-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border-bottom: 1px solid var(--clr-surface-3);
    font-size: 13px;
    font-weight: 600;
    color: var(--clr-text);
    flex-shrink: 0;
}
.desc-ai-text-btn {
    background: none; border: none; cursor: pointer;
    font-size: 12px; color: var(--clr-text-muted); padding: 2px 6px;
}
.desc-ai-text-btn:hover { color: var(--clr-text); }

.desc-ai-quickstart {
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex-shrink: 0;
    border-bottom: 1px solid var(--clr-surface-3);
}
.desc-ai-quickstart p {
    margin: 0;
    font-size: 12px;
    color: var(--clr-text-muted);
    line-height: 1.5;
}
.desc-ai-prefill-btn {
    background: var(--clr-accent-bg);
    border: 1px solid var(--clr-accent-border);
    border-radius: 6px;
    color: var(--clr-accent);
    font-size: 12px;
    font-weight: 600;
    padding: 7px 12px;
    cursor: pointer;
    text-align: left;
    transition: opacity 0.1s;
}
.desc-ai-prefill-btn:hover:not(:disabled) { opacity: 0.85; }
.desc-ai-prefill-btn:disabled { opacity: 0.5; cursor: default; }

.desc-ai-messages {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.desc-ai-msg { display: flex; flex-direction: column; gap: 6px; }
.desc-ai-msg--user { align-items: flex-end; }
.desc-ai-msg--assistant { align-items: flex-start; }

.desc-ai-bubble {
    max-width: 95%;
    padding: 7px 11px;
    border-radius: 10px;
    font-size: 12px;
    line-height: 1.55;
    white-space: pre-wrap;
    word-break: break-word;
}
.desc-ai-msg--user .desc-ai-bubble {
    background: var(--clr-accent);
    color: #fff;
    border-bottom-right-radius: 3px;
}
.desc-ai-msg--assistant .desc-ai-bubble {
    background: var(--clr-surface-2);
    color: var(--clr-text);
    border-bottom-left-radius: 3px;
}
.desc-ai-bubble--loading { opacity: 0.65; font-style: italic; }

.desc-ai-suggestion {
    width: 100%;
    border: 1px solid var(--clr-surface-3);
    border-radius: 8px;
    overflow: hidden;
}
.desc-ai-suggestion-preview {
    padding: 10px 12px;
    max-height: 240px;
    overflow-y: auto;
    font-size: 12px;
    line-height: 1.6;
    border-bottom: 1px solid var(--clr-surface-3);
    background: var(--clr-bg);
}
.desc-ai-suggestion-actions {
    padding: 8px 10px;
    background: var(--clr-surface-2);
}

.desc-ai-error { margin: 0; font-size: 12px; color: var(--clr-error); }

.desc-ai-input {
    display: flex;
    gap: 6px;
    align-items: flex-end;
    padding: 10px;
    border-top: 1px solid var(--clr-surface-3);
    flex-shrink: 0;
}
.desc-ai-input textarea {
    flex: 1;
    resize: none;
    padding: 6px 9px;
    font-size: 12px;
    font-family: inherit;
    line-height: 1.5;
    border: 1px solid var(--clr-surface-3);
    border-radius: 6px;
    outline: none;
    background: var(--clr-bg);
    color: var(--clr-text);
}
.desc-ai-input textarea:focus { border-color: var(--clr-accent-border); }

/* ── Footer ──────────────────────────────────────────────────── */
.desc-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 12px 18px;
    border-top: 1px solid var(--clr-surface-3);
    flex-shrink: 0;
}
</style>
