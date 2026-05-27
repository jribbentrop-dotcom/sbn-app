<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import { Node, mergeAttributes } from '@tiptap/core';
import { SlashCommands } from './slashCommands';
import Image from '@tiptap/extension-image';
import { hasSelection } from './editorSelection';

// ── SBN chip node factory ────────────────────────────────────────────────────

type SbnNodeType = 'chord' | 'rhythm' | 'progression' | 'sheet' | 'song' | 'widget' | 'fretboard';

const SBN_TYPES: SbnNodeType[] = ['chord', 'rhythm', 'progression', 'sheet', 'song', 'widget', 'fretboard'];

const LABELS: Record<SbnNodeType, string> = {
    chord:       'chord',
    rhythm:      'rhythm',
    progression: 'progression',
    sheet:       'sheet',
    song:        'song',
    widget:      'widget',
    fretboard:   'fretboard',
};

// Attribute objects may carry parseHTML/renderHTML so a camelCase TipTap attr
// can serialize to a hyphenated HTML attribute (e.g. videoSnippet →
// video-snippet, the tag attribute CourseController::player() reads).
type AttrSpec = {
    default: string | null;
    parseHTML?: (el: HTMLElement) => string | null;
    renderHTML?: (attrs: Record<string, any>) => Record<string, any>;
};

const videoSnippetAttr: AttrSpec = {
    default: '',
    parseHTML: (el) => el.getAttribute('video-snippet') || '',
    renderHTML: (attrs) =>
        attrs.videoSnippet ? { 'video-snippet': attrs.videoSnippet } : {},
};

const ATTRS: Record<SbnNodeType, Record<string, AttrSpec>> = {
    chord:       { slug: { default: '' }, root:  { default: '' } },
    rhythm:      { slug: { default: '' }, videoSnippet: videoSnippetAttr },
    progression: { slug: { default: '' }, key:   { default: 'C' }, videoSnippet: videoSnippetAttr },
    sheet:       { slug: { default: '' }, key:   { default: 'C' } },
    song:        { slug: { default: '' }, label: { default: '' } },
    widget:      { slug: { default: '' } },
    fretboard:   { slug: { default: '' } },
};

function makeSbnNode(type: SbnNodeType) {
    return Node.create({
        name: `sbn-${type}`,
        group: 'inline',
        inline: true,
        atom: true,   // treat as a single unit — cursor can't enter it

        addAttributes() {
            return ATTRS[type];
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

                const extras: string[] = [];
                if (type === 'chord'       && node.attrs.root)  extras.push(node.attrs.root);
                if (type === 'progression' && node.attrs.key)   extras.push(`key: ${node.attrs.key}`);
                if (type === 'sheet'       && node.attrs.key)   extras.push(`key: ${node.attrs.key}`);
                if (type === 'song'        && node.attrs.label) extras.push(node.attrs.label);
                if ((type === 'rhythm' || type === 'progression') && node.attrs.videoSnippet) extras.push('▶ video');
                const suffix = extras.length ? ` (${extras.join(', ')})` : '';

                label.textContent = `${LABELS[type]}: ${node.attrs.slug}${suffix}`;

                const editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'sbn-chip-edit';
                editBtn.setAttribute('aria-label', 'Edit');
                editBtn.textContent = '✎';
                editBtn.addEventListener('click', () => {
                    const newSlug = window.prompt(`Edit ${type} slug:`, node.attrs.slug);
                    if (newSlug && newSlug !== node.attrs.slug) {
                        const pos = typeof getPos === 'function' ? getPos() : null;
                        if (pos == null) return;
                        ed.chain().focus().command(({ tr }) => {
                            // Preserve every other attr (key, videoSnippet, …);
                            // only the slug changes.
                            tr.setNodeMarkup(pos, undefined, { ...node.attrs, slug: newSlug });
                            return true;
                        }).run();
                    }
                });

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
                dom.appendChild(editBtn);
                dom.appendChild(deleteBtn);

                return { dom };
            };
        },
    });
}

const sbnExtensions = SBN_TYPES.map(makeSbnNode);

const makeYoutubeNode = () => {
    return Node.create({
        name: 'sbn-youtube',
        group: 'block',
        atom: true,
        addAttributes() {
            return {
                id: { default: '' },
                start: { default: null },
            };
        },
        parseHTML() {
            return [{ tag: 'sbn-youtube' }];
        },
        renderHTML({ HTMLAttributes }) {
            return ['sbn-youtube', mergeAttributes(HTMLAttributes)];
        },
        addNodeView() {
            return ({ node, getPos, editor: ed }) => {
                const dom = document.createElement('div');
                dom.className = 'sbn-chip'; // reuse chip styling
                dom.dataset.type = 'youtube';
                dom.contentEditable = 'false';

                const label = document.createElement('span');
                label.className = 'sbn-chip-label';
                label.textContent = `YouTube: ${node.attrs.id}`;

                const editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'sbn-chip-edit';
                editBtn.setAttribute('aria-label', 'Edit');
                editBtn.textContent = '✎';
                editBtn.addEventListener('click', () => {
                    const newId = window.prompt(`Edit YouTube ID:`, node.attrs.id);
                    if (newId && newId !== node.attrs.id) {
                        const pos = typeof getPos === 'function' ? getPos() : null;
                        if (pos == null) return;
                        ed.chain().focus().command(({ tr }) => {
                            tr.setNodeMarkup(pos, undefined, { id: newId });
                            return true;
                        }).run();
                    }
                });

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
                dom.appendChild(editBtn);
                dom.appendChild(deleteBtn);

                return { dom };
            };
        },
    });
};

// ── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{ initial: string }>();

// ── Editor setup ─────────────────────────────────────────────────────────────

let editor: Editor | null = null;

// Reactive state for toolbar active states — updated on every selection change
const fmt = ref({
    bold: false, italic: false, h1: false, h2: false, h3: false,
    ul: false, ol: false, blockquote: false,
});

const isProcessingAI = ref(false);

async function callAI(action: string, content: string, context: string = '') {
    isProcessingAI.value = true;
    try {
        const response = await fetch('/admin/ai/process', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            },
            body: JSON.stringify({ action, content, context })
        });
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        return data;
    } catch (err) {
        console.error('AI Error:', err);
        alert('AI Assistant is currently unavailable.');
        return null;
    } finally {
        isProcessingAI.value = false;
    }
}

async function aiAutocomplete() {
    if (!editor) return;
    const { from } = editor.state.selection;
    const textBefore = editor.state.doc.textBetween(Math.max(0, from - 500), from, ' ');
    
    const res = await callAI('autocomplete', textBefore, editor.getText().substring(0, 500));
    if (res?.suggestion) {
        editor.chain().focus().insertContent(res.suggestion).run();
    }
}

function updateFmt() {
    if (!editor) return;
    // Publish selection state for LessonAiPanel's "Replace selection" button.
    const { from, to } = editor.state.selection;
    hasSelection.value = from !== to;
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

const SHORTCUT_MAP: Record<string, string> = {
    c: 'chord',
    r: 'rhythm',
    p: 'progression',
    s: 'sheet',
    f: 'fretboard',
    l: 'song',
    w: 'widget',
    m: 'media',
};

function handleShortcut(e: KeyboardEvent) {
    if (!e.ctrlKey || !e.shiftKey) return;
    const type = SHORTCUT_MAP[e.key.toLowerCase()];
    if (!type) return;
    e.preventDefault();
    const fn = (window as any).__sbnPalette;
    if (typeof fn === 'function') fn(type);
}

function uploadImage(file: File, view: any, pos?: number) {
    const el = document.getElementById('lesson-editor');
    const lessonId = el?.dataset.lessonId;
    if (!lessonId) {
        alert('Please save the lesson first before uploading images.');
        return;
    }

    const formData = new FormData();
    formData.append('image', file);

    fetch(`/admin/lessons/${lessonId}/upload-image`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.url) {
            if (pos !== undefined) {
                view.dispatch(view.state.tr.insert(pos, view.state.schema.nodes.image.create({ src: data.url })));
            } else {
                view.dispatch(view.state.tr.replaceSelectionWith(view.state.schema.nodes.image.create({ src: data.url })));
            }
        }
    })
    .catch(err => {
        console.error('Upload failed', err);
        alert('Image upload failed.');
    });
}

import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';

const MediaHandlingExtension = Extension.create({
    name: 'mediaHandling',
    addProseMirrorPlugins() {
        return [
            new Plugin({
                key: new PluginKey('mediaHandling'),
                props: {
                    handlePaste(view, event) {
                        const items = Array.from(event.clipboardData?.items || []);
                        const hasImage = items.some(i => i.type.startsWith('image/'));
                        if (hasImage) {
                            const file = items.find(i => i.type.startsWith('image/'))?.getAsFile();
                            if (file) {
                                uploadImage(file, view);
                                return true;
                            }
                        }
                        
                        const text = event.clipboardData?.getData('text/plain');
                        if (text) {
                            const idMatch = text.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\s]+)/);
                            if (idMatch && idMatch[1]) {
                                view.dispatch(
                                    view.state.tr.replaceSelectionWith(
                                        view.state.schema.nodes['sbn-youtube'].create({ id: idMatch[1] })
                                    )
                                );
                                return true;
                            }
                        }
                        return false;
                    },
                    handleDrop(view, event, slice, moved) {
                        const jsonStr = event.dataTransfer?.getData('application/json');
                        if (jsonStr) {
                            try {
                                const data = JSON.parse(jsonStr);
                                if (data.type && data.slug) {
                                    event.preventDefault();
                                    const coordinates = view.posAtCoords({ left: event.clientX, top: event.clientY });
                                    const pos = coordinates ? coordinates.pos : view.state.selection.from;
                                    
                                    if (data.type === 'media' || data.type === 'image') {
                                        view.dispatch(view.state.tr.insert(pos, view.state.schema.nodes.image.create({ src: data.slug })));
                                    } else {
                                        view.dispatch(view.state.tr.insert(pos, view.state.schema.nodes[`sbn-${data.type}`].create({ slug: data.slug })));
                                    }
                                    return true;
                                }
                            } catch (e) {
                                // ignore
                            }
                        }

                        if (!moved && event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0) {
                            const file = Array.from(event.dataTransfer.files).find(f => f.type.startsWith('image/'));
                            if (file) {
                                event.preventDefault();
                                const coordinates = view.posAtCoords({ left: event.clientX, top: event.clientY });
                                uploadImage(file, view, coordinates?.pos);
                                return true;
                            }
                        }
                        return false;
                    }
                }
            })
        ];
    }
});

onMounted(() => {
    editor = new Editor({
        element: document.getElementById('lesson-editor-inner')!,
        extensions: [
            StarterKit,
            Placeholder.configure({ placeholder: 'Start writing… or type / to insert a component' }),
            SlashCommands,
            Image.configure({ inline: true }),
            makeYoutubeNode(),
            MediaHandlingExtension,
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
    (window as any).__sbnInsert = (type: string, slug: string, extras: Record<string, string> = {}) => {
        if (!editor) return;
        if (type === 'image' || type === 'media') {
            editor.chain().focus().insertContent({
                type: 'image',
                attrs: { src: slug },
            }).run();
            return;
        }
        editor.chain().focus().insertContent({
            type: `sbn-${type}`,
            attrs: { slug, ...extras },
        }).run();
    };

    // Bridge for the AI panel (LessonAiPanel.vue). The panel only ever reads
    // state or applies content through these methods — it can't reach the doc
    // any other way, so AI output never lands without an explicit click.
    (window as any).__sbnEditor = {
        /** Plain text the admin currently has selected ('' if nothing). */
        getSelection(): string {
            if (!editor) return '';
            const { from, to } = editor.state.selection;
            return from === to ? '' : editor.state.doc.textBetween(from, to, ' ');
        },
        /** Short plain-text excerpt of the whole lesson, for AI context. */
        getContext(): string {
            return editor ? editor.getText().substring(0, 800) : '';
        },
        /** Drop HTML at the current cursor position. */
        insertAtCursor(html: string) {
            editor?.chain().focus().insertContent(html).run();
        },
        /** Overwrite the current selection with HTML. No-op if nothing selected. */
        replaceSelection(html: string) {
            if (!editor) return;
            const { from, to } = editor.state.selection;
            if (from === to) return;
            editor.chain().focus().insertContentAt({ from, to }, html).run();
        },
    };

    // Keyboard shortcuts — open palette to the right tab
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.code === 'Space') {
            e.preventDefault();
            aiAutocomplete();
        } else {
            handleShortcut(e);
        }
    });
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleShortcut);
    delete (window as any).__sbnInsert;
    delete (window as any).__sbnEditor;
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
