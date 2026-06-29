@extends('layouts.admin')

@section('title', 'PDF Products')

@push('styles')
<style>
.pdf-modal-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,0.5);
    z-index: 200; display: flex; align-items: center; justify-content: center;
}
.pdf-modal {
    background: var(--clr-surface); border: 1px solid var(--clr-border);
    border-radius: 12px; width: min(640px, 96vw); max-height: 90vh;
    display: flex; flex-direction: column; overflow: hidden;
}
.pdf-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px; border-bottom: 1px solid var(--clr-border); flex-shrink: 0;
}
.pdf-modal-header h3 { font-size: 1rem; font-weight: 700; margin: 0; }
.pdf-modal-close {
    background: none; border: none; font-size: 1.2rem; cursor: pointer;
    color: var(--clr-text-muted); line-height: 1; padding: 2px 6px;
}
.pdf-modal-body { padding: 20px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 20px; }
.pdf-modal-footer {
    padding: 14px 20px; border-top: 1px solid var(--clr-border);
    display: flex; justify-content: flex-end; gap: 10px; flex-shrink: 0;
}
.pdf-page-picker { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.pdf-page-pool { display: flex; flex-direction: column; gap: 6px; }
.pdf-page-pool-label {
    font-size: 0.78rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.07em; color: var(--clr-text-muted); margin-bottom: 2px;
}
.pdf-page-chip {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px; border: 1px solid var(--clr-border); border-radius: 8px;
    background: var(--clr-surface-2); font-size: 0.88rem; font-weight: 500;
    cursor: default;
}
.pdf-page-chip button {
    background: none; border: none; cursor: pointer; font-size: 1rem;
    color: var(--clr-text-muted); padding: 0 2px; line-height: 1;
}
.pdf-page-chip button:hover { color: var(--clr-text); }
.pdf-page-chip--active { background: var(--clr-accent-bg, #fef3c7); border-color: var(--clr-accent-border, #fcd34d); }
.pdf-page-order { display: flex; flex-direction: column; gap: 6px; }
.pdf-page-order-empty {
    padding: 20px; border: 2px dashed var(--clr-border); border-radius: 8px;
    text-align: center; font-size: 0.85rem; color: var(--clr-text-muted);
}
</style>
@endpush

@section('content')

<div x-data="pdfIndex()" x-init="init()">

{{-- New Document Modal --}}
<div x-show="modal" class="pdf-modal-backdrop" x-cloak @keydown.escape.window="modal = false">
    <div class="pdf-modal" @click.outside="modal = false">
        <div class="pdf-modal-header">
            <h3>New PDF Document</h3>
            <button type="button" class="pdf-modal-close" @click="modal = false">✕</button>
        </div>

        <form method="POST" action="{{ route('admin.pdf.store') }}" @submit="onSubmit">
            @csrf
            <div class="pdf-modal-body">

                {{-- Title + Slug --}}
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <div class="sbn-form-group">
                        <label style="font-size:0.8rem;font-weight:600;color:var(--clr-text-muted);display:block;margin-bottom:4px;">Title</label>
                        <input type="text" name="title" x-model="form.title" @input="slugifyTitle"
                               class="sbn-search-input" style="padding-left:14px;" placeholder="My PDF title" required>
                    </div>
                    <div class="sbn-form-group">
                        <label style="font-size:0.8rem;font-weight:600;color:var(--clr-text-muted);display:block;margin-bottom:4px;">Slug</label>
                        <input type="text" name="slug" x-model="form.slug" @input="slugEdited = true"
                               class="sbn-search-input" style="padding-left:14px;" placeholder="my-pdf-slug" required>
                    </div>
                </div>

                {{-- Page picker --}}
                <div>
                    <div class="pdf-page-pool-label" style="margin-bottom:8px;">Page composition</div>
                    <div class="pdf-page-picker">

                        {{-- Left: available page types --}}
                        <div class="pdf-page-pool">
                            <div class="pdf-page-pool-label">Available pages</div>
                            <template x-for="p in availablePages" :key="p.key">
                                <div class="pdf-page-chip" :class="isSelected(p.key) ? 'pdf-page-chip--active' : ''">
                                    <span x-text="p.label"></span>
                                    <button type="button" @click="addPage(p.key)"
                                            :disabled="isSelected(p.key)"
                                            :style="isSelected(p.key) ? 'opacity:0.3;cursor:default' : ''"
                                            title="Add">＋</button>
                                </div>
                            </template>
                        </div>

                        {{-- Right: ordered selection --}}
                        <div class="pdf-page-order">
                            <div class="pdf-page-pool-label">Page order</div>
                            <div x-show="form.pages.length === 0" class="pdf-page-order-empty">
                                Add pages from the left →
                            </div>
                            <template x-for="(key, idx) in form.pages" :key="key">
                                <div class="pdf-page-chip pdf-page-chip--active">
                                    <span x-text="labelFor(key)"></span>
                                    <span style="display:flex;gap:2px;">
                                        <button type="button" @click="moveUp(idx)" :disabled="idx === 0"
                                                :style="idx === 0 ? 'opacity:0.3' : ''" title="Move up">↑</button>
                                        <button type="button" @click="moveDown(idx)" :disabled="idx === form.pages.length - 1"
                                                :style="idx === form.pages.length - 1 ? 'opacity:0.3' : ''" title="Move down">↓</button>
                                        <button type="button" @click="removePage(idx)" title="Remove"
                                                style="color:var(--clr-danger,#e74c3c);">×</button>
                                    </span>
                                </div>
                            </template>
                        </div>

                    </div>
                    {{-- Hidden input carries the pages JSON --}}
                    <input type="hidden" name="pages" :value="JSON.stringify(form.pages)">
                    <input type="hidden" name="template_key" value="composed">
                </div>

            </div>
            <div class="pdf-modal-footer">
                <button type="button" class="sbn-btn sbn-btn-secondary" @click="modal = false">Cancel</button>
                <button type="submit" class="sbn-btn sbn-btn-primary" :disabled="form.pages.length === 0">Create</button>
            </div>
        </form>
    </div>
</div>

{{-- Top bar --}}
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
    <h1 style="font-size:1.2rem;font-weight:700;margin:0;">PDF Documents</h1>
    <button type="button" class="sbn-btn sbn-btn-primary" @click="modal = true">+ New Document</button>
</div>

@if(session('success'))
    <div style="padding:10px 16px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;color:#065f46;margin-bottom:16px;">
        {{ session('success') }}
    </div>
@endif

@if($errors->any())
    <div style="padding:10px 16px;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;margin-bottom:16px;">
        {{ $errors->first() }}
    </div>
@endif

<div class="sbn-editor-card">
    <div class="sbn-editor-card-body" style="padding:0;">
        @if($documents->isEmpty())
            <p style="padding:24px;color:var(--clr-text-muted);">No PDF documents yet. Click "+ New Document" to create one.</p>
        @else
            <table class="sbn-table" style="width:100%;">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Slug</th>
                        <th>Pages</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($documents as $doc)
                        @php
                            $pageLabels = collect($doc->pages ?? [])
                                ->map(fn($k) => \App\Models\PdfDocument::pageRegistry()[$k]['label'] ?? $k)
                                ->join(', ');
                        @endphp
                        <tr>
                            <td>{{ $doc->title ?? '—' }}</td>
                            <td><code style="font-size:0.8rem;">{{ $doc->slug }}</code></td>
                            <td style="font-size:0.82rem;color:var(--clr-text-muted);">
                                {{ $pageLabels ?: ($templateLabels[$doc->template_key] ?? $doc->template_key) }}
                            </td>
                            <td>
                                <span class="sbn-status-badge sbn-status-{{ $doc->status }}">
                                    {{ ucfirst($doc->status) }}
                                </span>
                            </td>
                            <td style="text-align:right;white-space:nowrap;">
                                <a href="{{ route('admin.pdf.edit', $doc->slug) }}" class="sbn-btn sbn-btn-sm">Edit</a>
                                <a href="{{ route('admin.pdf.preview', $doc->slug) }}" class="sbn-btn sbn-btn-sm sbn-btn-secondary" target="_blank">Preview</a>
                                <a href="{{ route('admin.pdf.download', $doc->slug) }}" class="sbn-btn sbn-btn-sm sbn-btn-secondary">Download</a>
                                <form method="POST" action="{{ route('admin.pdf.destroy', $doc->slug) }}"
                                      style="display:inline;"
                                      onsubmit="return confirm('Delete &quot;{{ addslashes($doc->title ?? $doc->slug) }}&quot;? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="sbn-btn sbn-btn-sm"
                                            style="background:var(--clr-danger,#e74c3c);color:#fff;border-color:var(--clr-danger,#e74c3c);">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

</div>{{-- /x-data --}}

@push('scripts')
<script>
function pdfIndex() {
    return {
        modal: {{ $errors->any() ? 'true' : 'false' }},
        slugEdited: false,
        form: {
            title: '{{ old('title') }}',
            slug:  '{{ old('slug') }}',
            pages: @json(old('pages') ? json_decode(old('pages'), true) : []),
        },
        availablePages: [
            { key: 'cover',  label: 'Cover Page' },
            { key: 'theory', label: 'Theory Page' },
            { key: 'chords', label: 'Chord Pages' },
            { key: 'songs',  label: 'Song Examples' },
        ],

        init() {
            if ({{ $errors->any() ? 'true' : 'false' }}) this.slugEdited = true;
        },

        isSelected(key) { return this.form.pages.includes(key); },

        labelFor(key) {
            return this.availablePages.find(p => p.key === key)?.label ?? key;
        },

        addPage(key) {
            if (!this.isSelected(key)) this.form.pages.push(key);
        },

        removePage(idx) { this.form.pages.splice(idx, 1); },

        moveUp(idx) {
            if (idx <= 0) return;
            [this.form.pages[idx - 1], this.form.pages[idx]] = [this.form.pages[idx], this.form.pages[idx - 1]];
            this.form.pages = [...this.form.pages];
        },

        moveDown(idx) {
            if (idx >= this.form.pages.length - 1) return;
            [this.form.pages[idx], this.form.pages[idx + 1]] = [this.form.pages[idx + 1], this.form.pages[idx]];
            this.form.pages = [...this.form.pages];
        },

        slugifyTitle() {
            if (this.slugEdited) return;
            this.form.slug = this.form.title
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        },

        onSubmit() {
            // ensure pages hidden input is current (x-model binding handles it but be safe)
        },
    };
}
</script>
@endpush

@endsection
