# Fretboard & Chord Progression Redesign — Implementation Checklist

**Purpose:** Step-by-step checklist to prepare code for Claude Design handoff.

---

## CHECKLIST: Prepare Redesign Bundle

### Phase 1: Code Export (30 min)

- [ ] Create folder: `sbn-app-redesign-handoff/`
- [ ] Copy current Vue components:
  ```bash
  mkdir sbn-app-redesign-handoff/components
  cp resources/js/Components/ChordDiagram/NeonChordDiagram.vue \
     sbn-app-redesign-handoff/components/
  cp resources/js/tab-editor/components/*.vue \
     sbn-app-redesign-handoff/components/
  cp resources/js/tab-editor/utils/chordFormat.js \
     sbn-app-redesign-handoff/components/
  cp resources/js/tab-editor/composables/*.js \
     sbn-app-redesign-handoff/components/
  ```

- [ ] Copy design system CSS:
  ```bash
  mkdir sbn-app-redesign-handoff/css
  cp public/css/sbn-design-system.css sbn-app-redesign-handoff/css/
  cp public/css/chord-symbols.css sbn-app-redesign-handoff/css/
  ```

- [ ] Copy documentation:
  ```bash
  cp docs/SBN-Design-Reference.md sbn-app-redesign-handoff/
  cp docs/SBN-Builder-Reference.md sbn-app-redesign-handoff/
  ```

### Phase 2: Data Context (20 min)

- [ ] Create `sample-progression.json` (see template below)
- [ ] Create `voicing-samples.json` (see template below)
- [ ] Create `color-tokens.json` with all design system tokens

### Phase 3: Design Brief (30 min)

- [ ] Create `README.md` in handoff folder (see template below)
- [ ] Capture current state screenshots
  - [ ] Fretboard diagram (multiple fret positions)
  - [ ] Progression builder (full view)
  - [ ] Progression builder (compact view)
  - [ ] Mobile/tablet preview (if applicable)

- [ ] Define redesign goals and constraints (see BRIEF_TEMPLATE.md below)

### Phase 4: Generate Handoff Bundle (10 min)

- [ ] Zip everything: `sbn-app-redesign-handoff.zip`
- [ ] Share with Claude Design or upload to Design Canvas

---

## TEMPLATE: sample-progression.json

This represents a real progression Claude Design should design for:

```json
{
  "sections": [
    {
      "id": "verse-1",
      "name": "Verse",
      "measures": [
        {
          "index": 0,
          "globalIndex": 0,
          "chords": [
            {
              "name": "Cmaj7",
              "voicing": "x3545x",
              "fretPosition": 3,
              "beats": 2
            },
            {
              "name": "Am7",
              "voicing": "x02010",
              "fretPosition": 0,
              "beats": 2
            }
          ],
          "beatsPerMeasure": 4,
          "timeSignature": "4/4"
        },
        {
          "index": 1,
          "globalIndex": 1,
          "chords": [
            {
              "name": "Dm7",
              "voicing": "xx0211",
              "fretPosition": 0,
              "beats": 2
            },
            {
              "name": "G7",
              "voicing": "3x343x",
              "fretPosition": 3,
              "beats": 2
            }
          ],
          "beatsPerMeasure": 4,
          "timeSignature": "4/4"
        }
      ]
    },
    {
      "id": "chorus-1",
      "name": "Chorus",
      "measures": [
        {
          "index": 0,
          "globalIndex": 2,
          "chords": [
            {
              "name": "Fmaj7",
              "voicing": "1x321x",
              "fretPosition": 1,
              "beats": 4
            }
          ],
          "beatsPerMeasure": 4,
          "timeSignature": "4/4"
        }
      ]
    }
  ],
  "metadata": {
    "title": "Standard Jazz II-V-I",
    "category": "jazz",
    "tonality": "major",
    "tempo": 120,
    "timeSignature": "4/4"
  }
}
```

---

## TEMPLATE: voicing-samples.json

**10+ common voicings for reference:**

```json
{
  "voicings": [
    {
      "name": "Cmaj7",
      "voicing": "x3545x",
      "fretPosition": 3,
      "voicingClass": "drop2",
      "category": "jazz",
      "difficulty": "intermediate",
      "aliases": ["C∆7", "Cma7"]
    },
    {
      "name": "Am7",
      "voicing": "x02010",
      "fretPosition": 0,
      "voicingClass": "open",
      "category": ["jazz", "blues", "pop"],
      "difficulty": "beginner",
      "aliases": ["A-7"]
    },
    {
      "name": "Dm7",
      "voicing": "xx0211",
      "fretPosition": 0,
      "voicingClass": "open",
      "category": ["jazz", "blues", "pop"],
      "difficulty": "beginner",
      "aliases": ["D-7"]
    },
    {
      "name": "G7",
      "voicing": "3x343x",
      "fretPosition": 3,
      "voicingClass": "drop2",
      "category": "jazz",
      "difficulty": "intermediate",
      "aliases": ["G-dom7"]
    },
    {
      "name": "Fmaj7",
      "voicing": "1x321x",
      "fretPosition": 1,
      "voicingClass": "closed",
      "category": "jazz",
      "difficulty": "advanced",
      "aliases": ["F∆7", "Fma7"]
    },
    {
      "name": "Bb7",
      "voicing": "6x675x",
      "fretPosition": 6,
      "voicingClass": "drop2",
      "category": ["jazz", "blues"],
      "difficulty": "intermediate",
      "aliases": ["B♭-dom7"]
    },
    {
      "name": "Ebmaj7",
      "voicing": "xx1113",
      "fretPosition": 11,
      "voicingClass": "shell",
      "category": "jazz",
      "difficulty": "advanced",
      "aliases": ["E♭∆7"]
    },
    {
      "name": "Ab7",
      "voicing": "4x345x",
      "fretPosition": 4,
      "voicingClass": "drop2",
      "category": ["jazz", "blues"],
      "difficulty": "intermediate",
      "aliases": ["A♭-dom7"]
    },
    {
      "name": "Gmaj7",
      "voicing": "3x4432",
      "fretPosition": 3,
      "voicingClass": "drop2",
      "category": "jazz",
      "difficulty": "intermediate",
      "aliases": ["G∆7"]
    },
    {
      "name": "C7",
      "voicing": "x35353",
      "fretPosition": 3,
      "voicingClass": "drop2",
      "category": ["jazz", "blues"],
      "difficulty": "intermediate",
      "aliases": ["C-dom7"]
    }
  ]
}
```

---

## TEMPLATE: color-tokens.json

**Design system colors for Claude Design:**

```json
{
  "brand": {
    "primary": "#f39c12",
    "primaryDim": "#e6871a",
    "primaryBg": "#fef3c7",
    "primaryBorder": "#fbbf24"
  },
  "surface": {
    "bg": "#f8f9fb",
    "white": "#ffffff",
    "surface2": "#f7fafc",
    "surface3": "#eef1f5"
  },
  "text": {
    "primary": "#1f2937",
    "dim": "#6b7280",
    "muted": "#9ca3af"
  },
  "semantic": {
    "success": "#10b981",
    "error": "#ef4444",
    "warning": "#f39c12"
  },
  "music": {
    "jazz": "#3b82f6",
    "blues": "#6366f1",
    "bossa": "#f39c12",
    "samba": "#10b981",
    "latin": "#8b5cf6",
    "pop": "#ec4899",
    "classical": "#f59e0b",
    "gold": "#d69e2e"
  },
  "fretboard": {
    "grid": "#d1d5db",
    "gridStrong": "#9ca3af",
    "text": "#374151",
    "fingerDot": "#e74c3c",
    "fingerDotStroke": "#991b1b"
  }
}
```

---

## TEMPLATE: README.md

**For the handoff bundle:**

```markdown
# Fretboard & Chord Progression Redesign

## Overview

This bundle contains current SBN Teaching Hub components for redesign:
1. **Chord Diagram** (fretboard visualization)
2. **Chord Progression Builder** (section/measure/chord hierarchy)

## What We Need

### Current Issues / Redesign Goals

**[INSERT YOUR SPECIFIC GOALS HERE]**

Examples:
- Fretboard is dated-looking (neon glow effect) — modernize while keeping clarity
- Progression builder is too dense on mobile — optimize for touch/tablet
- Voicing picker is text-only — add visual voicing selector
- Want to combine multiple redesigns (fretboard + progression UI)

### Constraints

- Must use existing design system tokens (colors, fonts, spacing)
- Must maintain Vue 3 component interface (props/events)
- Read-only mode must work for public viewers
- Desktop-first, mobile support desired
- No external dependencies (only Vue 3, no chart libraries)

### Must-Haves

- [ ] Responsive chord diagram
- [ ] Clear beat/timing visualization
- [ ] Accessible chord name rendering
- [ ] Drag-to-resize interaction preserved
- [ ] Inline editing preserved

### Nice-to-Haves

- [ ] Animation on state changes
- [ ] Visual voicing explorer
- [ ] Keyboard shortcuts
- [ ] Undo/redo

## Files Included

```
├── components/                    # Current Vue components
│   ├── NeonChordDiagram.vue
│   ├── ChordSection.vue
│   ├── ChordMeasure.vue
│   ├── ChordCard.vue
│   ├── ChordPicker.vue
│   └── [utility files]
├── css/                           # Design system
│   ├── sbn-design-system.css
│   └── chord-symbols.css
├── docs/                          # Reference
│   ├── SBN-Design-Reference.md
│   └── SBN-Builder-Reference.md
├── sample-progression.json        # Example data
├── voicing-samples.json           # Common chord voicings
└── color-tokens.json              # Design tokens
```

## How to Use This

1. **Read** `SBN-Design-Reference.md` and `SBN-Builder-Reference.md` (10 min)
2. **Review** sample progression and voicing data
3. **Open** `components/` and inspect Vue structure
4. **Identify** target changes:
   - Fretboard aesthetics?
   - UI density/layout?
   - Feature additions?
5. **Design** in HTML/CSS (test in browser)
6. **Export** high-fidelity prototype + design specs
7. **Hand off** to code team for Vue implementation

## Key Numbers

| Component | File | Lines | Complexity |
|---|---|---|---|
| NeonChordDiagram | .vue | ~250 | Medium (SVG rendering) |
| ChordSection | .vue | ~200 | Low (structure) |
| ChordMeasure | .vue | ~400 | High (drag/resize logic) |
| ChordCard | .vue | ~350 | High (interactions) |
| ChordPicker | .vue | ~150 | Low (text input) |

## Sample Data

Open `sample-progression.json` to see the data structure. This represents
a real user progression: 4 bars (2 chords per bar) with fret positions.

Common voicings are in `voicing-samples.json` — these are all playable,
idiomatic for jazz/blues.

## Design System

All colors, fonts, and spacing come from `color-tokens.json` and CSS files.
**Rule:** Never use raw hex values; always reference tokens.

Example:
```css
/* ❌ DON'T: */
.chord-diagram { fill: #f39c12; }

/* ✅ DO: */
.chord-diagram { fill: var(--clr-accent); }
```

## Questions Before You Start?

Ask the code team:

1. **Scope:** Redesign fretboard only? Progression UI? Both? Add features?
2. **Constraints:** Must maintain exact component interface? Any migration allowed?
3. **Mobile:** Is this for desktop only or do we need mobile/tablet layout?
4. **Animations:** What's the tone? (snappy & playful vs. smooth & business-like)
5. **Deadline:** When do you need the design export?

---

**Prepared:** May 11, 2026  
**For:** Claude Design  
**By:** Code team
```

---

## TEMPLATE: BRIEF_TEMPLATE.md

**Share this with Claude Design to clarify redesign scope:**

```markdown
# Redesign Brief — Fretboard & Chord Progression

## Problems We're Solving

**[List 2-3 main problems]**

Examples:
- Users find the fretboard diagram hard to read on mobile
- Progression builder UI is too dense for rapid chord selection
- Neon glow effect feels dated compared to modern music apps
- Voicing picker is basic (text-only) — need visual selector

## Current State

[Link to current screenshots or live demo]

## Target State

**What should this look like when done?**

[Describe changes, reference other music apps if helpful]

## Success Criteria

- [ ] Design passes accessibility review (WCAG AA)
- [ ] Works on mobile, tablet, desktop
- [ ] Chord selection is < 2 seconds (vs. current)
- [ ] 90%+ of users prefer new design (survey)
- [ ] No performance regression

## Constraints

### Must Haves
- [ ] Use existing color tokens (no new colors)
- [ ] Chord names render in Crimson Text (serif)
- [ ] Fret positions labeled clearly
- [ ] Beat grid visible for timing
- [ ] Drag-to-resize interaction intact

### Must NOT Do
- [ ] No external chart libraries
- [ ] No new fonts beyond DM Sans / JetBrains Mono / Crimson Text
- [ ] No major component restructuring (preserve props/events)
- [ ] No breaking changes to data format

### Nice to Have
- [ ] Dark mode variant
- [ ] Animation on chord change
- [ ] Keyboard shortcuts for voicing selection
- [ ] Video playback indicator overlay

## Timeline

- Design iteration: [X days]
- Code review: [Y days]
- Implementation: [Z days]

## Questions?

[Ask before you start]

---

**Due date:** [date]  
**Design canvas:** [link]  
**Questions to:** [slack channel / email]
```

---

## Quick Command to Generate Bundle

**One-liner to create and zip the handoff:**

```bash
# Create folder
mkdir -p sbn-app-redesign-handoff/components sbn-app-redesign-handoff/css

# Copy components
cp resources/js/Components/ChordDiagram/NeonChordDiagram.vue \
   sbn-app-redesign-handoff/components/
cp resources/js/tab-editor/components/*.vue sbn-app-redesign-handoff/components/
cp resources/js/tab-editor/utils/*.js sbn-app-redesign-handoff/components/
cp resources/js/tab-editor/composables/*.js sbn-app-redesign-handoff/components/

# Copy CSS & docs
cp public/css/{sbn-design-system,chord-symbols}.css sbn-app-redesign-handoff/css/
cp docs/{SBN-Design-Reference,SBN-Builder-Reference}.md sbn-app-redesign-handoff/

# Copy sample data
cp sample-progression.json voicing-samples.json color-tokens.json sbn-app-redesign-handoff/

# Zip
zip -r sbn-app-redesign-handoff.zip sbn-app-redesign-handoff/

echo "✅ Handoff bundle ready: sbn-app-redesign-handoff.zip"
```

---

## Next Step: Upload to Design Canvas

1. **Create new Design Canvas project**
2. **Upload zip as project asset**
3. **Share brief (README + BRIEF_TEMPLATE)**
4. **Add any screenshots or reference designs**
5. **Invite Claude Design to collaborate**

---

---

## PHASE 5: Standardization & Polish (May 2026)

- [x] Standardize `ChordProgressionViewer` across Chord, Song, and Progression libraries.
- [x] Integrate metadata header (Name, Category, Key, Numerals) into the component.
- [x] Implement unified resolution pipeline (`HarmonicContext` + `ProgressionBuilder`).
- [x] Update `SBN-Design-Reference.md` and `SBN-Builder-Reference.md` with new specs.
- [x] Verify visual consistency (Vintage Card + Category Coloring).
