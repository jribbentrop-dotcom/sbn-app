# SBN Course Player - Sample Loading & Synth Fallback Findings

## 1. SbnPercussion (assets/js/sbn-percussion.js)
**Purpose:** One-shot sample-based percussion engine for rhythm patterns

### Sample Loading Logic
- **Entry Point:** `SbnPercussion.init(samplesBaseUrl)`
- **Location:** Lines 59-115
- Loads WAV files from structured base URL:
  ```
  {baseUrl}/shaker_soft.wav, shaker_accent.wav
  {baseUrl}/tamborim_soft.wav, tamborim_accent.wav
  {baseUrl}/kick_soft.wav, kick_accent.wav
  {baseUrl}/hihat_brush_soft.wav, hihat_brush_accent.wav
  {baseUrl}/brush_snare_soft.wav, brush_snare_accent.wav
  ```
- Loads in parallel using `fetch()` + `decodeAudioData()`
- Non-fatal error handling: missing samples just stay silent (line 101-104)

### AudioContext Resolution (Lines 178-191)
```javascript
_getAudioContext: function () {
    // Prefer Tone.js context so we share the same clock as SbnAudio
    if (typeof Tone !== 'undefined' && Tone.getContext) {
        try {
            var raw = Tone.getContext().rawContext;
            if (raw) return raw;
        } catch (e) { /* fall through */ }
    }
    // Standalone fallback (e.g. rhythm shortcode pages without Tone loaded)
    if (!this._standaloneCtx) {
        this._standaloneCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    return this._standaloneCtx;
}
```

### Sample Fallback Logic (Line 131)
```javascript
var buf = bufSet[variant] || bufSet['soft']; // fallback to soft if accent missing
```

---

## 2. RhythmPlayer (assets/js/course-player.js)
**Purpose:** Rhythm pattern player with MP3 + synth blend capability

### MP3 Sample Loading (Lines 840-860)
```javascript
async loadMp3() {
    if (!this.mp3Url || this.mp3Loading || this.mp3Loaded) return;
    this.mp3Loading = true;
    try {
        this.initAudio();
        const response = await fetch(this.mp3Url);
        const arrayBuffer = await response.arrayBuffer();
        this.mp3Buffer = await this.audioContext.decodeAudioData(arrayBuffer);
        this.mp3Loaded = true;
    } catch (error) {
        console.error('[Rhythm] Failed to load MP3:', error);
    }
}
```

### Synth Fallback Sounds (Lines 1037-1148)
When percussion samples unavailable, falls back to synthesized sounds:

**Clave Synth:** `playClave(time, pitch)` (Lines 1074-1100)
- High: 2500Hz → 1800Hz exponential decay
- Low: 1800Hz → 1200Hz exponential decay
- Triangle wave with bandpass filter

**Guitar Synth:** `playNote(time, freq, dur, vol)` (Lines 1102-1114)
- Sine wave for pitched notes
- Used for 493.88Hz (B4) and 220Hz (A3) thumb/finger distinction

**Muted String:** `playMuted(time, pitch)` (Lines 1116-1136)
- Square wave with lowpass filter
- 110Hz (low) / 330Hz (high)

**Hi-Hat:** `playHiHat(time)` (Lines 1138-1162)
- White noise with bandpass (8000Hz) + highpass (7000Hz)

### Blend Control (Lines 815-838)
- Blend slider controls mix between MP3 (0%) and synth (100%)
- `synthVol = (1 - (blend / 100)) * 0.8`
- `mp3Vol = (blend / 100) * 0.8`

### Percussion Initialization (Lines 807-810, 910-916)
```javascript
// Pre-load percussion samples
if (this.hasPerc && this.samplesUrl && window.SbnPercussion && !SbnPercussion.ready) {
    SbnPercussion.init(this.samplesUrl);
}

// Resume / init percussion engine during play()
if (this.hasPerc && window.SbnPercussion) {
    SbnPercussion.resume();
    if (!SbnPercussion.ready && !SbnPercussion.loading && this.samplesUrl) {
        SbnPercussion.init(this.samplesUrl);
    }
}
```

---

## 3. Rhythm Patterns Admin (assets/js/rhythm-patterns-admin.js)
**Purpose:** Admin interface for pattern editing with audio preview

### Sample vs Synth Fallback (Lines 242-287)
```javascript
// Init sample-based percussion if available, fall back to synth clicks
const percTop  = $('#sbnPatternPercTop').val()  || 'none';
const percBass = $('#sbnPatternPercBass').val() || 'none';
const hasPerc  = (percTop !== 'none' || percBass !== 'none');
const samplesUrl = (typeof sbnRhythm !== 'undefined' && sbnRhythm.samplesUrl) ? sbnRhythm.samplesUrl : '';

if (hasPerc && samplesUrl && window.SbnPercussion) {
    if (!SbnPercussion.ready && !SbnPercussion.loading) {
        SbnPercussion.init(samplesUrl);
    }
    SbnPercussion.resume();
} else {
    await AudioPreview.init(); // Tone.js synth fallback
}

// Playback decision (Lines 280-287)
if (hasPerc && window.SbnPercussion && SbnPercussion.ready) {
    const now = (typeof Tone !== 'undefined') ? Tone.now() : SbnPercussion._audioCtx.currentTime;
    if (rHit && percTop  !== 'none') SbnPercussion.playHit(percTop,  r === 'X', now, r === 'X' ? 1.0 : 0.78);
    if (tHit && percBass !== 'none') SbnPercussion.playHit(percBass, false,     now, 0.85);
} else {
    if (tHit) AudioPreview.playHit(true,  false);
    if (rHit) AudioPreview.playHit(false, r === 'X');
}
```

### AudioPreview Synth (Lines 20-60)
Tone.js PolySynth for admin preview when samples unavailable.

---

## 4. SbnAudio / Leadsheet (assets/js/sbn-audio.js, leadsheet.js)
**Purpose:** Guitar chord/plucked-string synthesis

### Architecture
- **Pure synthesis** - no samples loaded
- Uses Tone.js PolySynth with custom partials
- Shared engine between leadsheet and chord library

### Signal Chain (sbn-audio.js, Lines 84-120)
```
PolySynth → EQ3 → Reverb → Limiter → Destination
```

### Synth Configuration
```javascript
self.synth = new Tone.PolySynth(Tone.Synth, {
    maxPolyphony: 64,
    oscillator: {
        type: 'custom',
        partials: [1.0, 0.4, 0.15, 0.05]  // Warm fundamental + overtones
    },
    envelope: {
        attack:  0.002,
        decay:   0.08,
        sustain: 0.25,
        release: 0.6
    },
    volume: -16
});
```

### Leadsheet AudioEngine Wrapper (leadsheet.js, Lines 24-71)
Thin wrapper around SbnAudio - no sample loading, just synthesis.

---


---

## Summary Table

| Component | Sample Loading | Synth Fallback | Context |
|-----------|---------------|----------------|---------|
| SbnPercussion | WAV files (fetch/decode) | Soft variant fallback | Rhythm percussion |
| RhythmPlayer | MP3 (fetch/decodeAudioData) | Clave/Guitar/Muted/HiHat synth | Rhythm patterns |
| RhythmAdmin | SbnPercussion init | Tone.js PolySynth | Admin preview |
| SbnAudio | None (pure synthesis) | N/A - always synth | Guitar chords |
| Leadsheet | None (uses SbnAudio) | N/A - always synth | Lead sheet playback |

---

## Key Files
1. `assets/js/sbn-percussion.js` - Percussion sample loading
2. `assets/js/course-player.js` - Rhythm player with MP3/synth blend
3. `assets/js/rhythm-patterns-admin.js` - Admin with sample/synth fallback
4. `assets/js/sbn-audio.js` - Guitar synth engine
5. `assets/js/leadsheet.js` - Leadsheet wrapper (no samples)
