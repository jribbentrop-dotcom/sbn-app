# SBN Teaching Hub — Migration Reference

> **Purpose:** Working reference for the frontend (Phase 8+) migration from the legacy WordPress plugin to Laravel. Admin migration is complete — see `SBN-Admin-Reference.md` for the full admin functional spec.
> **Last updated:** 2026-04-20

---

## MIGRATION STATUS

| Phase | What | Status |
|-------|------|--------|
| 0–7d | Admin section (all modules) | **DONE — see SBN-Admin-Reference.md** |
| 8 | Public frontend (student-facing pages) | **NEXT** |
| 9 | Courses, auth, payments, video integration | Planned |

---

## PHASE 8 — PUBLIC FRONTEND

### Scope
All student-facing views — leadsheet viewer, chord library, progression explorer, rhythm patterns. No editing, no admin UI.

### Seed file
`resources/views/admin/leadsheets/edit.blade.php` Step 10a created `leadsheet-viewer.blade.php` (deferred, still TODO). This is the starting point: read-only chord grid + sidebar from `$leadsheet->json_data`, no Vue, no editing. When Phase 8 starts, it gets a play button (audio), sidebar edu panel, and public card system.

### Public chord grid approach
Read-only Alpine `x-for` loop over `$leadsheet->json_data.sections`. Same `sbn-design-system.css` and `chord-symbols.css` as admin — no new CSS framework needed. No Vue, no editing composables.

**Alpine chord grid source (git recovery):** The original fully-featured Alpine chord grid (with drag-to-reorder, context menu, voicing picker, selection) exists intact in commit `dd1c739` (the initial commit, 3,999 lines). Extract with:
```bash
git show dd1c739:resources/views/admin/leadsheets/edit.blade.php > edit-original.blade.php
```
**Data shape caveat:** The old Alpine grid used `chord.name` / `chord.beats` objects in a `chords[]` array per measure. The current `json_data` uses parallel `chordNames[]` / `chordOffsets[]` / `chordBeats[]` arrays. The read-only viewer should be built against the current shape — don't port the old shape wholesale.

### Public tab viewer
`TabViewer.vue` reusing `TabMeasure.vue` stripped of editing composables (`useCursor`, `useNoteInput`, `useReflow`, `useSelection`, `useUndo`). Keeps `useAudioEngine` for playback.

### CSS extraction needed before Phase 8
Tab SVG classes (`.sbn-tab-note-text`, `.sbn-tab-metronome-col`, `.sbn-beat-active`, etc.) currently live in `leadsheets.css` (admin-only). These must move to `sbn-design-system.css` before Phase 8 so the public viewer can use them without importing admin CSS.

### Voicing picker (public)
The progression builder picker is the clean prototype — consider extracting to `Alpine.store('voicingPicker')` for use in the public leadsheet viewer.

---

## LEADSHEET JSON DATA STRUCTURE

This is the shape of `Leadsheet.json_data` — the contract between admin editor and public frontend.

```json
{
  "title": "string",
  "composer": "string",
  "key": "C",
  "tempo": 120,
  "timeSignature": "4/4",
  "melody": "...MusicXML string...",
  "sections": [
    {
      "id": "section-uuid",
      "name": "A",
      "lineBreaks": [4, 8],
      "measures": [
        {
          "index": 0,
          "chordNames": ["Cmaj7", "Am7"],
          "chordOffsets": [0, 2],
          "chordBeats": [2, 2],
          "repeatStart": false,
          "repeatEnd": false,
          "volta": null
        }
      ]
    }
  ],
  "chordVoicings": {
    "Cmaj7@0.0": { "frets": "x32000", "fingers": "...", "position": 0 },
    "Am7@0.1": { "frets": "x02010", "fingers": "...", "position": 0 }
  },
  "repeatMarkers": [],
  "voltaEndings": [],
  "videoSync": {
    "videoId": "dQw4w9WgXcQ",
    "videoType": "youtube",
    "audioSource": "synth",
    "mappings": [
      { "measureIndex": 0, "videoTime": 4.5 }
    ]
  }
}
```

### Key field notes
- `chordOffsets[i]` — beat offset of chord i from measure start (quarter beats, 0-based)
- `chordBeats[i]` — duration of chord i in quarter beats; always in sync with `chordNames[]`
- `chordVoicings` keys: `"chordName@globalMeasureIndex.chordIndex"` — e.g. `"Cmaj7@0.0"`
- `videoSync.audioSource` — `'synth'` or `'video'`; determines which clock drives playback
- `melody` — full MusicXML string; null if no tab data entered

---

## LEGACY WORDPRESS PLUGIN

### Source location
`sbn-course-player(legacywp)/` — legacy WP plugin files, kept for reference only.

### What was migrated
All functionality from the WP plugin has been reimplemented in Laravel:
- Chord diagram library → `admin/chords/` + `ChordDiagram` model
- Rhythm patterns → `admin/rhythms/` + `RhythmPattern` model
- Chord progressions → `admin/progressions/` + `ChordProgression` model
- Leadsheet viewer/editor → `admin/leadsheets/` + `Leadsheet` model + `TabEditor.vue`
- Audio playback → `resources/js/audio/` (Tone.js, replaces WP audio engine)
- Chord name rendering → `public/js/sbn-chord-name.js` (replaces WP shortcode rendering)

### WP shortcode output format
The legacy WP plugin used shortcodes like `[sbn_leadsheet id="123"]`. The Laravel equivalent is server-side Blade rendering via `$leadsheet->json_data`. The shortcode is preserved as a field on the `Leadsheet` model for now but is not used by the new frontend.

### AlphaTab
`alphaTab.js` was part of the legacy WP player. It has been removed — the new tab editor uses a custom SVG renderer (`TabMeasure.vue`) built from scratch.

---

## PHASE 9 — COURSES, AUTH, PAYMENTS

Planned scope (not started):
- Student auth (Laravel Breeze or Jetstream)
- Course model — groups of leadsheets with ordering + progress tracking
- Payment integration (Stripe or similar)
- Video integration for courses (YouTube embeds — foundation already in `useVideoSync.js`)

---

## SESSION PROTOCOL (frontend sessions)

1. **Upload:** `SBN-Admin-Reference.md` + `SBN-Design-Reference.md` + this file.
2. **For leadsheet viewer work:** also upload `leadsheet-viewer.blade.php` (once created) + `leadsheets.css`.
3. **For tab viewer work:** also upload `TabMeasure.vue` + `useAudioEngine.js` + relevant composables.
4. **For CSS work:** upload `leadsheets.css` + `sbn-design-system.css`.
5. **Claude must read files before modifying them.**
6. **Before writing any CSS:** check `SBN-Design-Reference.md`.
7. **End of session:** update status table + any structural discoveries about the frontend.
