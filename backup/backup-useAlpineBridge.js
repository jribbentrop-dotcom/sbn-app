/**
 * SBN Tab Editor — Alpine Bridge
 *
 * Handles communication between the Alpine leadsheet editor
 * and the Vue tab editor via CustomEvents.
 *
 * Events received (Alpine → Vue):
 *   'sbn-tab-init'              — initial data on mount (parsed, tabXml, etc.)
 *   'sbn-chords-changed'        — chord grid was edited in Alpine
 *   'sbn-tab-save-request'      — Alpine is about to save; Vue must respond with XML
 *   'sbn-tab-voicing-applied'   — user selected a voicing in the picker (Step 5)
 *   'sbn-tab-request-snapshot'  — Alpine requests a serialized tab model snapshot (synchronous)
 *   'sbn-tab-restore-snapshot'  — Alpine restores a tab model snapshot (structural undo/redo)
 *
 * Events dispatched (Vue → Alpine):
 *   'sbn-tab-edited'            — tab edit changed a chord voicing
 *   'sbn-tab-save-response'     — reply to save-request; detail.xml contains MusicXML string
 *   'sbn-tab-open-picker'       — chord name clicked; open voicing picker (Step 5)
 *   'sbn-tab-chord-update'      — identified chord name; Alpine updates grid (Step 3)
 *   'sbn-tab-identify-result'   — chord identified as different name; Alpine shows confirm UI
 */

import { ref, onMounted, onUnmounted } from 'vue';

// ── Module-level singleton guard ───────────────────────────────────────────
// Prevents multiple Vue app instances from each registering their own
// event listeners. Only the first mounted instance owns the bridge.
let _bridgeOwned = false;
const _globalInitialized = ref(false);

export function useAlpineBridge() {
    // ── Received data ──────────────────────────────────────
    const melody        = ref(null);     // parsed.melody array
    const sections      = ref([]);       // parsed.sections array
    const timeSignature = ref('4/4');
    const songKey       = ref('C');
    const title         = ref('');
    const composer      = ref('');
    const tabXml        = ref(null);
    const repeatMarkers = ref({});
    const voltaEndings  = ref({});
    const initialized   = _globalInitialized; // shared singleton

    // ── Event handlers ─────────────────────────────────────

    function handleTabInit(e) {
        const d = e.detail;
        if (d.parsed) {
            melody.value        = d.parsed.melody || null;
            sections.value      = d.parsed.sections || [];
            timeSignature.value = d.parsed.timeSignature || '4/4';
            songKey.value       = d.parsed.key || 'C';
            title.value         = d.parsed.title || '';
            composer.value      = d.parsed.composer || '';
            repeatMarkers.value = d.parsed.repeatMarkers || {};
            voltaEndings.value  = d.parsed.voltaEndings || {};
        }
        if (d.tabXml !== undefined) {
            tabXml.value = d.tabXml;
        }
        initialized.value = true;
        // Ack so Alpine knows Vue received the data and can stop retrying.
        document.dispatchEvent(new CustomEvent('sbn-tab-init-ack'));
    }

    function handleChordsChanged(e) {
        const d = e.detail;
        if (d.sections) {
            // Shallow-copy the array to force Vue's reference watch to fire
            // even when Alpine mutates the same object in-place (e.g. chord-update).
            sections.value = [...d.sections];
        }
    }

    // ── Save state ─────────────────────────────────────────
    // onSaveRequest is set by TabEditor so the bridge can call back into
    // the model serializer without importing it here directly.
    let _onSaveRequest = null;

    function setSaveHandler(fn) {
        _onSaveRequest = fn;
    }

    // ── Voicing-applied state ───────────────────────────────
    // Set by TabEditor; called when Alpine confirms a voicing selection.
    let _onVoicingApplied = null;

    function setVoicingAppliedHandler(fn) {
        _onVoicingApplied = fn;
    }

    function handleVoicingApplied(e) {
        console.log('[SBN] handleVoicingApplied received', e.detail);
        if (_onVoicingApplied) {
            _onVoicingApplied(e.detail);
        } else {
            console.warn('[SBN] handleVoicingApplied: no handler registered');
        }
    }

    function handleSaveRequest() {
        if (!_onSaveRequest) {
            console.warn('[SBN] sbn-tab-save-request received but no save handler registered');
            return;
        }
        try {
            const xml = _onSaveRequest();
            document.dispatchEvent(new CustomEvent('sbn-tab-save-response', {
                detail: { xml },
            }));
        } catch (err) {
            console.error('[SBN] Tab XML serialization failed:', err);
            document.dispatchEvent(new CustomEvent('sbn-tab-save-response', {
                detail: { xml: null, error: String(err) },
            }));
        }
    }

    // ── Structural hint ────────────────────────────────────
    // When the tab editor dispatches sbn-tab-structure-request, Alpine will
    // perform the mutation and then fire sbn-chords-changed. We intercept the
    // request here first so patchStructure() knows exactly what changed and can
    // do a surgical splice instead of a blind positional rebuild.
    //
    // Format: { action: 'insertBarAfter'|'insertBarBefore'|'deleteBar'|'deleteSelection',
    //           measureIndex: number, selectedIndices?: number[] }
    // Cleared by patchStructure() after consumption.
    const pendingStructureHint = ref(null);

    function handleStructureRequest(e) {
        pendingStructureHint.value = e.detail || null;
    }

    // ── Snapshot capture (synchronous) ─────────────────────
    // Alpine dispatches sbn-tab-request-snapshot before structural mutations.
    // The handler writes the serialized model directly onto e.detail so
    // Alpine can read it synchronously after dispatch returns.
    let _onSnapshotRequest = null;

    function setSnapshotHandler(fn) {
        console.log('[SBN bridge] setSnapshotHandler called, fn=', !!fn, '_bridgeOwned=', _bridgeOwned);
        _onSnapshotRequest = fn;
    }

    function handleSnapshotRequest(e) {
        console.log('[SBN bridge] handleSnapshotRequest fired, _onSnapshotRequest=', !!_onSnapshotRequest);
        if (_onSnapshotRequest) {
            e.detail.tabSnapshot = _onSnapshotRequest();
            console.log('[SBN bridge] snapshot captured, sections=', e.detail.tabSnapshot?.sections?.length);
        }
    }

    // ── Snapshot restore (structural undo/redo) ─────────────
    // Alpine dispatches sbn-tab-restore-snapshot with the serialized model.
    // Vue directly assigns it, bypassing the sections watcher / patchStructure.
    let _onSnapshotRestore = null;

    function setRestoreHandler(fn) {
        _onSnapshotRestore = fn;
    }

    function handleSnapshotRestore(e) {
        const snapshot = e.detail?.snapshot;
        if (snapshot && _onSnapshotRestore) {
            _onSnapshotRestore(snapshot);
        }
    }

    function emitTabEdited(detail) {
        document.dispatchEvent(new CustomEvent('sbn-tab-edited', { detail }));
    }

    function emitChordUpdate(detail) {
        document.dispatchEvent(new CustomEvent('sbn-tab-chord-update', { detail }));
    }

    // ── Lifecycle ──────────────────────────────────────────

    onMounted(() => {
        // Only one bridge instance should own the event listeners.
        // If a second Vue app mounts (retry race), it shares state but doesn't
        // double-register listeners.
        if (_bridgeOwned) {
            console.warn('[SBN] useAlpineBridge: duplicate mount detected — secondary instance will share state but skip listener registration');
            return;
        }
        console.log('[SBN bridge] onMounted — claiming bridge ownership');
        _bridgeOwned = true;

        document.addEventListener('sbn-tab-init', handleTabInit);
        document.addEventListener('sbn-chords-changed', handleChordsChanged);
        document.addEventListener('sbn-tab-save-request', handleSaveRequest);
        document.addEventListener('sbn-tab-voicing-applied', handleVoicingApplied);
        document.addEventListener('sbn-tab-structure-request', handleStructureRequest);
        document.addEventListener('sbn-tab-request-snapshot', handleSnapshotRequest);
        document.addEventListener('sbn-tab-restore-snapshot', handleSnapshotRestore);

        // Request initial data from Alpine.
        // Alpine may not have finished loadExistingData() yet, so retry every 200ms
        // until we receive a response (initialized becomes true) or give up after 5s.
        function requestInit() {
            document.dispatchEvent(new CustomEvent('sbn-tab-request-init'));
        }

        requestInit();

        let attempts = 0;
        const maxAttempts = 25; // 25 × 200ms = 5s
        const retryTimer = setInterval(() => {
            if (initialized.value) {
                clearInterval(retryTimer);
                return;
            }
            attempts++;
            if (attempts >= maxAttempts) {
                clearInterval(retryTimer);
                console.warn('[SBN] useAlpineBridge: no sbn-tab-init response after 5s');
                return;
            }
            requestInit();
        }, 200);
    });

    onUnmounted(() => {
        if (!_bridgeOwned) return; // secondary instance, nothing to clean up
        _bridgeOwned = false;
        _globalInitialized.value = false; // allow re-init if app fully remounts

        document.removeEventListener('sbn-tab-init', handleTabInit);
        document.removeEventListener('sbn-chords-changed', handleChordsChanged);
        document.removeEventListener('sbn-tab-save-request', handleSaveRequest);
        document.removeEventListener('sbn-tab-voicing-applied', handleVoicingApplied);
        document.removeEventListener('sbn-tab-structure-request', handleStructureRequest);
        document.removeEventListener('sbn-tab-request-snapshot', handleSnapshotRequest);
        document.removeEventListener('sbn-tab-restore-snapshot', handleSnapshotRestore);
    });

    return {
        // State
        melody,
        sections,
        timeSignature,
        songKey,
        title,
        composer,
        tabXml,
        repeatMarkers,
        voltaEndings,
        initialized,

        // Actions
        emitTabEdited,
        emitChordUpdate,
        setSaveHandler,
        setVoicingAppliedHandler,
        setSnapshotHandler,
        setRestoreHandler,

        // Phase 2b: request structural changes (insert/delete bar) via Alpine
        emitStructureRequest,

        // Hint consumed by patchStructure() for surgical tab model updates
        pendingStructureHint,
    };
}

/**
 * Dispatch a structural operation request to Alpine.
 * Alpine owns parsed.sections — it performs the mutation and fires
 * sbn-chords-changed, which Vue picks up via patchStructure().
 *
 * @param {string} action        - 'insertBarAfter' | 'insertBarBefore' | 'deleteBar' | 'deleteSelection'
 * @param {number} measureIndex  - global measure index
 * @param {object} extra         - additional payload (e.g. selectedIndices for deleteSelection)
 */
function emitStructureRequest(action, measureIndex, extra = {}) {
    document.dispatchEvent(new CustomEvent('sbn-tab-structure-request', {
        detail: { action, measureIndex, ...extra },
    }));
}
