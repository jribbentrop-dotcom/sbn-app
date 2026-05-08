@extends('layouts.admin')

@section('title', 'Progression Builder: Machine Room')

@section('actions')
    <a href="{{ route('admin.progressions.index') }}" class="sbn-btn sbn-btn-secondary">
        &larr; Progressions
    </a>
@endsection

@push('styles')
<style>
.mr-container { display: flex; flex-direction: column; gap: 20px; height: calc(100vh - 100px); }
.mr-top-bar { display: flex; gap: 15px; align-items: center; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.mr-layout { display: flex; gap: 20px; flex: 1; min-height: 0; }
.mr-settings { flex: 0 0 380px; overflow-y: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.mr-preview { flex: 1; overflow-y: auto; background: #f8fafc; padding: 20px; border-radius: 8px; box-shadow: inset 0 1px 3px rgba(0,0,0,0.05); }

.mr-group { margin-bottom: 30px; }
.mr-group h3 { margin: 0 0 15px 0; font-size: 16px; font-weight: 600; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
.mr-row { margin-bottom: 12px; display: flex; align-items: flex-start; justify-content: space-between; font-size: 13px; }
.mr-row label { flex: 1; padding-right: 10px; color: #475569; }
.mr-control { width: 180px; }
.mr-control select, .mr-control input[type="text"], .mr-control input[type="number"] { width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; }
.mr-control input[type="checkbox"] { margin-top: 3px; }
.mr-control input[type="range"] { width: 100%; }

.mr-prog { background: #fff; border-radius: 8px; padding: 15px; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.mr-prog-header { display: flex; justify-content: space-between; margin-bottom: 15px; }
.mr-prog-title { font-weight: 600; color: #0f172a; }
.mr-prog-meta { font-size: 12px; color: #64748b; }
.mr-chords { display: flex; gap: 15px; flex-wrap: wrap; }
.mr-chord { display: flex; flex-direction: column; align-items: center; width: 100px; }
.mr-chord-name { font-weight: bold; font-size: 14px; margin-bottom: 8px; color: #1e293b; }
.mr-diagram svg { width: 100%; height: auto; }
.mr-diag { margin-top: 15px; padding: 10px; background: #f1f5f9; border-radius: 6px; font-family: monospace; font-size: 11px; color: #475569; white-space: pre-wrap; }

.sbn-btn-sm { padding: 4px 10px; font-size: 12px; }
</style>
@endpush

@section('content')
<div x-data="machineRoom()" x-cloak class="mr-container">

    <div class="mr-top-bar">
        <label style="font-weight: 600; font-size: 14px;">Archetype:</label>
        <select x-model="selectedArchetype" @change="loadArchetype()" class="sbn-select" style="width: 250px;">
            <option value="">-- Current Settings --</option>
            <template x-for="arch in archetypes" :key="arch.slug">
                <option :value="arch.slug" x-text="arch.name"></option>
            </template>
        </select>
        <button class="sbn-btn sbn-btn-primary sbn-btn-sm" @click="saveArchetype()">Save as Archetype</button>
        <button class="sbn-btn sbn-btn-secondary sbn-btn-sm" @click="restoreDefaults()">Restore Defaults</button>
        <span x-show="saving" style="font-size: 12px; color: #64748b;">Saving...</span>
    </div>

    <div class="mr-layout">
        <!-- SETTINGS PANEL -->
        <div class="mr-settings">
            
            <div class="mr-group">
                <h3>Global Cost Weights</h3>
                <template x-for="(weight, key) in settings.cost_weights" :key="key">
                    <div class="mr-row">
                        <label x-text="key"></label>
                        <div class="mr-control" style="display: flex; align-items: center; gap: 10px;">
                            <input type="range" min="0" max="1" step="0.05" x-model.number="settings.cost_weights[key]" @change="updateSetting('cost_weights', settings.cost_weights)">
                            <span style="width: 30px; text-align: right;" x-text="settings.cost_weights[key].toFixed(2)"></span>
                        </div>
                    </div>
                </template>
            </div>

            <div class="mr-group">
                <h3>Algorithm Toggles</h3>
                <div class="mr-row">
                    <label>Repeated Chord Reuse</label>
                    <div class="mr-control">
                        <input type="checkbox" x-model="settings.repeated_chord_reuse" @change="updateSetting('repeated_chord_reuse', settings.repeated_chord_reuse)">
                    </div>
                </div>
            </div>

            <div class="mr-group">
                <h3>Per-Category Defaults</h3>
                <div class="mr-row" style="margin-bottom: 20px;">
                    <label>Select Category to Edit:</label>
                    <div class="mr-control">
                        <select x-model="editCategory">
                            <option value="jazz">Jazz</option>
                            <option value="blues">Blues</option>
                            <option value="pop">Pop</option>
                            <option value="classical">Classical</option>
                            <option value="modal">Modal</option>
                            <option value="latin">Latin</option>
                        </select>
                    </div>
                </div>

                <div x-show="editCategory">
                    <div class="mr-row">
                        <label>Register Target (Fret 0-12)</label>
                        <div class="mr-control">
                            <input type="number" min="0" max="12" x-model.number="settings.register_targets[editCategory].target" @change="updateSetting('register_targets', settings.register_targets)">
                        </div>
                    </div>
                    <div class="mr-row">
                        <label>Register Weight</label>
                        <div class="mr-control">
                            <input type="number" min="0" max="1" step="0.05" x-model.number="settings.register_targets[editCategory].weight" @change="updateSetting('register_targets', settings.register_targets)">
                        </div>
                    </div>
                    <div class="mr-row">
                        <label>Default Voicing Style</label>
                        <div class="mr-control">
                            <select x-model="settings.default_voicing_style[editCategory]" @change="updateSetting('default_voicing_style', settings.default_voicing_style)">
                                <option value="auto">Auto</option>
                                <option value="drop2_high">Drop 2 High</option>
                                <option value="drop2_mid">Drop 2 Mid</option>
                                <option value="drop3_low">Drop 3 Low</option>
                                <option value="drop3_mid">Drop 3 Mid</option>
                                <option value="roote">Root E</option>
                                <option value="roota">Root A</option>
                                <option value="shell_low">Shell Low</option>
                                <option value="mixed">Mixed</option>
                            </select>
                        </div>
                    </div>
                    <div class="mr-row">
                        <label>Root Position Only</label>
                        <div class="mr-control">
                            <input type="checkbox" :checked="settings.root_only_default[editCategory] || false" @change="toggleCategoryArray('root_only_default', editCategory, $event.target.checked)">
                        </div>
                    </div>
                    <div class="mr-row">
                        <label>Pass 2 Eligible (Extensions)</label>
                        <div class="mr-control">
                            <input type="checkbox" :checked="settings.pass2_eligible.includes(editCategory)" @change="toggleArrayItem('pass2_eligible', editCategory, $event.target.checked)">
                        </div>
                    </div>
                    <div class="mr-row">
                        <label>Tonic Widening</label>
                        <div class="mr-control">
                            <input type="checkbox" :checked="settings.tonic_widen_default[editCategory] || false" @change="toggleCategoryArray('tonic_widen_default', editCategory, $event.target.checked)">
                        </div>
                    </div>
                    <div class="mr-row">
                        <label>Pass 1 Plain Voicing Filter Disabled</label>
                        <div class="mr-control">
                            <input type="checkbox" :checked="settings.pass1_extensions_allowed.includes(editCategory)" @change="toggleArrayItem('pass1_extensions_allowed', editCategory, $event.target.checked)">
                        </div>
                    </div>
                    <div class="mr-row">
                        <label>Voicing Pool</label>
                        <div class="mr-control" style="font-size: 12px; display:flex; flex-direction:column; gap:4px;">
                            <template x-for="pool in ['drop2', 'drop3', 'shell', 'closed', 'archetype', 'closed_triads', 'spread_triads', 'quartal', 'custom']" :key="pool">
                                <label style="display:flex; align-items:center; gap:5px;">
                                    <input type="checkbox" :checked="(settings.category_pools[editCategory] || []).includes(pool)" @change="toggleCategoryPoolItem(editCategory, pool, $event.target.checked)">
                                    <span x-text="pool"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- PREVIEW PANEL -->
        <div class="mr-preview" :style="previewLoading ? 'opacity: 0.5; pointer-events: none; transition: opacity 0.2s;' : 'transition: opacity 0.2s;'">
            <template x-for="prog in corpus" :key="prog.name">
                <div class="mr-prog">
                    <div class="mr-prog-header">
                        <div class="mr-prog-title" x-text="prog.name"></div>
                        <div class="mr-prog-meta" x-text="prog.category + ' • Key of ' + prog.key"></div>
                    </div>
                    
                    <div x-show="prog.error" style="color: #ef4444; font-size: 12px; padding: 10px; background: #fef2f2; border-radius: 4px;" x-text="prog.error"></div>
                    
                    <div class="mr-chords" x-show="!prog.error">
                        <template x-for="(chord, idx) in prog.chords" :key="idx + '-' + (chord.voicing ? chord.voicing.diagram_id : 'null')">
                            <div class="mr-chord">
                                <div class="mr-chord-name" x-html="formatChordHtml(chord.chord_name)"></div>
                                <div class="mr-diagram" x-html="renderDiagram(chord.voicing)"></div>
                            </div>
                        </template>
                    </div>

                    <div class="mr-diag" x-show="!prog.error && prog.diagnostics">
                        Path Cost: <span x-text="parseFloat(prog.diagnostics.path_cost).toFixed(4)"></span>
                        <template x-if="prog.diagnostics.phase_e">
                            <div>
                                Pass 2 Won: <span x-text="prog.diagnostics.phase_e.pass2_won ? 'Yes' : 'No'"></span>
                                <span x-show="prog.diagnostics.phase_e.pass2_fired_resolutions && prog.diagnostics.phase_e.pass2_fired_resolutions.length">
                                    • Resolutions: <span x-text="prog.diagnostics.phase_e.pass2_fired_resolutions.join(', ')"></span>
                                </span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script src="{{ asset('js/sbn-chord-name.js') }}"></script>
<script src="{{ asset('js/chords.js') }}"></script>
<script>
function formatChordHtml(name) {
    if (!name) return '';
    if (typeof sbnFormatChord === 'function') return sbnFormatChord(name);
    return name;
}

document.addEventListener('alpine:init', () => {
    Alpine.data('machineRoom', () => ({
        settings: {},
        archetypes: [],
        selectedArchetype: '',
        corpus: [],
        editCategory: 'jazz',
        saving: false,
        previewLoading: false,
        debounceTimer: null,

        async init() {
            await this.fetchArchetypes();
            await this.fetchSettings();
            await this.fetchPreview();
        },

        async fetchSettings() {
            const res = await fetch('/api/admin/progressions/builder/settings');
            const data = await res.json();
            this.settings = data.settings;
        },

        async fetchArchetypes() {
            const res = await fetch('/api/admin/progressions/builder/archetypes');
            const data = await res.json();
            this.archetypes = data.archetypes;
        },

        async fetchPreview() {
            this.previewLoading = true;
            const res = await fetch('/api/admin/progressions/builder/preview');
            const data = await res.json();
            this.corpus = data.corpus;
            this.previewLoading = false;
        },

        async updateSetting(key, value) {
            this.saving = true;
            await fetch('/api/admin/progressions/builder/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ key, value })
            });
            this.saving = false;
            
            // Debounce preview fetch
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => this.fetchPreview(), 500);
        },

        toggleArrayItem(key, val, add) {
            let arr = this.settings[key] || [];
            if (add && !arr.includes(val)) arr.push(val);
            if (!add && arr.includes(val)) arr = arr.filter(i => i !== val);
            this.settings[key] = arr;
            this.updateSetting(key, arr);
        },

        toggleCategoryArray(key, cat, val) {
            if (!this.settings[key]) this.settings[key] = {};
            this.settings[key][cat] = val;
            this.updateSetting(key, this.settings[key]);
        },

        toggleCategoryPoolItem(cat, pool, add) {
            if (!this.settings.category_pools[cat]) this.settings.category_pools[cat] = [];
            let arr = this.settings.category_pools[cat];
            if (add && !arr.includes(pool)) arr.push(pool);
            if (!add && arr.includes(pool)) arr = arr.filter(i => i !== pool);
            this.settings.category_pools[cat] = arr;
            this.updateSetting('category_pools', this.settings.category_pools);
        },

        async loadArchetype() {
            if (!this.selectedArchetype) return;
            this.saving = true;
            const res = await fetch('/api/admin/progressions/builder/archetypes/load', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ slug: this.selectedArchetype })
            });
            const data = await res.json();
            this.settings = data.settings;
            this.saving = false;
            this.fetchPreview();
        },

        async saveArchetype() {
            const name = prompt("Enter a name for this archetype:");
            if (!name) return;
            this.saving = true;
            const res = await fetch('/api/admin/progressions/builder/archetypes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ name, description: '' })
            });
            const data = await res.json();
            await this.fetchArchetypes();
            this.selectedArchetype = data.slug;
            this.saving = false;
        },

        async restoreDefaults() {
            if (!confirm("Are you sure you want to restore all settings to their defaults?")) return;
            this.saving = true;
            const res = await fetch('/api/admin/progressions/builder/restore-defaults', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            const data = await res.json();
            this.settings = data.settings;
            this.selectedArchetype = '';
            this.saving = false;
            this.fetchPreview();
        },

        renderDiagram(voicing) {
            if (!voicing) return '<span>+</span>';
            return sbnRenderDiagramSVG(voicing);
        }
    }));
});
</script>
@endpush
