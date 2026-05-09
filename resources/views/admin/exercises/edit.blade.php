{{-- ============================================================
   SBN Teaching Hub — Exercise Visual Editor
   resources/views/admin/exercises/edit.blade.php

   Reuses the leadsheet TabEditor (tab-editor.js / TabEditor.vue) unchanged.
   Alpine loads exercise data via /admin/exercises/{id}/data, dispatches
   sbn-tab-init so TabEditor receives it through the standard useAlpineBridge
   handshake. Saves back via the existing PUT /admin/exercises/{id} route.
============================================================ --}}

@extends('layouts.admin')

@push('vite')
    @vite('resources/js/tab-editor/tab-editor.js')
@endpush

@section('title', $isNew ? 'New Exercise' : 'Edit: ' . $exercise->title)

@section('actions')
    <a href="{{ route('admin.exercises.index') }}" class="sbn-btn sbn-btn-secondary">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back
    </a>
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/leadsheets.css') }}">
<link rel="stylesheet" href="{{ asset('css/sbn-context-menu.css') }}">
@endpush

@section('content')
<div x-data="exerciseEditor()" x-cloak class="sbn-vp-layout" style="flex-direction: column;">

    <div style="display: flex; flex: 1; min-height: 0; width: 100%;">

        {{-- ═══ MAIN EDITOR COLUMN ═══════════════════════════════════ --}}
        <div class="sbn-vp-editor-main">

            @if($isNew)
            <div style="padding: 40px; text-align: center; color: var(--clr-text-muted);">
                <p style="font-size:15px; margin-bottom: 16px;">Fill in the meta fields and save to create the exercise, then return here to edit content.</p>
            </div>
            @else
            {{-- Vue mount point — TabEditor mounts here, same as leadsheet editor --}}
            <div id="sbn-editor-content"></div>
            @endif

        </div>

        {{-- ═══ RIGHT META PANEL ═══════════════════════════════════ --}}
        <div class="sbn-vp-sidebar" style="width: 300px; flex-shrink: 0; padding: 16px; border-left: 1px solid var(--clr-border); display: flex; flex-direction: column; gap: 14px; overflow-y: auto;">

            <form id="exercise-meta-form"
                  method="POST"
                  action="{{ $isNew ? route('admin.exercises.store') : route('admin.exercises.update', $exercise) }}">
                @csrf
                @if(!$isNew) @method('PUT') @endif

                {{-- Receives latest parsed JSON from Alpine before submit --}}
                <input type="hidden" name="content_json" x-ref="contentJsonInput"
                       value="{{ json_encode($exercise->content_json ?? ['sections' => []], JSON_UNESCAPED_SLASHES) }}">

                <div class="sbn-form-group">
                    <label>Title</label>
                    <input type="text" name="title" class="sbn-search-input" style="padding-left:14px;"
                           value="{{ old('title', $exercise->title) }}" required>
                </div>

                <div class="sbn-form-group" style="margin-top:10px;">
                    <label>Slug</label>
                    <input type="text" name="slug" class="sbn-search-input" style="padding-left:14px;"
                           value="{{ old('slug', $exercise->slug) }}" pattern="[a-z0-9\-]+">
                </div>

                <div class="sbn-form-group" style="margin-top:10px;">
                    <label>Key Center</label>
                    <select name="key_center" class="sbn-search-input" style="padding-left:14px;">
                        @foreach(['C','C#','Db','D','D#','Eb','E','F','F#','Gb','G','G#','Ab','A','A#','Bb','B'] as $k)
                            <option value="{{ $k }}" @selected(old('key_center', $exercise->key_center) === $k)>{{ $k }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sbn-form-group" style="margin-top:10px;">
                    <label>Time Signature</label>
                    <select name="time_sig" class="sbn-search-input" style="padding-left:14px;">
                        @foreach(['2/4','3/4','4/4','6/8','12/8'] as $ts)
                            <option value="{{ $ts }}" @selected(old('time_sig', $exercise->time_sig) === $ts)>{{ $ts }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sbn-form-group" style="margin-top:10px;">
                    <label>Default BPM</label>
                    <input type="number" name="bpm_default" min="40" max="320"
                           class="sbn-search-input" style="padding-left:14px;"
                           value="{{ old('bpm_default', $exercise->bpm_default ?? 100) }}" required>
                </div>

                <div class="sbn-form-group" style="margin-top:10px;">
                    <label>Type</label>
                    <select name="type" class="sbn-search-input" style="padding-left:14px;">
                        <option value="tab_exercise" @selected(old('type', $exercise->type) === 'tab_exercise')>Tab Exercise</option>
                        <option value="chord_etude" @selected(old('type', $exercise->type) === 'chord_etude')>Chord Étude</option>
                    </select>
                </div>

                <div style="margin-top:16px; display:flex; gap:8px;">
                    <button type="submit" class="sbn-btn sbn-btn-primary" style="flex:1;"
                            @click="syncContentJson()">
                        {{ $isNew ? 'Create' : 'Save' }}
                    </button>
                    @if(!$isNew)
                    <button type="button" class="sbn-btn sbn-btn-ghost" style="color:var(--clr-danger);"
                            onclick="if(confirm('Delete this exercise?')) document.getElementById('delete-exercise-form').submit()">
                        Delete
                    </button>
                    @endif
                </div>

                @if(session('success'))
                <p style="color: var(--clr-success, green); font-size:13px; margin-top:8px;">{{ session('success') }}</p>
                @endif

                @if($errors->any())
                <div style="color: var(--clr-danger); font-size:13px; margin-top:8px;">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
                @endif

            </form>

        </div>{{-- end sidebar --}}

    </div>

</div>{{-- end x-data --}}

@if(!$isNew)
<form id="delete-exercise-form" method="POST" action="{{ route('admin.exercises.destroy', $exercise) }}" style="display:none;">
    @csrf @method('DELETE')
</form>
@endif

<script>
function exerciseEditor() {
    return {
        parsed: null,
        exerciseId: @json($exercise->id ?? null),
        _tabInitDone: false,

        init() {
            if (this.exerciseId) {
                this.loadExistingData();
            }

            // TabEditor fires this when it's mounted and waiting for data
            window.addEventListener('sbn-tab-request-init', () => {
                if (this.parsed) this._dispatchTabInit();
            });

            // Keep parsed in sync as the editor makes changes
            window.addEventListener('sbn-tab-edited', (e) => {
                if (e.detail && e.detail.parsed) {
                    this.parsed = e.detail.parsed;
                }
            });
        },

        async loadExistingData() {
            try {
                const resp = await fetch('/admin/exercises/' + this.exerciseId + '/data');
                const data = await resp.json();
                if (data.success && data.exercise) {
                    const ex = data.exercise;
                    if (ex.content_json && ex.content_json.sections) {
                        this.parsed = ex.content_json;
                    }
                    if (this.parsed) {
                        this.parsed.title         = ex.title       || this.parsed.title        || '';
                        this.parsed.key           = ex.key_center  || this.parsed.key          || 'C';
                        this.parsed.tempo         = ex.bpm_default || this.parsed.tempo        || 100;
                        this.parsed.timeSignature = ex.time_sig    || this.parsed.timeSignature || '4/4';
                        this._dispatchTabInit();
                    }
                }
            } catch (e) {
                console.error('[SBN Exercise Editor] Load error:', e);
            }
        },

        _dispatchTabInit() {
            if (this._tabInitDone) return;
            this._tabInitDone = true;
            window.dispatchEvent(new CustomEvent('sbn-tab-init', {
                detail: {
                    parsed:          this.parsed,
                    tabXml:          null,
                    videoSync:       null,
                    openVideoSidebar: false,
                }
            }));
        },

        syncContentJson() {
            // Called on Save click — writes latest editor state into the hidden field
            const el = this.$refs.contentJsonInput;
            if (el && this.parsed) {
                el.value = JSON.stringify(this.parsed);
            }
        },
    };
}
</script>
@endsection
