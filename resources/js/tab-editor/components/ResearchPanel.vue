<template>
    <div class="sbn-research-panel">
        <div class="sbn-research-section">
            <div class="sbn-research-header">
                <span class="sbn-research-icon">📚</span>
                <span class="sbn-research-title">Research Block</span>
                <span class="sbn-research-badge" :class="data.mode">{{ data.mode }} mode</span>
            </div>
            <div class="sbn-research-meta">
                <div class="sbn-research-meta-label">Canonical Source</div>
                <div class="sbn-research-meta-value">{{ data.canonical_changes_source || 'Unknown' }}</div>
            </div>
        </div>

        <!-- Notable Versions -->
        <div class="sbn-research-section" v-if="data.notable_versions && data.notable_versions.length">
            <div class="sbn-research-sub-title">Notable Versions</div>
            <div class="sbn-research-versions">
                <div v-for="(v, vi) in data.notable_versions" :key="vi" class="sbn-research-version-card">
                    <div class="sbn-research-v-header">
                        <span class="sbn-research-v-artist">{{ v.artist }}</span>
                        <span class="sbn-research-v-year" v-if="v.year">({{ v.year }})</span>
                    </div>
                    <div class="sbn-research-v-recording">{{ v.recording }}</div>
                    <div class="sbn-research-v-diff" v-if="v.differences">{{ v.differences }}</div>
                    <div class="sbn-research-v-source">Source: {{ formatSourceType(v.source_type) }}</div>
                </div>
            </div>
        </div>

        <!-- Suggested Videos -->
        <div class="sbn-research-section" v-if="data.suggested_videos && data.suggested_videos.length">
            <div class="sbn-research-sub-title">Suggested Videos</div>
            <div class="sbn-research-videos">
                <div v-for="(vid, vdi) in data.suggested_videos" :key="vdi" class="sbn-research-video-card">
                    <div class="sbn-research-vid-info">
                        <div class="sbn-research-vid-title">{{ vid.title }}</div>
                        <div class="sbn-research-vid-channel">{{ vid.channel }}</div>
                        <div class="sbn-research-vid-rationale" v-if="vid.rationale">{{ vid.rationale }}</div>
                    </div>
                    <div class="sbn-research-vid-actions">
                        <button class="sbn-research-vid-btn" @click="setVideo(vid)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3" /></svg>
                            Set as sync video
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Voicing Hints -->
        <div class="sbn-research-section" v-if="data.voicing_hints && data.voicing_hints.length">
            <div class="sbn-research-sub-title">Voicing Hints</div>
            <div class="sbn-research-voicings">
                <div v-for="(hint, hti) in data.voicing_hints" :key="hti" class="sbn-research-voicing-card">
                    <div class="sbn-research-v-header">
                        <span class="sbn-research-v-chord">{{ hint.chord }}</span>
                        <span class="sbn-research-v-frets">{{ hint.frets }}</span>
                    </div>
                    <div class="sbn-research-v-note" v-if="hint.note">{{ hint.note }}</div>
                    <button class="sbn-research-vid-btn" style="margin-top: 8px;" @click="copyVoicingToPicker(hint)">
                        Copy to chord picker
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
const props = defineProps({
    data: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['set-video', 'copy-voicing']);

function formatSourceType(type) {
    if (!type) return 'N/A';
    return type.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

function setVideo(vid) {
    const videoId = extractYouTubeId(vid.url);
    if (videoId) {
        emit('set-video', { id: videoId, type: 'youtube' });
        if (window.sbnToast) window.sbnToast('Video set to sync panel', 'success');
    } else {
        if (window.sbnToast) window.sbnToast('Invalid YouTube URL', 'error');
    }
}

function extractYouTubeId(url) {
    const regExp = /^.*(youtu\.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
    const match = url.match(regExp);
    return (match && match[2].length === 11) ? match[2] : null;
}

function copyVoicingToPicker(hint) {
    // We'll dispatch a custom event that the VoicingPicker can listen for
    document.dispatchEvent(new CustomEvent('sbn-voicing-hint-applied', {
        detail: {
            chord: hint.chord,
            frets: hint.frets,
            position: 1 // Default to 1 for hints
        }
    }));
    if (window.sbnToast) window.sbnToast('Voicing copied to clipboard / picker', 'success');
}
</script>

<style scoped>
.sbn-research-panel {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 14px;
    font-family: var(--font-body, 'DM Sans', sans-serif);
    color: var(--clr-text);
}

.sbn-research-section {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.sbn-research-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}

.sbn-research-title {
    font-weight: 700;
    font-size: 14px;
    color: var(--clr-text);
}

.sbn-research-badge {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    padding: 2px 6px;
    border-radius: 4px;
}

.sbn-research-badge.assistant {
    background: #eff6ff;
    color: #2563eb;
    border: 1px solid #dbeafe;
}

.sbn-research-meta {
    background: var(--clr-surface-2, #f9fafb);
    padding: 10px;
    border-radius: 8px;
    border: 1px solid var(--clr-border, #e5e7eb);
}

.sbn-research-meta-label {
    font-size: 10px;
    color: var(--clr-text-muted, #6b7280);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 2px;
}

.sbn-research-meta-value {
    font-weight: 600;
    font-size: 13px;
}

.sbn-research-sub-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--clr-text-muted, #6b7280);
    letter-spacing: 0.06em;
    margin-top: 4px;
}

.sbn-research-versions, .sbn-research-videos {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.sbn-research-version-card, .sbn-research-video-card {
    padding: 12px;
    border-radius: 10px;
    background: var(--clr-surface, #fff);
    border: 1px solid var(--clr-border, #e5e7eb);
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: transform 0.15s, box-shadow 0.15s;
}

.sbn-research-version-card:hover, .sbn-research-video-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
}

.sbn-research-v-header {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
}

.sbn-research-v-artist {
    font-weight: 700;
    font-size: 13px;
}

.sbn-research-v-year {
    font-size: 11px;
    color: var(--clr-text-muted);
}

.sbn-research-v-recording {
    font-size: 12px;
    color: var(--clr-text-muted);
    font-style: italic;
    margin-bottom: 4px;
}

.sbn-research-v-diff {
    font-size: 12px;
    line-height: 1.4;
    color: var(--clr-text);
    margin-bottom: 6px;
    padding: 4px 0;
    border-top: 1px solid #f3f4f6;
}

.sbn-research-v-source {
    font-size: 10px;
    color: var(--clr-text-muted);
    background: #f3f4f6;
    display: inline-block;
    padding: 1px 5px;
    border-radius: 4px;
}

.sbn-research-vid-title {
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 2px;
}

.sbn-research-vid-channel {
    font-size: 11px;
    color: var(--clr-text-muted);
}

.sbn-research-vid-rationale {
    font-size: 11px;
    color: #4b5563;
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px solid #f3f4f6;
}

.sbn-research-vid-actions {
    margin-top: 10px;
}

.sbn-research-vid-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 0;
    font-size: 12px;
    font-weight: 600;
    border-radius: 6px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    cursor: pointer;
    transition: background 0.15s;
}

.sbn-research-vid-btn:hover {
    background: #e5e7eb;
}

.sbn-research-voicings {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.sbn-research-voicing-card {
    padding: 10px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
}

.sbn-research-v-chord {
    font-weight: 700;
    font-size: 13px;
    color: #0f172a;
}

.sbn-research-v-frets {
    font-family: var(--font-mono, monospace);
    font-size: 11px;
    color: #64748b;
    background: #fff;
    padding: 2px 4px;
    border-radius: 4px;
    border: 1px solid #e2e8f0;
}

.sbn-research-v-note {
    font-size: 11px;
    color: #64748b;
    margin-top: 4px;
    line-height: 1.4;
}
</style>
