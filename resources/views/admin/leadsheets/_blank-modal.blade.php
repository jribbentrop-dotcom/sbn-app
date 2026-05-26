<div id="blank-modal" class="sbn-modal" x-data="blankModal()" x-cloak>
    <div class="sbn-modal-overlay" @click="close"></div>
    <div class="sbn-modal-content">
        <div class="sbn-modal-header">
            <h2>Create Blank Leadsheet</h2>
            <button class="sbn-modal-close" @click="close">×</button>
        </div>

        <form method="POST" action="{{ route('admin.leadsheets.create-blank') }}">
            @csrf

            <div class="sbn-modal-body">
                <div class="sbn-form-group">
                    <label for="title">Title <span class="required">*</span></label>
                    <input type="text" id="title" name="title" class="sbn-input" required maxlength="255">
                    @error('title')
                        <span class="sbn-form-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="sbn-form-group">
                    <label for="composer">Composer</label>
                    <input type="text" id="composer" name="composer" class="sbn-input" maxlength="255">
                    @error('composer')
                        <span class="sbn-form-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="sbn-form-row">
                    <div class="sbn-form-group">
                        <label for="song_key">Key <span class="required">*</span></label>
                        <select id="song_key" name="song_key" class="sbn-select" required>
                            <option value="C">C</option>
                            <option value="C#">C#</option>
                            <option value="D">D</option>
                            <option value="D#">D#</option>
                            <option value="E">E</option>
                            <option value="F">F</option>
                            <option value="F#">F#</option>
                            <option value="G">G</option>
                            <option value="G#">G#</option>
                            <option value="A">A</option>
                            <option value="A#">A#</option>
                            <option value="B">B</option>
                            <option value="Cm">Cm</option>
                            <option value="C#m">C#m</option>
                            <option value="Dm">Dm</option>
                            <option value="D#m">D#m</option>
                            <option value="Em">Em</option>
                            <option value="Fm">Fm</option>
                            <option value="F#m">F#m</option>
                            <option value="Gm">Gm</option>
                            <option value="G#m">G#m</option>
                            <option value="Am">Am</option>
                            <option value="A#m">A#m</option>
                            <option value="Bm">Bm</option>
                        </select>
                        @error('song_key')
                            <span class="sbn-form-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="sbn-form-group">
                        <label for="tempo">Tempo (BPM) <span class="required">*</span></label>
                        <input type="number" id="tempo" name="tempo" class="sbn-input" value="120" min="20" max="300" required>
                        @error('tempo')
                            <span class="sbn-form-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="sbn-form-row">
                    <div class="sbn-form-group">
                        <label for="time_signature">Time Signature <span class="required">*</span></label>
                        <select id="time_signature" name="time_signature" class="sbn-select" required>
                            <option value="4/4" selected>4/4</option>
                            <option value="3/4">3/4</option>
                            <option value="2/4">2/4</option>
                            <option value="6/8">6/8</option>
                            <option value="12/8">12/8</option>
                        </select>
                        @error('time_signature')
                            <span class="sbn-form-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="sbn-form-group">
                        <label for="rhythm">Rhythm Pattern</label>
                        <select id="rhythm" name="rhythm" class="sbn-select">
                            <option value="">None</option>
                            @foreach($rhythms as $r)
                                <option value="{{ $r->slug }}">{{ $r->name }}</option>
                            @endforeach
                        </select>
                        @error('rhythm')
                            <span class="sbn-form-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="sbn-form-group">
                    <label>Structure Mode <span class="required">*</span></label>
                    <div class="sbn-radio-group">
                        <label class="sbn-radio">
                            <input type="radio" name="structure_mode" value="simple" checked x-model="structureMode" @change="updateStructureMode">
                            <span>Simple (N bars in one section)</span>
                        </label>
                        <label class="sbn-radio">
                            <input type="radio" name="structure_mode" value="sectioned" x-model="structureMode" @change="updateStructureMode">
                            <span>Sectioned (multiple named sections)</span>
                        </label>
                    </div>
                    @error('structure_mode')
                        <span class="sbn-form-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="sbn-form-group" x-show="structureMode === 'simple'">
                    <label for="simple_bar_count">Number of Bars <span class="required">*</span></label>
                    <input type="number" id="simple_bar_count" name="simple_bar_count" class="sbn-input" value="16" min="1" max="256">
                    @error('simple_bar_count')
                        <span class="sbn-form-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="sbn-form-group" x-show="structureMode === 'sectioned'">
                    <label>Sections <span class="required">*</span></label>
                    <div id="sections-container">
                        <template x-for="(section, index) in sections" :key="index">
                            <div class="sbn-section-row">
                                <input type="text" :name="'sections[' + index + '][name]'" class="sbn-input sbn-input-sm" placeholder="Section name" x-model="section.name" required maxlength="50">
                                <input type="number" :name="'sections[' + index + '][bars]'" class="sbn-input sbn-input-sm" placeholder="Bars" x-model="section.bars" required min="1" max="64">
                                <button type="button" class="sbn-btn sbn-btn-danger sbn-btn-xs" @click="removeSection(index)" x-show="sections.length > 1">×</button>
                            </div>
                        </template>
                    </div>
                    <button type="button" class="sbn-btn sbn-btn-xs" @click="addSection">+ Add Section</button>
                    @error('sections')
                        <span class="sbn-form-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="sbn-form-group">
                    <label class="sbn-checkbox">
                        <input type="checkbox" name="pickup_bar" value="1">
                        <span>Add pickup bar</span>
                    </label>
                    @error('pickup_bar')
                        <span class="sbn-form-error">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            <div class="sbn-modal-footer">
                <button type="button" class="sbn-btn" @click="close">Cancel</button>
                <button type="submit" class="sbn-btn sbn-btn-primary">Create Blank Sheet</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
window.blankModal = function() {
    return {
        structureMode: 'simple',
        sections: [
            { name: 'Verse', bars: 8 },
            { name: 'Chorus', bars: 8 }
        ],

        init() {
            // Open modal if hash is #blank
            if (window.location.hash === '#blank') {
                this.open();
            }
        },

        open() {
            document.getElementById('blank-modal').classList.add('sbn-modal-open');
        },

        close() {
            document.getElementById('blank-modal').classList.remove('sbn-modal-open');
            window.location.hash = '';
        },

        updateStructureMode() {
            // Triggered when radio changes
        },

        addSection() {
            this.sections.push({ name: '', bars: 8 });
        },

        removeSection(index) {
            if (this.sections.length > 1) {
                this.sections.splice(index, 1);
            }
        }
    };
};
</script>
@endpush

@push('styles')
<style>
.sbn-modal {
    display: none;
}

.sbn-modal.sbn-modal-open {
    display: flex;
}

.sbn-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
}

.sbn-modal-content {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    border-radius: 8px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    z-index: 1001;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
}

.sbn-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
}

.sbn-modal-header h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
}

.sbn-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
}

.sbn-modal-close:hover {
    background: #f3f4f6;
}

.sbn-modal-body {
    padding: 20px;
}

.sbn-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 16px 20px;
    border-top: 1px solid #e5e7eb;
}

.sbn-form-group {
    margin-bottom: 16px;
}

.sbn-form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    font-size: 14px;
}

.sbn-form-group .required {
    color: #dc2626;
}

.sbn-input,
.sbn-select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 14px;
}

.sbn-input:focus,
.sbn-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.sbn-form-row {
    display: flex;
    gap: 12px;
}

.sbn-form-row .sbn-form-group {
    flex: 1;
}

.sbn-form-error {
    display: block;
    color: #dc2626;
    font-size: 12px;
    margin-top: 4px;
}

.sbn-radio-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.sbn-radio {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.sbn-radio input[type="radio"] {
    margin: 0;
}

.sbn-section-row {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
}

.sbn-input-sm {
    flex: 1;
}

.sbn-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.sbn-checkbox input[type="checkbox"] {
    margin: 0;
}

</style>
@endpush
