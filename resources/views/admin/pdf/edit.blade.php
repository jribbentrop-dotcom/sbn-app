@extends('layouts.admin')

@section('title', 'Edit PDF: ' . $document->title)

@section('actions')
    <a href="{{ route('admin.pdf.index') }}" class="sbn-btn sbn-btn-secondary">← Back</a>
@endsection

@push('styles')
<style>
[x-cloak] { display: none !important; }

.pdf-editor-topbar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 20px;
    background: var(--clr-surface);
    border: 1px solid var(--clr-border);
    border-radius: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.pdf-editor-topbar h2 {
    flex: 1;
    font-size: 1rem;
    font-weight: 600;
    color: var(--clr-text);
    margin: 0;
}
.pdf-field-section {
    margin-bottom: 24px;
}
.pdf-field-section > label {
    display: block;
    font-weight: 600;
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--clr-text-muted);
    margin-bottom: 8px;
}
.pdf-repeater {
    border: 1px solid var(--clr-border);
    border-radius: 8px;
    overflow: hidden;
}
.pdf-repeater-item {
    border-bottom: 1px solid var(--clr-border);
    background: var(--clr-surface);
}
.pdf-repeater-item:last-of-type { border-bottom: none; }
.pdf-repeater-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: var(--clr-surface-2);
    cursor: pointer;
    user-select: none;
}
.pdf-repeater-header-label {
    flex: 1;
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--clr-text);
}
.pdf-repeater-body {
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.pdf-repeater-actions {
    display: flex;
    gap: 6px;
    padding: 10px 16px;
    border-top: 1px solid var(--clr-border);
    background: var(--clr-surface-2);
}
.pdf-slug-field { position: relative; }
.pdf-slug-dropdown {
    position: absolute;
    top: calc(100% + 2px);
    left: 0;
    right: 0;
    background: var(--clr-surface);
    border: 1px solid var(--clr-border);
    border-radius: 6px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.10);
    z-index: 50;
    max-height: 200px;
    overflow-y: auto;
}
.pdf-slug-dropdown-item {
    padding: 7px 12px;
    font-size: 0.85rem;
    cursor: pointer;
    color: var(--clr-text);
}
.pdf-slug-dropdown-item:hover {
    background: var(--clr-surface-2);
}
.pdf-slug-dropdown-item small {
    color: var(--clr-text-muted);
    margin-left: 6px;
}
.pdf-chip-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 6px;
}
.pdf-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    background: var(--clr-surface-2);
    border: 1px solid var(--clr-border);
    border-radius: 20px;
    font-size: 0.82rem;
    font-weight: 500;
}
.pdf-chip button {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--clr-text-muted);
    font-size: 1rem;
    line-height: 1;
    padding: 0;
}
.pdf-chip button:hover { color: var(--clr-danger, #e74c3c); }
.pdf-section-accordion {
    border: 1px solid var(--clr-border);
    border-radius: 10px;
    margin-bottom: 20px;
    overflow: hidden;
}
.pdf-section-accordion > div {
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.pdf-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 12px 16px;
    background: var(--clr-surface-2);
    border: none;
    cursor: pointer;
    text-align: left;
    gap: 10px;
}
.pdf-section-header:hover { background: var(--clr-surface-3, var(--clr-surface-2)); }
.pdf-section-title {
    font-size: 0.88rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--clr-text);
}
.pdf-section-chevron {
    font-size: 1.2rem;
    color: var(--clr-text-muted);
    transition: transform 0.15s;
    transform: rotate(90deg);
}
.pdf-section-chevron.open {
    transform: rotate(270deg);
}
.sbn-tiptap-host {
    background: var(--clr-bg, #fff);
    display: flex;
    flex-direction: column;
}
.sbn-tiptap-preview p         { margin: 0 0 0.8em; }
.sbn-tiptap-preview p:last-child { margin-bottom: 0; }
.sbn-tiptap-preview h2        { font-size: 1.1em; font-weight: 700; margin: 1em 0 0.4em; }
.sbn-tiptap-preview h3        { font-size: 1em; font-weight: 600; margin: 0.9em 0 0.3em; }
.sbn-tiptap-preview ul,
.sbn-tiptap-preview ol        { padding-left: 1.3em; margin: 0 0 0.8em; }
.sbn-tiptap-preview li        { margin-bottom: 0.2em; }
.sbn-tiptap-preview strong    { font-weight: 700; }
.sbn-tiptap-preview em        { font-style: italic; }
.pdf-dirty-badge {
    display: inline-block;
    padding: 2px 8px;
    font-size: 0.75rem;
    background: #fef3c7;
    color: #92400e;
    border-radius: 12px;
    font-weight: 600;
}
</style>
@endpush

@section('content')

<div x-data="pdfEditor()" x-init="init()">

    {{-- Top action bar --}}
    <div class="pdf-editor-topbar">
        <h2>{{ $document->title ?? $document->slug }}</h2>

        <span x-show="dirty" class="pdf-dirty-badge" x-cloak>Unsaved changes</span>

        <select x-model="meta.status" class="sbn-search-input" style="width:auto;padding-left:12px;">
            <option value="draft">Draft</option>
            <option value="publish">Published</option>
        </select>

        <button type="button" class="sbn-btn sbn-btn-primary" @click="save()" :disabled="saving">
            <span x-text="saving ? 'Saving…' : 'Save'"></span>
        </button>

        <a href="{{ route('admin.pdf.preview', $document->slug) }}"
           class="sbn-btn sbn-btn-secondary" target="_blank"
           @click.prevent="saveAndOpen('{{ route('admin.pdf.preview', $document->slug) }}')">
            Preview ↗
        </a>

        <a href="{{ route('admin.pdf.download', $document->slug) }}"
           class="sbn-btn sbn-btn-secondary"
           @click.prevent="saveAndOpen('{{ route('admin.pdf.download', $document->slug) }}')">
            Download PDF
        </a>
    </div>

    {{-- Flash message --}}
    @if(session('success'))
        <div style="padding:10px 16px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;color:#065f46;margin-bottom:16px;">
            {{ session('success') }}
        </div>
    @endif

    <div class="sbn-editor-card">
        <div class="sbn-editor-card-body">

            {{-- Render each schema field --}}
            @php $inSection = false; @endphp
            @foreach($schema['fields'] as $field)
                @php $type = $field['type']; @endphp

                @if($type === 'section')
                    @if($inSection) </div></div> @endif
                    @php $inSection = true; $sectionKey = $field['key']; @endphp
                    <div class="pdf-section-accordion" x-data="{ open: true }">
                        <button type="button" class="pdf-section-header" @click="open = !open">
                            <span class="pdf-section-title">{{ $field['label'] }}</span>
                            <span class="pdf-section-chevron" :class="open ? 'open' : ''">›</span>
                        </button>
                        <div x-show="open">
                @else
                    @php $name = $field['name']; $label = $field['label']; @endphp
                    @if(!$inSection)
                    <div x-data="{ open: true }"><div>
                    @php $inSection = true; @endphp
                    @endif

                <div class="pdf-field-section sbn-form-group">
                    @if($type !== 'repeater')<label>{{ $label }}</label>@endif

                    @switch($type)

                        @case('text')
                            @if(!empty($field['multiline']))
                                <textarea x-model="content.{{ $name }}"
                                    class="sbn-search-input" style="padding:10px 14px;height:72px;resize:vertical;font-family:inherit;"></textarea>
                            @else
                                <input type="text" x-model="content.{{ $name }}" class="sbn-search-input" style="padding-left:14px;">
                            @endif
                            @break

                        @case('textarea')
                            <textarea x-model="content.{{ $name }}"
                                class="sbn-search-input" style="padding:10px 14px;height:110px;resize:vertical;font-family:inherit;"></textarea>
                            @break

                        @case('richtext')
                            @php $editorId = 'richtext-' . $name; @endphp
                            <div class="sbn-tiptap-host"
                                 id="{{ $editorId }}"
                                 data-field="{{ $name }}"
                                 data-initial="{{ htmlspecialchars($document->content[$name] ?? '') }}"
                                 style="min-height:140px;border:1px solid var(--clr-border);border-radius:8px;"></div>
                            @break

                        @case('number')
                            <input type="number" x-model.number="content.{{ $name }}"
                                class="sbn-search-input" style="padding-left:14px;width:120px;">
                            @break

                        @case('range')
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input type="number" x-model.number="content.{{ $name }}[0]"
                                    class="sbn-search-input" style="padding-left:14px;width:100px;" placeholder="From">
                                <span style="color:var(--clr-text-muted);">–</span>
                                <input type="number" x-model.number="content.{{ $name }}[1]"
                                    class="sbn-search-input" style="padding-left:14px;width:100px;" placeholder="To">
                            </div>
                            @break

                        @case('chord-slug')
                        @case('rhythm-slug')
                        @case('song-slug')
                            @php
                                $endpoint = match($type) {
                                    'chord-slug'  => route('api.admin.pdf.search-chords'),
                                    'rhythm-slug' => route('api.admin.pdf.search-rhythms'),
                                    'song-slug'   => route('api.admin.pdf.search-songs'),
                                };
                                $multiple = !empty($field['multiple']);
                            @endphp
                            @if($multiple)
                                {{-- Multiple slug picker (chip list) --}}
                                <div x-data="slugPicker('{{ $endpoint }}', content, '{{ $name }}', true)"
                                     @click.outside="open = false">
                                    <div class="pdf-slug-field">
                                        <input type="text" x-model="query" @input.debounce.300="search()"
                                               @focus="if(query.length >= 1) search()"
                                               class="sbn-search-input sbn-search-input-with-icon" style="padding-left:14px;"
                                               placeholder="Search to add…">
                                        <div class="pdf-slug-dropdown" x-show="open && results.length" x-cloak>
                                            <template x-for="r in results" :key="r.slug">
                                                <div class="pdf-slug-dropdown-item" @click="addChip(r)">
                                                    <span x-text="r.slug"></span>
                                                    <small x-text="r.label"></small>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="pdf-chip-list">
                                        <template x-for="(chip, idx) in (Array.isArray(content['{{ $name }}']) ? content['{{ $name }}'] : [])" :key="chip">
                                            <span class="pdf-chip">
                                                <span x-text="chip"></span>
                                                <button type="button" @click="removeChip(idx)" title="Remove">×</button>
                                            </span>
                                        </template>
                                    </div>
                                </div>
                            @else
                                {{-- Single slug picker --}}
                                <div x-data="slugPicker('{{ $endpoint }}', content, '{{ $name }}', false)"
                                     @click.outside="open = false">
                                    <div class="pdf-slug-field">
                                        <input type="text" x-model="query" @input.debounce.300="search()"
                                               @focus="if(query.length >= 1) search()"
                                               class="sbn-search-input" style="padding-left:14px;"
                                               placeholder="Search slug…">
                                        <div class="pdf-slug-dropdown" x-show="open && results.length" x-cloak>
                                            <template x-for="r in results" :key="r.slug">
                                                <div class="pdf-slug-dropdown-item" @click="selectSingle(r)">
                                                    <span x-text="r.slug"></span>
                                                    <small x-text="r.label"></small>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    <p x-show="content['{{ $name }}']" style="margin-top:6px;font-size:0.83rem;color:var(--clr-text-muted);">
                                        Current: <code x-text="content['{{ $name }}']"></code>
                                        <button type="button" @click="content['{{ $name }}'] = ''; query = ''; $dispatch('content-changed')"
                                                style="margin-left:6px;background:none;border:none;cursor:pointer;color:var(--clr-text-muted);">×</button>
                                    </p>
                                </div>
                            @endif
                            @break

                        @case('repeater')
                            @php $itemFields = $field['item'] ?? []; @endphp
                            <div>
                                <div class="pdf-repeater">
                                    <template x-for="(item, idx) in (content['{{ $name }}'] || [])" :key="idx">
                                        <div class="pdf-repeater-item">
                                            <div class="pdf-repeater-header" @click="toggleItem('{{ $name }}', idx)">
                                                <span class="pdf-repeater-header-label"
                                                      x-text="(idx + 1) + '. ' + (item['{{ $itemFields[0]['name'] ?? 'slug' }}'] || '(empty)')"></span>
                                                <button type="button" @click.stop="moveUp('{{ $name }}', idx)" :disabled="idx === 0"
                                                        class="sbn-btn sbn-btn-sm sbn-btn-secondary" style="padding:2px 8px;">↑</button>
                                                <button type="button" @click.stop="moveDown('{{ $name }}', idx)" :disabled="idx >= content['{{ $name }}'].length - 1"
                                                        class="sbn-btn sbn-btn-sm sbn-btn-secondary" style="padding:2px 8px;">↓</button>
                                                <button type="button" @click.stop="removeItem('{{ $name }}', idx)"
                                                        class="sbn-btn sbn-btn-sm" style="padding:2px 8px;background:var(--clr-danger,#e74c3c);color:#fff;border-color:var(--clr-danger,#e74c3c);">×</button>
                                            </div>

                                            <div class="pdf-repeater-body" x-show="openItems['{{ $name }}']?.[idx]" x-cloak>
                                                @foreach($itemFields as $sub)
                                                    @php
                                                        $sName = $sub['name'];
                                                        $sType = $sub['type'];
                                                        $sLabel = $sub['label'];
                                                        $subEndpoint = match($sType) {
                                                            'chord-slug' => route('api.admin.pdf.search-chords'),
                                                            'rhythm-slug' => route('api.admin.pdf.search-rhythms'),
                                                            'song-slug' => route('api.admin.pdf.search-songs'),
                                                            default => '',
                                                        };
                                                    @endphp
                                                    <div class="sbn-form-group">
                                                        <label style="font-size:0.8rem;font-weight:600;color:var(--clr-text-muted);">{{ $sLabel }}</label>

                                                        @switch($sType)
                                                            @case('text')
                                                                <input type="text" x-model="item['{{ $sName }}']"
                                                                       class="sbn-search-input" style="padding-left:14px;">
                                                                @break

                                                            @case('textarea')
                                                                <textarea x-model="item['{{ $sName }}']"
                                                                    class="sbn-search-input" style="padding:10px 14px;height:110px;resize:vertical;font-family:inherit;"></textarea>
                                                                @break

                                                            @case('number')
                                                                <input type="number" x-model.number="item['{{ $sName }}']"
                                                                       class="sbn-search-input" style="padding-left:14px;width:120px;">
                                                                @break

                                                            @case('range')
                                                                <div style="display:flex;align-items:center;gap:10px;">
                                                                    <input type="number" x-model.number="item['{{ $sName }}'][0]"
                                                                        class="sbn-search-input" style="padding-left:14px;width:100px;" placeholder="From">
                                                                    <span style="color:var(--clr-text-muted);">–</span>
                                                                    <input type="number" x-model.number="item['{{ $sName }}'][1]"
                                                                        class="sbn-search-input" style="padding-left:14px;width:100px;" placeholder="To">
                                                                </div>
                                                                @break

                                                            @case('chord-slug')
                                                            @case('rhythm-slug')
                                                            @case('song-slug')
                                                                <div x-data="slugPicker('{{ $subEndpoint }}', item, '{{ $sName }}', false)"
                                                                     @click.outside="open = false">
                                                                    <div class="pdf-slug-field">
                                                                        <input type="text" x-model="query" @input.debounce.300="search()"
                                                                               @focus="if(!query && item['{{ $sName }}']) query = item['{{ $sName }}']; if(query.length >= 1) search();"
                                                                               class="sbn-search-input" style="padding-left:14px;"
                                                                               :placeholder="item['{{ $sName }}'] || 'Search slug…'">
                                                                        <div class="pdf-slug-dropdown" x-show="open && results.length" x-cloak>
                                                                            <template x-for="r in results" :key="r.slug">
                                                                                <div class="pdf-slug-dropdown-item" @click="selectSingle(r)">
                                                                                    <span x-text="r.slug"></span>
                                                                                    <small x-text="r.label"></small>
                                                                                </div>
                                                                            </template>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                @break

                                                            @default
                                                                <input type="text" x-model="item['{{ $sName }}']"
                                                                       class="sbn-search-input" style="padding-left:14px;">
                                                        @endswitch
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <div class="pdf-repeater-actions">
                                    <button type="button" class="sbn-btn sbn-btn-secondary sbn-btn-sm"
                                            @click="addItem('{{ $name }}', {{ json_encode(array_fill_keys(array_column($itemFields, 'name'), '')) }})">
                                        + Add {{ strtolower($label) }} item
                                    </button>
                                </div>
                            </div>
                            @break

                    @endswitch
                </div>
                @endif
            @endforeach
            @if($inSection) </div></div> @endif

        </div>
    </div>

</div>{{-- /x-data --}}

@endsection

@push('scripts')
<div id="desc-editor-root"></div>
@vite('resources/js/admin/description-editor.ts')
<script>
const PDF_INITIAL_CONTENT = @js((object)($document->content ?: []));
const PDF_UPDATE_URL      = '{{ route('admin.pdf.update', $document->slug) }}';
const PDF_CSRF            = '{{ csrf_token() }}';

// ── Slug picker component (shared across field types) ─────────────────────────
function slugPicker(endpoint, targetObj, fieldName, multiple) {
    return {
        query:   '',
        results: [],
        open:    false,

        async search() {
            if (this.query.length < 1) { this.open = false; return; }
            const res = await fetch(`${endpoint}?q=${encodeURIComponent(this.query)}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            this.results = await res.json();
            this.open = this.results.length > 0;
        },

        selectSingle(r) {
            targetObj[fieldName] = r.slug;
            this.query = r.slug;
            this.open  = false;
        },

        addChip(r) {
            const arr = Array.isArray(targetObj[fieldName]) ? targetObj[fieldName] : [];
            if (! arr.includes(r.slug)) arr.push(r.slug);
            targetObj[fieldName] = arr;
            this.query = '';
            this.open  = false;
        },

        removeChip(idx) {
            const arr = Array.isArray(targetObj[fieldName]) ? [...targetObj[fieldName]] : [];
            arr.splice(idx, 1);
            targetObj[fieldName] = arr;
        },
    };
}

// ── Main PDF editor component ─────────────────────────────────────────────────
function pdfEditor() {
    return {
        content:   {},
        meta:      { status: '{{ $document->status }}' },
        openItems: {},
        dirty:     false,
        saving:    false,

        init() {
            this.content = JSON.parse(JSON.stringify(PDF_INITIAL_CONTENT));

            // Wire richtext hosts — open the description-editor modal on click
            this.$nextTick(() => {
                document.querySelectorAll('.sbn-tiptap-host').forEach(el => {
                    if (el.dataset.mounted) return;
                    el.dataset.mounted = '1';

                    const fieldName = el.dataset.field;
                    const eventName = `pdf-richtext:save:${fieldName}`;

                    // Preview pane
                    const preview = document.createElement('div');
                    preview.className = 'sbn-tiptap-preview sbn-prose';
                    preview.style.cssText = 'padding:12px 16px;min-height:80px;font-size:14px;line-height:1.7;color:var(--clr-text);';
                    preview.innerHTML = this.content[fieldName] || '<em style="color:var(--clr-text-muted)">No content yet — click Edit to open the editor.</em>';
                    el.appendChild(preview);

                    // Edit button
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = 'Edit in WYSIWYG…';
                    btn.className = 'sbn-btn sbn-btn-secondary sbn-btn-sm';
                    btn.style.cssText = 'margin:8px 12px 12px;';
                    btn.addEventListener('click', () => {
                        if (!window.__descEditor) return;
                        window.__descEditor.open({
                            initial:   this.content[fieldName] || '',
                            eventName,
                            placeholder: 'Write prose…',
                        });
                    });
                    el.appendChild(btn);

                    // Listen for save event from the modal
                    document.addEventListener(eventName, (e) => {
                        const html = e.detail || '';
                        this.content[fieldName] = html;
                        this.dirty = true;
                        preview.innerHTML = html || '<em style="color:var(--clr-text-muted)">No content yet — click Edit to open the editor.</em>';
                    });
                });
            });

            // Watch for any change to mark dirty
            this.$watch('content', () => { this.dirty = true; }, { deep: true });
            this.$watch('meta',    () => { this.dirty = true; }, { deep: true });
        },

        // ── Repeater helpers ───────────────────────────────────────────────────

        addItem(fieldName, template) {
            if (! Array.isArray(this.content[fieldName])) this.content[fieldName] = [];
            this.content[fieldName].push({ ...template });
            const idx = this.content[fieldName].length - 1;
            if (! this.openItems[fieldName]) this.openItems[fieldName] = {};
            this.openItems[fieldName][idx] = true;
        },

        removeItem(fieldName, idx) {
            this.content[fieldName].splice(idx, 1);
        },

        moveUp(fieldName, idx) {
            if (idx <= 0) return;
            const arr = this.content[fieldName];
            [arr[idx - 1], arr[idx]] = [arr[idx], arr[idx - 1]];
            this.content[fieldName] = [...arr];
        },

        moveDown(fieldName, idx) {
            const arr = this.content[fieldName];
            if (idx >= arr.length - 1) return;
            [arr[idx], arr[idx + 1]] = [arr[idx + 1], arr[idx]];
            this.content[fieldName] = [...arr];
        },

        toggleItem(fieldName, idx) {
            if (! this.openItems[fieldName]) this.openItems[fieldName] = {};
            this.openItems[fieldName][idx] = ! this.openItems[fieldName][idx];
        },

        // ── Save / preview ─────────────────────────────────────────────────────

        async save() {
            this.saving = true;
            try {
                const res = await fetch(PDF_UPDATE_URL, {
                    method:  'PUT',
                    headers: {
                        'Content-Type':     'application/json',
                        'Accept':           'application/json',
                        'X-CSRF-TOKEN':     PDF_CSRF,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        content: this.content,
                        status:  this.meta.status,
                        title:   (this.content.title || '').replace(/\n/g, ' '),
                    }),
                });
                if (res.ok) {
                    this.dirty = false;
                } else {
                    alert('Save failed. Check the console.');
                    console.error(await res.text());
                }
            } finally {
                this.saving = false;
            }
        },

        async saveAndOpen(url) {
            await this.save();
            window.open(url, '_blank');
        },
    };
}
</script>
@endpush
