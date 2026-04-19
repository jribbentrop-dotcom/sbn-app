/**
 * SBN Tab Editor — Alpine Bridge
 *
 * Handles communication between the Alpine leadsheet editor
 * and the Vue tab editor via CustomEvents.
 *
 * Events received (Alpine -> Vue):
 *   'sbn-tab-init'              - initial data on mount (parsed, tabXml, etc.)
 *   'sbn-tab-init'              - initial data on mount (parsed, tabXml, etc.)
 *   'sbn-tab-save-request'      - Alpine is about to save; Vue must respond with XML
 *   'sbn-tab-structure-request' - structural operations initiated by tab editor
 *
 *
 * Events dispatched by this composable (Vue -> Alpine):
 *   'sbn-tab-init-ack'          - confirms Vue received init payload
 *   'sbn-tab-save-response'     - reply to save-request; detail.xml contains MusicXML
 *
 * Note: other Vue->Alpine events (picker open, identify result, sections sync)
 * are dispatched directly from TabEditor.vue.
 */

import { ref, onMounted, onUnmounted } from 'vue';

// ── Module-level singleton guard ───────────────────────────────────────────
// Prevents multiple Vue app instances from each registering their own
// event listeners. Only the first mounted instance owns the bridge.
let _bridgeOwned = false;
const _globalInitialized = ref(false);

// ── Tab model reference (set after bridge initialization) ───────────────
let _tabModel = null;

export function useAlpineBridge() {
    // ── Received data ──────────────────────────────────────
    const melody        = ref(null);     // parsed.melody array
    const sections      = ref([]);       // parsed.sections array
    const chordVoicings = ref({});       // parsed.chordVoicings — mirrored into TabModel
    const timeSignature = ref('4/4');
    const songKey       = ref('C');
    const title         = ref('');
    const composer      = ref('');
    const tabXml        = ref(null);
    const repeatMarkers = ref({});
    const lineBreaks    = ref({});      // Added: Track layout in Vue
    const voltaEndings  = ref({});
    const videoSync     = ref(null);   // Phase D: passed through from sbn-tab-init
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
            lineBreaks.value    = d.parsed.lineBreaks || {}; // Sync layout
            chordVoicings.value = JSON.parse(JSON.stringify(d.parsed.chordVoicings || {}));
        }
        if (d.tabXml !== undefined) {
            tabXml.value = d.tabXml;
        }
        if (d.videoSync !== undefined) {
            videoSync.value = d.videoSync;
        }
        initialized.value = true;
        // Ack so Alpine knows Vue received the data and can stop retrying.
        document.dispatchEvent(new CustomEvent('sbn-tab-init-ack'));
    }


    // ── Save state ─────────────────────────────────────────
    // onSaveRequest is set by TabEditor so the bridge can call back into
    // the model serializer without importing it here directly.
    let _onSaveRequest = null;

    function setSaveHandler(fn) {
        _onSaveRequest = fn;
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



    // Structure request handler for tab-initiated structural operations
    let _onStructureRequest = null;

    function setStructureHandler(fn) {
        _onStructureRequest = fn;
    }

    function handleStructureRequest(e) {
        const detail = e.detail;
        if (detail && _onStructureRequest) {
            _onStructureRequest(detail);
        }
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
        _bridgeOwned = true;

        document.addEventListener('sbn-tab-init', handleTabInit);
        document.addEventListener('sbn-tab-save-request', handleSaveRequest);
        document.addEventListener('sbn-tab-structure-request', handleStructureRequest);

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
        document.removeEventListener('sbn-tab-save-request', handleSaveRequest);
        document.removeEventListener('sbn-tab-structure-request', handleStructureRequest);
    });

    return {
        // State
        melody,
        sections,
        chordVoicings,
        timeSignature,
        songKey,
        title,
        composer,
        tabXml,
        repeatMarkers,
        lineBreaks,
        voltaEndings,
        initialized,

        // Actions
        setSaveHandler,
        setStructureHandler,

        // Set tab model reference for structural operations
        setTabModel: (model) => { _tabModel = model; },

        // Phase D
        videoSync,
    };
}
