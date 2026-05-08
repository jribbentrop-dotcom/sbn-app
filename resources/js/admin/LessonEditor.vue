<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import { Node, mergeAttributes } from '@tiptap/core';
import { SlashCommands } from './slashCommands';

// ── SBN chip node factory ────────────────────────────────────────────────────

type SbnNodeType = 'chord' | 'rhythm' | 'progression' | 'song';

const SBN_TYPES: SbnNodeType[] = ['chord', 'rhythm', 'progression', 'song'];

const LABELS: Record<SbnNodeType, string> = {
    chord:       'chord',
    rhythm:      'rhythm',
    progression: 'progression',
    song:        'song',
};

function makeSbnNode(type: SbnNodeType) {
    return Node.create({
        name: `sbn-${type}`,
        group: 'inline',
        inline: true,
        atom: true,   // treat as a single unit — cursor can't enter it

        addAttributes() {
            return {
                slug: { default: '' },
            };
        },

        parseHTML() {
            return [{ tag: `sbn-${type}` }];
        },

        renderHTML({ node, HTMLAttributes }) {
            return [`sbn-${type}`, mergeAttributes(HTMLAttributes)];
        },

        addNodeView() {
            return ({ node, getPos, editor: ed }) => {
                const dom = document.createElement('span');
                dom.className = 'sbn-chip';
                dom.dataset.type = type;
                dom.contentEditable = 'false';

                const label = document.createElement('span');
                label.className = 'sbn-chip-label';
                label.textContent = `${LABELS[type]}: ${node.attrs.slug}`;

                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'sbn-chip-delete';
                deleteBtn.setAttribute('aria-label', 'Remove');
                deleteBtn.textContent = '✕';
                deleteBtn.addEventListener('click', () => {
                    const pos = typeof getPos === 'function' ? getPos() : null;
                    if (pos == null) return;
                    ed.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run();
                });

                dom.appendChild(label);
                dom.appendChild(deleteBtn);

                return { dom };
            };
        },
    });
}

const sbnExtensions = SBN_TYPES.map(makeSbnNode);

// ── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{ initial: string }>();

// ── Editor setup ─────────────────────────────────────────────────────────────

let editor: Editor | null = null;

// Reactive state for toolbar active states — updated on every selection change
const fmt = ref({
    bold: false, italic: false, h1: false, h2: false, h3: false,
    ul: false, ol: false, blockquote: false,
});

function updateFmt() {
    if (!editor) return;
    fmt.value = {
        bold:       editor.isActive('bold'),
        italic:     editor.isActive('italic'),
        h1:         editor.isActive('heading', { level: 1 }),
        h2:         editor.isActive('heading', { level: 2 }),
        h3:         editor.isActive('heading', { level: 3 }),
        ul:         editor.isActive('bulletList'),
        ol:         editor.isActive('orderedList'),
        blockquote: editor.isActive('blockquote'),
    };
}

function tb(action: string, arg?: any) {
    if (!editor) return;
    const chain = editor.chain().focus();
    switch (action) {
        case 'bold':       chain.toggleBold().run(); break;
        case 'italic':     chain.toggleItalic().run(); break;
        case 'h1':         chain.toggleHeading({ level: 1 }).run(); break;
        case 'h2':         chain.toggleHeading({ level: 2 }).run(); break;
        case 'h3':         chain.toggleHeading({ level: 3 }).run(); break;
        case 'ul':         chain.toggleBulletList().run(); break;
        case 'ol':         chain.toggleOrderedList().run(); break;
        case 'blockquote': chain.toggleBlockquote().run(); break;
        case 'hr':         chain.setHorizontalRule().run(); break;
        case 'undo':       chain.undo().run(); break;
        case 'redo':       chain.redo().run(); break;
    }
}

function syncToTextarea(html: string) {
    const ta = document.getElementById('content-sync') as HTMLTextAreaElement | null;
    if (ta) ta.value = html;
}

const SHORTCUT_MAP: Record<string, SbnNodeType> = {
    c: 'chord',
    r: 'rhythm',
    p: 'progression',
    l: 'song',
};

function handleShortcut(e: KeyboardEvent) {
    if (!e.ctrlKey || !e.shiftKey) return;
    const type = SHORTCUT_MAP[e.key.toLowerCase()];
    if (!type) return;
    e.preventDefault();
    const fn = (window as any).__sbnPalette;
    if (typeof fn === 'function') fn(type);
}

onMounted(() => {
    editor = new Editor({
        element: document.getElementById('lesson-editor-inner')!,
        extensions: [
            StarterKit,
            Placeholder.configure({ placeholder: 'Start writing… or type / to insert a component' }),
            SlashCommands,
            ...sbnExtensions,
        ],
        content: props.initial,
        onUpdate({ editor: ed }) {
            syncToTextarea(ed.getHTML());
            updateFmt();
        },
        onSelectionUpdate() { updateFmt(); },
    });
    // Sync initial value immediately so a no-change save still works
    syncToTextarea(editor.getHTML());

    // Bridge for LessonPalette — inserts a chip at the current cursor position
    (window as any).__sbnInsert = (type: SbnNodeType, slug: string) => {
        if (!editor) return;
        editor.chain().focus().insertContent({
            type: `sbn-${type}`,
            attrs: { slug },
        }).run();
    };

    // Keyboard shortcuts — open palette to the right tab
    document.addEventListener('keydown', handleShortcut);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleShortcut);
    delete (window as any).__sbnInsert;
    editor?.destroy();
    editor = null;
});
</script>

<template>
  <div>
    <!-- Toolbar -->
    <div class="sbn-tiptap-toolbar" @mousedown.prevent>
      <button type="button" class="sbn-tiptap-btn" :class="{ 'is-active': fmt.bold }"       title="Bold (Ctrl+B)"       @click="tb('bold')"><b>B</b></button>
      <button type="button" class="sbn-tiptap-btn" :class="{ 'is-active': fmt.italic }"     title="Italic (Ctrl+I)"     @click="tb('italic')"><em>I</em></button>
      <div class="sbn-tiptap-divider" />
      <button type="button" class="sbn-tiptap-btn" :class="{ 'is-active': fmt.h1 }"         title="Heading 1"           @click="tb('h1')">H1</button>
      <button type="button" class="sbn-tiptap-btn" :class="{ 'is-active': fmt.h2 }"         title="Heading 2"           @click="tb('h2')">H2</button>
      <button type="button" class="sbn-tiptap-btn" :class="{ 'is-active': fmt.h3 }"         title="Heading 3"           @click="tb('h3')">H3</button>
      <div class="sbn-tiptap-divider" />
      <button type="button" class="sbn-tiptap-btn" :class="{ 'is-active': fmt.ul }"         title="Bullet list"         @click="tb('ul')">• —</button>
      <button type="button" class="sbn-tiptap-btn" :class="{ 'is-active': fmt.ol }"         title="Numbered list"       @click="tb('ol')">1. —</button>
      <button type="button" class="sbn-tiptap-btn" :class="{ 'is-active': fmt.blockquote }" title="Blockquote"          @click="tb('blockquote')">"</button>
      <button type="button" class="sbn-tiptap-btn"                                           title="Horizontal rule"     @click="tb('hr')">—</button>
      <div class="sbn-tiptap-divider" />
      <button type="button" class="sbn-tiptap-btn"                                           title="Undo (Ctrl+Z)"       @click="tb('undo')">↩</button>
      <button type="button" class="sbn-tiptap-btn"                                           title="Redo (Ctrl+Y)"       @click="tb('redo')">↪</button>
    </div>
    <!-- Editor body -->
    <div class="sbn-tiptap-wrap">
      <div id="lesson-editor-inner" />
    </div>
  </div>
</template>
