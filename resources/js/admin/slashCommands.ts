/**
 * Slash-command extension for TipTap.
 *
 * Typing "/" at the start of a word triggers an inline popup with the 4 SBN
 * node types. Picking one:
 *   1. Deletes the "/" from the document.
 *   2. Calls window.__sbnPalette(type) — exposed by LessonPalette.vue —
 *      which switches the palette to that tab and focuses its search input.
 *
 * No node is inserted here. Insertion happens when the user clicks a result
 * in the palette (same path as a normal palette click), keeping one code path.
 */

import { Extension } from '@tiptap/core';
import Suggestion, { type SuggestionOptions } from '@tiptap/suggestion';
import { type Editor }  from '@tiptap/core';

type NodeType = 'chord' | 'rhythm' | 'progression' | 'sheet' | 'song' | 'image' | 'youtube' | 'widget';

interface SlashItem { label: string; type: NodeType; shortcut: string }

const ITEMS: SlashItem[] = [
    { label: 'Chord',       type: 'chord',       shortcut: '⇧C' },
    { label: 'Rhythm',      type: 'rhythm',       shortcut: '⇧R' },
    { label: 'Progression', type: 'progression',  shortcut: '⇧P' },
    { label: 'Sheet',       type: 'sheet',        shortcut: '⇧S' },
    { label: 'Song',        type: 'song',         shortcut: '⇧L' },
    { label: 'Widget',      type: 'widget',       shortcut: '⇧W' },
    { label: 'Image',       type: 'image',        shortcut: '⇧M' },
    { label: 'YouTube',     type: 'youtube',      shortcut: '' },
];

// ── Popup DOM ─────────────────────────────────────────────────────────────────

function createPopup(): HTMLElement {
    const el = document.createElement('div');
    el.className = 'sbn-slash-popup';
    el.setAttribute('role', 'listbox');
    document.body.appendChild(el);
    return el;
}

function renderPopup(
    popup: HTMLElement,
    items: SlashItem[],
    activeIndex: number,
    onSelect: (item: SlashItem) => void,
) {
    popup.innerHTML = '';
    items.forEach((item, i) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sbn-slash-item' + (i === activeIndex ? ' is-active' : '');
        btn.setAttribute('role', 'option');
        btn.innerHTML = `
            <span class="sbn-slash-dot sbn-slash-dot--${item.type}"></span>
            <span class="sbn-slash-label">${item.label}</span>
            <kbd class="sbn-slash-kbd">Ctrl${item.shortcut}</kbd>`;
        btn.addEventListener('mousedown', (e) => { e.preventDefault(); onSelect(item); });
        popup.appendChild(btn);
    });
}

function positionPopup(popup: HTMLElement, rect: DOMRect) {
    const scrollY = window.scrollY;
    const scrollX = window.scrollX;
    popup.style.position  = 'absolute';
    popup.style.top       = `${rect.bottom + scrollY + 4}px`;
    popup.style.left      = `${rect.left  + scrollX}px`;
    popup.style.display   = 'block';
}

// ── Extension ─────────────────────────────────────────────────────────────────

export const SlashCommands = Extension.create({
    name: 'slashCommands',

    addOptions() {
        return {
            suggestion: {
                char: '/',
                allowSpaces: false,
                startOfLine: false,

                items({ query }: { query: string }) {
                    const q = query.toLowerCase();
                    return ITEMS.filter(
                        (i) => !q || i.label.toLowerCase().startsWith(q),
                    );
                },

                render() {
                    let popup: HTMLElement | null = null;
                    let activeIndex = 0;

                    return {
                        onStart(props: any) {
                            activeIndex = 0;
                            popup = createPopup();
                            renderPopup(popup, props.items, activeIndex, (item) => {
                                props.command(item);
                            });
                            const rect = props.clientRect?.() ?? new DOMRect();
                            positionPopup(popup, rect);
                        },

                        onUpdate(props: any) {
                            if (!popup) return;
                            activeIndex = Math.min(activeIndex, Math.max(0, props.items.length - 1));
                            renderPopup(popup, props.items, activeIndex, (item) => {
                                props.command(item);
                            });
                            const rect = props.clientRect?.() ?? new DOMRect();
                            positionPopup(popup, rect);
                        },

                        onKeyDown({ event }: { event: KeyboardEvent }) {
                            if (!popup) return false;
                            const items: SlashItem[] = Array.from(
                                popup.querySelectorAll<HTMLElement>('.sbn-slash-item'),
                            ).map((_, i) => ITEMS[i]).filter(Boolean);

                            if (event.key === 'ArrowDown') {
                                activeIndex = (activeIndex + 1) % items.length;
                                Array.from(popup.querySelectorAll('.sbn-slash-item')).forEach((el, i) =>
                                    el.classList.toggle('is-active', i === activeIndex),
                                );
                                return true;
                            }
                            if (event.key === 'ArrowUp') {
                                activeIndex = (activeIndex - 1 + items.length) % items.length;
                                Array.from(popup.querySelectorAll('.sbn-slash-item')).forEach((el, i) =>
                                    el.classList.toggle('is-active', i === activeIndex),
                                );
                                return true;
                            }
                            if (event.key === 'Enter') {
                                const chosen = items[activeIndex];
                                if (chosen) {
                                    // Simulate a command call via the active item's mousedown
                                    (popup.querySelectorAll('.sbn-slash-item')[activeIndex] as HTMLElement)?.dispatchEvent(
                                        new MouseEvent('mousedown', { bubbles: true }),
                                    );
                                }
                                return true;
                            }
                            if (event.key === 'Escape') {
                                popup.remove();
                                popup = null;
                                return true;
                            }
                            return false;
                        },

                        onExit() {
                            popup?.remove();
                            popup = null;
                        },
                    };
                },

                command({ editor, range, props }: { editor: Editor; range: any; props: SlashItem }) {
                    // Delete the "/" trigger text
                    editor.chain().focus().deleteRange(range).run();
                    
                    if (props.type === 'youtube') {
                        const url = window.prompt('Enter YouTube URL:');
                        if (!url) return;
                        
                        const idMatch = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&]+)/);
                        if (idMatch && idMatch[1]) {
                            editor.chain().focus().insertContent({
                                type: 'sbn-youtube',
                                attrs: { id: idMatch[1] },
                            }).run();
                        } else {
                            alert('Invalid YouTube URL');
                        }
                        return;
                    }

                    // Delegate to palette
                    const fn = (window as any).__sbnPalette;
                    if (typeof fn === 'function') fn(props.type === 'image' ? 'media' : props.type);
                },
            } satisfies Partial<SuggestionOptions>,
        };
    },

    addProseMirrorPlugins() {
        return [
            Suggestion({
                editor: this.editor,
                ...this.options.suggestion,
            }),
        ];
    },
});
