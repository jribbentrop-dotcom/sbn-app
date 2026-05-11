# Fretboard & Chord Progression Redesign Handoff

**Purpose:** Comprehensive guide for Claude Design to work on fretboard and chord progression component redesign.

**Date:** May 11, 2026  
**Prepared for:** Claude Design  
**Scope:** Redesign of chord diagram visualization (fretboard) and chord progression builder UI

---

## PART 1: EXECUTIVE SUMMARY

### What We're Redesigning

Two interconnected UI components in the SBN Teaching Hub:

1. **Chord Diagram (Fretboard)** — Visualization of guitar chord voicings
   - Current tech: SVG-based, rendered in `NeonChordDiagram.vue`
   - Current implementation: neon glow effect, numeric fret positions
   - Used in: chord progression builder, chord library, leadsheet viewer

2. **Chord Progression Component** — Builder/editor for chord sequences
   - Current tech: Vue 3 components with drag-and-drop
   - Current structure: hierarchical (sections → measures → chords)
   - Features: inline chord editing, voicing picker, rhythm timing, drag-to-resize
   - Used in: admin progression builder, leadsheet creation

### Constraints

- **Platform:** Laravel + Vue 3 frontend
- **Design System:** Existing CSS component library (see SBN-Design-Reference.md)
- **Brand colors:** Orange accent (`#f39c12`), blue/purple/green for music styles
- **Fonts:** DM Sans (body), JetBrains Mono (mono), Crimson Text (chord names)
- **Browser targets:** Modern desktop browsers (Chrome, Safari, Firefox)

---

## PART 2: CURRENT ARCHITECTURE

### 2.1 Chord Diagram Component

**File:** `resources/js/Components/ChordDiagram/NeonChordDiagram.vue`

**Current Implementation:**
- SVG canvas (configurable width/height)
- Renders 6-string guitar fretboard
- Input format: chord string like `"x3545x"` (x=muted, 0-9=fret numbers)
- Features:
  - Neon glow filter effect
  - Fret position indicator (top-left)
  - Muted string markers (×)
  - String/fret grid
  - Finger dots (colored red: `#e74c3c`)

**Props:**
```javascript
{
  voicing: String,           // e.g. "x3545x" or "x32010"
  width: Number,             // viewport width
  pos: Number,               // fret position (1-12+)
  numFrets: Number,          // visible frets (default: 4)
  // Plus color/style props for customization
}
```

**Rendering Pipeline:**
1. Parse voicing string to array: `"x3545x"` → `['x', '3', '5', '4', '5', 'x']`
2. Calculate SVG grid geometry
3. Draw fret/string grid
4. Plot finger dots at intersection points
5. Add neon glow filter

### 2.2 Chord Progression Component Hierarchy

**Files:**
```
resources/js/tab-editor/components/
├── ChordSection.vue          # Section container (e.g., "Verse", "Chorus")
├── ChordMeasure.vue          # Individual measure (bar) within section
├── ChordCard.vue             # Individual chord voicing card
├── ChordPicker.vue           # Inline chord name editor popup
└── ChordMeasure.vue          # Measure with beat grid & chord positioning
```

**Data Structure:**
```javascript
{
  id: "verse-1",
  name: "Verse",
  measures: [
    {
      index: 0,
      chords: [
        { name: "Cmaj7", voicing: "x3545x", beats: 2 },
        { name: "Am7", voicing: "x02010", beats: 2 }
      ],
      beatsPerMeasure: 4,
      timeSignature: "4/4"
    }
  ]
}
```

### 2.3 Key Features of Current UI

#### ChordSection
- Collapsible section header
- Show/hide measures
- Add/remove measures
- Section rename
- Read-only mode (for viewers)
- Density setting (full/compact)

#### ChordMeasure
- Beat grid (quarter-note ticks)
- Display measure number (global index)
- Show TAB badge (if imported from tablature)
- Show repeat signs (𝄆 𝄇)
- Sync point badge (for video sync)
- Absolutely positioned chord cards by beat offset

#### ChordCard
- Chord name display (with superscript formatting via Crimson Text)
- Chord diagram preview (SVG thumbnail)
- Inline chord name editing
- Voicing picker modal
- Drag-to-move within measure
- Resize handles (left/right to adjust duration)
- Empty state (🎸 icon if no voicing)

#### ChordPicker
- Teleported modal (fixed positioning)
- Text input for chord symbol
- OK/Cancel buttons
- Click-outside to close

---

## PART 3: DESIGN SYSTEM & STYLING

### 3.1 CSS Architecture

**Load Order (in HTML head):**
```html
<link rel="stylesheet" href="{{ asset('css/sbn-design-system.css') }}">
<link rel="stylesheet" href="{{ asset('css/chord-symbols.css') }}">
<link rel="stylesheet" href="{{ asset('css/admin2.css') }}">
```

### 3.2 Color Tokens (from SBN-Design-Reference.md)

**Core Colors:**
- `--clr-bg`: `#f8f9fb` (page background)
- `--clr-white`: `#ffffff` (cards, inputs, diagrams)
- `--clr-surface-2`: `#f7fafc` (table headers, secondary)
- `--clr-surface-3`: `#eef1f5` (hover states)

**Accent (Brand):**
- `--clr-accent`: Orange (`#f39c12`) — active states, icons, borders
- `--clr-accent-dim`: Dimmed orange — chord names, accents
- `--clr-accent-bg`: Orange tint background
- `--clr-gradient`: Orange gradient (primary buttons)

**Text:**
- `--clr-text`: Primary text
- `--clr-text-dim`: Secondary text
- `--clr-text-muted`: Placeholders, labels

**Semantic:**
- `--clr-success`: `#10b981` (green)
- `--clr-error`: `#ef4444` (red, finger dots)
- `--clr-warning`: `#f39c12` (orange)

**Music Styles:**
- `--clr-style-bossa`: `#f39c12` (orange)
- `--clr-style-jazz`: `#3b82f6` (blue)
- `--clr-style-samba`: `#10b981` (green)
- `--clr-style-latin`: `#8b5cf6` (purple)
- `--clr-style-blues`: `#6366f1` (indigo)

### 3.3 Typography

| Variable | Font | Usage |
|---|---|---|
| `--font-body` | DM Sans | UI text, labels, buttons |
| `--font-mono` | JetBrains Mono | Fret strings, code, slugs |
| `--font-chord` | Crimson Text | **Chord names only** |

**Important:** Chord names must always render in Crimson Text with superscripted extensions. Never use plain text for chord symbols.

### 3.4 Key Component CSS Classes

All components use `sbn-ve-` prefix (= "SBN visual editor"):

```css
/* Section */
.sbn-ve-section
.sbn-ve-section-header
.sbn-ve-section-name
.sbn-ve-section-body

/* Measure */
.sbn-ve-measure
.sbn-ve-measure-num
.sbn-ve-beat-grid
.sbn-ve-beat-tick

/* Chord Card */
.sbn-ve-chord
.sbn-ve-chord-name
.sbn-ve-chord-diagram
.sbn-ve-chord-resize-handle

/* Chord Picker */
.sbn-ve-chord-picker
.sbn-ve-chord-picker-input
```

---

## PART 4: DATA MODELS & BACKEND INTEGRATION

### 4.1 ChordProgression Model

**File:** `app/Models/ChordProgression.php`

```php
ChordProgression {
  id
  name                  // e.g. "II-V-I"
  category              // jazz|blues|pop|modal|classical|latin|other
  numerals              // String: "ii-7 v-7 Imaj7"
  alt_numerals          // Array: ["ii V I"]
  description
  typical_genres
  tags                  // JSON array
  tonality              // major|minor
  match_mode
  sort_order
  featured              // boolean
}
```

**Categories & Colors:**
```php
const CATEGORIES = ['jazz', 'blues', 'pop', 'modal', 'classical', 'latin', 'other'];
const CATEGORY_COLORS = [
  'jazz' => '#8b5cf6',
  'blues' => '#3b82f6',
  'pop' => '#ec4899',
  'modal' => '#10b981',
  'classical' => '#f59e0b',
  'latin' => '#ef4444',
  'other' => '#6b7280'
];
```

### 4.2 ChordDiagram Model

**File:** `app/Models/ChordDiagram.php`

Stores individual chord voicings with associated metadata.

### 4.3 Builder Service

**File:** `app/Services/ProgressionBuilder.php`

Algorithm for generating guitar voicings from a chord progression. Consults:
- Chord category (jazz, blues, pop, etc.)
- Named resolutions (Phase E feature)
- Harmonic constraints
- Candidate voicing pool from database

**See also:** `docs/SBN-Builder-Reference.md` for deep dive on voicing algorithm.

---

## PART 5: INTERACTION PATTERNS

### 5.1 Drag-and-Drop (Chords within Measures)

**Current Implementation:**
- Chord cards are absolutely positioned by beat offset
- Pointerdown on card → capture state
- Pointermove → update ghost position
- Pointerup → commit or cancel

**Ghost states:**
- Drag ghost (semi-transparent card following cursor)
- Resize ghost (for duration adjustment)

### 5.2 Inline Editing

**Current Pattern:**
- Click chord name → text input appears
- Type new chord symbol
- Enter/blur to commit or Escape to cancel
- Validates against chord name format

### 5.3 Voicing Picker

**Current Pattern:**
- Click chord diagram → ChordPicker modal opens
- User selects new voicing (currently just text input, step 5 scope: dropdown)
- OK to apply, Cancel/Esc to close

### 5.4 Read-Only Mode

All components accept `readOnly` prop:
- Hides edit buttons, resize handles, rename inputs
- Sections stay collapsed/expanded as-is
- Diagrams display only (no interaction)
- Perfect for public leadsheet viewer

---

## PART 6: CURRENT STATE & PAIN POINTS

### 6.1 What Works Well

- Hierarchical structure (sections/measures/chords) is intuitive
- Drag-to-resize for duration is powerful
- Beat grid visualization is clear
- Inline editing reduces modal fatigue
- Read-only mode works seamlessly

### 6.2 Known Areas for Redesign

1. **Chord Diagram Aesthetics**
   - Neon glow may feel dated
   - Fret position label could be more prominent
   - Finger dots could use better contrast or animation

2. **Chord Progression UI Density**
   - Compact mode exists but may not go far enough
   - Mobile/tablet layout not optimized
   - Measure numbers take up space

3. **Voicing Picker**
   - Currently text-input only (Step 5 scope: add dropdown selector)
   - No preview of voicing before commitment
   - Could benefit from visual voicing explorer

4. **Rhythm/Timing**
   - Beat grid is good but could be more interactive
   - No visual indication of which beat is "active" during playback
   - Resize granularity could be finer

---

## PART 7: HOW TO PREPARE CODE FOR HANDOFF

### 7.1 Extract Current Component Files

**Minimal set for Claude Design:**

```
sbn-app/
├── resources/js/Components/ChordDiagram/NeonChordDiagram.vue
├── resources/js/tab-editor/components/
│   ├── ChordSection.vue
│   ├── ChordMeasure.vue
│   ├── ChordCard.vue
│   ├── ChordPicker.vue
│   └── ChordMeasure.vue
├── resources/js/tab-editor/utils/chordFormat.js
├── resources/js/tab-editor/composables/useChordGridOps.js
├── resources/js/tab-editor/composables/useChordSync.js
└── public/css/
    ├── sbn-design-system.css
    └── chord-symbols.css
```

### 7.2 Create Design Canvas Export

**Recommended approach:**

1. **Option A: Code export (best for iterative design)**
   - Export components as-is to Design Canvas
   - Preserve Vue structure, modify styling layer only
   - Use existing design system tokens
   - Keep component props/event interfaces stable

2. **Option B: Figma mockup + HTML prototype (good for major redesign)**
   - Sketch new designs in Figma
   - Export high-fidelity HTML/CSS prototype
   - Code team implements production version
   - Cleaner separation of concerns

3. **Option C: Combined approach (recommended)**
   - Use Design Canvas to iterate on current Vue components
   - Export HTML/CSS prototypes for major changes (e.g., voicing picker dropdown)
   - Clear handoff specs for production implementation

### 7.3 Prepare Data Context

**What Claude Design needs:**
1. **Sample progression data** (JSON)
   ```json
   {
     "sections": [
       {
         "name": "Verse",
         "measures": [
           { "chords": [{"name": "Cmaj7", "voicing": "x3545x", "beats": 2}] }
         ]
       }
     ]
   }
   ```

2. **Chord voicing examples** (at least 10 common voicings)
3. **Design system reference** (copy `SBN-Design-Reference.md`)
4. **Current screenshot/recording** (for baseline comparison)

### 7.4 Document Redesign Goals

**Prepare brief:**

1. **What problems are we solving?**
   - Aesthetics? Usability? Mobile support? Feature additions?

2. **Constraints?**
   - Must work with existing design system tokens?
   - Must support read-only mode?
   - Must maintain drag-to-resize interaction?

3. **Inspiration?**
   - Any reference designs to emulate?
   - Music education best practices?
   - Competitor analysis (if applicable)?

4. **Success metrics?**
   - Faster chord selection?
   - Better mobile experience?
   - Higher engagement with voicing explorer?

### 7.5 Create Handoff Bundle

**Recommended file structure for Claude Design:**

```
sbn-app-redesign-brief/
├── README.md                              # Overview & goals
├── current-components.zip                 # Current Vue components
├── design-system-tokens.json              # Color/font/spacing tokens
├── sample-progression.json                # Example data
├── voicing-samples.json                   # 20+ chord voicings with shapes
├── screenshots/
│   ├── chord-diagram-current.png
│   ├── progression-builder-current.png
│   └── mobile-view-current.png
├── design-reference/
│   ├── SBN-Design-Reference.md
│   └── SBN-Builder-Reference.md (excerpt)
└── constraints.md                         # Platform, browser, performance
```

---

## PART 8: PRODUCTION HANDOFF TO CODE

### 8.1 What Claude Design Should Export

1. **HTML/CSS Prototype**
   - Standalone `index.html` with inline CSS
   - Mockup data in `<script>` tag (no build step)
   - Playable in browser immediately
   - Clear comments indicating component boundaries

2. **Vue Component Specs** (if redesigning internals)
   - Props interface (unchanged if possible)
   - Event interface (emit contracts)
   - Styling hooks/class names
   - Composition changes (if needed)

3. **Design Specs Document**
   - Color palette changes (if any)
   - Typography changes (if any)
   - Spacing/layout rules
   - Animation/transition specs
   - Responsive breakpoints

### 8.2 Code Team Implementation Workflow

1. **Review Design Export**
   - Verify design system alignment
   - Check interaction completeness
   - Test in target browsers

2. **Implement in Vue**
   - Port HTML structure to Vue template
   - Preserve component hierarchy (if possible)
   - Integrate with existing state management
   - Maintain props/events interface

3. **Test**
   - Visual regression testing
   - Interaction testing (drag-resize, inline edit)
   - Responsive testing
   - Read-only mode verification

4. **Deploy**
   - Merge to staging
   - QA sign-off
   - Release to production

---

## PART 9: QUICK REFERENCE FILES

### 9.1 Where to Find Things

| Component | Location |
|---|---|
| Chord diagram SVG | `resources/js/Components/ChordDiagram/NeonChordDiagram.vue` |
| Progression builder | `resources/js/tab-editor/components/` |
| Chord formatting | `resources/js/tab-editor/utils/chordFormat.js` |
| Design system CSS | `public/css/sbn-design-system.css` |
| Color tokens | `public/css/sbn-design-system.css` (`:root` section) |
| Chord model | `app/Models/ChordDiagram.php` |
| Progression model | `app/Models/ChordProgression.php` |
| Builder algorithm | `app/Services/ProgressionBuilder.php` |
| Design reference | `docs/SBN-Design-Reference.md` |
| Builder reference | `docs/SBN-Builder-Reference.md` |

### 9.2 Key Vocabulary

- **Voicing** — specific guitar fingering for a chord (e.g., `"x3545x"`)
- **Numeral** — Roman numeral chord symbol (e.g., `"ii-7"`, `"V7"`)
- **Measure / Bar** — 4 beats (standard time signature)
- **Section** — group of measures (Verse, Chorus, Bridge, etc.)
- **Category** — progression style (jazz, blues, pop, modal, classical, latin)
- **Fret position** — which fret the voicing starts on (1-12+)
- **Drop2/Drop3** — voicing class determined by voice leading algorithm
- **Read-only mode** — view-only UI (no editing, no drag-resize)

---

## PART 10: NEXT STEPS

### For Immediate Setup

1. **Prepare design brief** (constraints, goals, scope)
2. **Export current components** as ZIP or GitHub link
3. **Copy `SBN-Design-Reference.md` to handoff bundle**
4. **Add sample progression JSON** and voicing examples
5. **Create short video walkthrough** (optional but helpful)

### For Claude Design Session

1. Read this document top-to-bottom
2. Open current components in Design Canvas
3. Identify target changes (fretboard aesthetics? UI density? feature additions?)
4. Iterate on designs in browser preview
5. Export high-fidelity HTML/CSS prototype
6. Document all design decisions in comments

### For Code Implementation

1. Review Claude Design exports
2. Port Vue components (or implement from scratch if major redesign)
3. Preserve props/events contracts
4. Test against existing data structure
5. Verify read-only mode works as expected
6. Deploy via standard merge workflow

---

## Questions?

When Claude Design starts, they should ask:

1. **What's the primary pain point?** (aesthetics / UX / mobile / features?)
2. **Are component boundaries flexible?** (can we restructure internals?)
3. **Must we maintain backward compatibility** with existing data?
4. **Are there animation/interaction preferences?** (smooth vs snappy? microinteractions?)
5. **Mobile/tablet support priority?** (or desktop-only for now?)

---

**Document version:** 1.0  
**Last updated:** May 11, 2026  
**Maintained by:** Code team  
**For questions:** See SBN-Design-Reference.md and SBN-Builder-Reference.md
