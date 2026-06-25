# SBN Skill Tree — Design Brief (for Cowork / design brainstorm)

> **Purpose of this doc:** hand a designer/brainstorm tool the *real constraints* of the SBN skill
> tree so mockups start grounded, not blank-page. The **data is already built** (graph + grades +
> style weights + completion all exist in the DB as of 2026-06-25) — this is now purely a *presentation*
> problem. Bring a chosen visual direction back to the engineer; do NOT decide SVG/canvas/schema here.
>
> Context: this tree is the **visual core and main student motivation** of a gamified guitar-education
> app (RPG/FIFA analogy — see `SBN-Skill-System-Plan.md` "Vision → Reality Reconciliation"). It must
> feel **modern, attractive, rewarding** — the thing that makes practice feel like leveling up a
> character. Brand is bossa-nova/jazz guitar: warm, sophisticated, not childish gamification.

---

## 1. What the tree visualizes (the real data)

A **skill graph**: atomic skills ("nodes") connected by prerequisite edges. Verified live numbers:

| Fact | Value | Design implication |
|---|---|---|
| Total nodes | **57** (will grow toward ~80) | Fits one screen with care; not a thousand-node MMO tree |
| Prerequisite edges | **60** | **Sparse** — clean layout is achievable, NOT a hairball |
| Max prereqs on a node (in-degree) | **3** | No node is a chaotic merge point |
| Max things a node unlocks (out-degree) | **7** | A few "hub" nodes fan out — they're the visual anchors |
| It's a **graph, not a tree** | cross-branch edges exist | e.g. `arpeggio-shapes` (Melody) requires `triads` (Harmony). Edges cross categories — the layout can't be 6 clean separate columns. |

---

## 2. The five dimensions that must read AT ONCE

This is the core challenge. Every node simultaneously carries **five** attributes, and the design has
to make them legible together without looking like a circuit diagram:

1. **Branch** (category) — one of 6: Harmony (19), Rhythm (10), Technique (10), Melody (8),
   Reading & Theory (6), Ear Training (4). *Each has a Heroicon already.*
2. **Grade** (difficulty 1–5) — Basic → Advanced. Spread: g1=6, g2=19, g3=17, g4=8, plus 7 ungraded.
   This is the **progression axis** — students climb it.
3. **Style weight** — how characteristic the node is of each of 4 styles (Bossa-Nova, Jazz, Classical,
   Pop), weight 1–3. Coverage: jazz 23, bossa 19, classical 13, pop 7 nodes. *Many nodes carry 0–2
   styles; foundational nodes carry none (neutral).* This is the **identity axis** — it's how a student
   becomes "a Jazz player" vs "a Bossa player."
4. **Prerequisite edges** — directed lines (this-before-that).
5. **Completion state** — per student: done / available (prereqs met) / locked (prereqs unmet).
   *Soft lock — locked nodes are still clickable; the lock motivates, never blocks.*

**The hard question for the brainstorm:** which dimensions become *position*, which become *color*,
which become *shape/icon*, which become *overlay/glow*? You can't make all five "position." A starting
hypothesis (not a mandate): grade → vertical/horizontal progression axis, branch → icon, style →
color, completion → glow/lock overlay, edges → connector lines. Mock alternatives.

---

## 3. Layout metaphors to mock (pick one, or hybridize)

Mock at least two of these for **desktop**, side by side, so the emotional read is comparable:

- **A. FC26 / skill-tree tiles** *(the original reference, 2026-06-24)* — hexagonal or diamond tiles,
  connector lines for edges, lock overlay on unmet prereqs, glow on completed. Hand-laid positions.
  Feels like a video-game ability tree. **This is the leading candidate** — mock it first.
- **B. Grade lanes** — 5 vertical columns or horizontal bands = the 5 grades; nodes flow left→right
  (or bottom→top) as difficulty rises; edges mostly go forward. Makes "progression" unmissable.
  Risk: cross-branch edges and same-grade clusters get busy.
- **C. Constellation / star map** — style clusters as "regions," nodes as stars, edges as lines.
  Romantic, fits a sophisticated music brand. Risk: grade legibility suffers.
- **D. Metro/transit map** — branches as colored "lines," nodes as stations, interchanges = cross-
  branch prereqs. Clean, iconic. Risk: the grade axis has to be faked.

For **each**, show: a completed node, an available node, a locked node, and at least one cross-branch
edge, so we see how the states and the "graph not tree" reality actually look.

---

## 4. Hard constraints (non-negotiable)

- **Mobile must work.** A dense desktop graph is unusable on a phone. Propose the mobile story
  explicitly — pan/zoom? collapse to one branch at a time? a simplified vertical list per branch with
  a "see full map" on desktop only? This is a design decision, flag your recommendation.
- **Soft-gating, not hard-locking.** Locked nodes are dimmed/overlaid but still tappable. Never a
  "you can't go here" wall — the vibe is "here's what's ahead," not "denied."
- **The student's grade level** (computed: 0–5, e.g. "Level 2 — Early Intermediate") should be
  glanceable from the tree, ideally as a "you are here" line/region, not just a separate number.
- **Brand:** warm, modern, sophisticated (bossa/jazz guitar). Gold/amber accent already in use
  (`--sbn-accent` ≈ `#b8860b`). Avoid neon/arcade-y gamification clichés; think "elegant progression,"
  not "candy crush."
- **Completed should feel rewarding** — the glow/celebration on completion is a primary motivation
  lever. Worth designing the "just completed a node" micro-moment, not only the static state.

---

## 5. What NOT to decide in the brainstorm (leave for engineering)

These come *after* a visual direction is locked — don't burn brainstorm time on them:

- SVG vs Canvas vs CSS-grid rendering (engineer will choose from node count + chosen visual).
- Exact `pos_x` / `pos_y` coordinate scheme + the admin layout-editor to set them.
- Data plumbing — already done (graph, grades, style weights, completion are all queryable).
- Auto-layout algorithms — the plan already leans **hand-laid** positions, not force-directed.

---

## 6. Deliverable to bring back

1. **One chosen desktop layout** (static mockup, with the 4 node states + a cross-branch edge visible).
2. **The mobile approach** (recommendation + rough mock).
3. **The dimension→encoding mapping** you settled on (which of the 5 attributes is position / color /
   icon / overlay).

With those three, the engineer can decide rendering tech, design the position schema + admin editor,
and build the Vue component — all the data it needs already exists.

---

*Created 2026-06-25. Companion to `SBN-Skill-System-Plan.md` (Post-v1 Roadmap #6 + Icon System).*
*Live data snapshot in §1 verified against `database/sbn.db` on 2026-06-25.*

---

## 7. Decision — locked direction (2026-06-25, Cowork design session)

Three layouts from §3 were mocked side-by-side using the same 9-node sample subgraph (including
the `triads` → `arpeggio-shapes` cross-branch edge): **A (FC26 tile tree)**, **B (grade lanes)**,
**C (constellation)**. Findings:

- **B** confirmed its own predicted risk — the cross-branch edge visibly cut through an unrelated
  grade band. Demoted to a *mobile fallback pattern* only (see below), not a desktop contender.
- **C** read as the most on-brand (sophisticated, not arcade-y) but cost the grade dimension —
  grade was reduced to star-size, the weakest of the three encodings tested. Sparse style clusters
  (Classical, Pop) also looked visually empty next to Jazz's 23 nodes.
- **A won.** It was the only layout that didn't sacrifice a dimension to make another legible.

### Chosen layout: A — FC26-style tile tree

Hand-laid diamond/hex tiles (not auto-layout, not force-directed — confirmed per §5, this stays a
hand-laid `pos_x`/`pos_y` scheme for the engineer to design).

**Reference mockup:** `docs/SBN-Skill-Tree-Mockup-A-Reference.html` (open directly in a browser).
Only layout A is saved as a reference file — B and C were comparison sketches only, shown live
during the design session and not persisted, since they were demoted (see findings above). The
saved file carries the same caveat inline: it's a comparative sketch, not a visual spec — see
"Explicitly NOT specified" below for what's still open.

### Dimension → encoding mapping (locked)

| Dimension | Encoding |
|---|---|
| Grade (1–5) | Vertical position — tiles climb upward by tier, "you are here" as a horizontal line/region |
| Branch (6 categories) | Icon (existing Heroicon system per `SBN-Skill-System-Plan.md`) |
| Style (bossa/jazz/classical/pop) | Tile color |
| Completion (done / available / locked) | Border + check badge / plain / dimmed + lock badge — soft lock, never hard-blocked |
| Prerequisite edges | Connector lines; cross-branch edges get a visually distinct treatment (color/weight) so the "graph not tree" reality stays legible, not hidden |

### Mobile — TBD, not locked

No mobile mock was built. Placeholder direction only: collapse to **one branch at a time** (a
vertical chip-list per branch, structurally similar to layout B's chip styling, which held up fine
as a single column even though it failed as the desktop hero), with a branch switcher, gating the
full tile-tree map to desktop/tablet. **This needs its own design pass before mobile is built** —
flagging honestly rather than pretending B's chip styling alone closes it out.

### Explicitly NOT specified — open for implementation

The mockups were comparative sketches, not a visual spec. Still open:

- **Color values.** Mockup colors (purple/coral/teal/etc.) were placeholders. The real style tokens
  (defined in `public/css/sbn-design-system.css`) are: `--clr-style-bossa` **orange** `#f39c12`,
  `--clr-style-jazz` **blue** `#3b82f6`, `--clr-style-classical` **green** `#10b981` (NOT slate — an
  earlier note in this doc was wrong), `--clr-style-pop` **pink** `#ec4899`. Reuse these; don't invent
  a parallel palette.
- **Spacing, tile size/shape, icon set, font sizes** — mockup used Tabler icons as a stand-in for
  the real Heroicon system and arbitrary diamond proportions. Not a constraint.
- **Animations and the completion "glow"/celebration micro-moment** — explicitly called out in §4
  as worth designing properly (not just `box-shadow: glow`), and not attempted in the mockups.
  Code can and should handle this.

### One architectural constraint for whoever builds it

Per `SBN-Design-Reference.md`: **card frame styling (borders, shadows, hover transitions, any
completion glow) must live as global classes in `sbn-design-system.css`, not in the Vue component's
scoped `<style>` block** — scoped styles get a `[data-v-hash]` attribute that `[data-theme]`
selectors can't reach, breaking theme-switching. Put the tile/glow styling in the design system from
the start so it isn't redone later.

### Still genuinely deferred to engineering (per §5, unchanged)

SVG vs. Canvas vs. CSS-grid rendering for the *student* tree, and the mobile design pass flagged above.
(The `pos_x`/`pos_y` schema + admin layout editor are now BUILT — see §8.)

---

## 8. As-built status (2026-06-25) + open questions

### Built
- **Positions schema** — `pos_x`/`pos_y` on `sbn_skill_nodes` (0..1000 design units), auto-seeded by
  `SkillNodePositionSeeder` (grade-tier × branch-spread), commit `4b8ad56`.
- **Admin drag-to-position editor** — `/admin/skill-nodes/layout`, vanilla-JS draggable tiles + live
  SVG edge redraw + bulk save, commit `441cc02`. Verified rendering; drag/save confirmed working by
  Lucas in-browser 2026-06-25.
- **Grade scale completed** — all five tiers now populated (G1=13, G2=19, G3=17, G4=3, G5=5; zero
  ungraded), commit `73fcdad`. The tree is structurally complete top-to-bottom.

### Encoding as-built — note the editor vs student divergence
- **Admin editor tile color = BRANCH** (deliberate — for *positioning*, "which branch" is the more
  useful key, and a node can carry 0–4 styles so style-color would be ambiguous there).
- **Student tree tile color = STYLE** (the §7 locked mapping). These diverge ON PURPOSE.

### Icons — two layers, current state
- **`icon_key`** (Heroicon name, per-node): **50 of 57 set** in the seeder (e.g. `chord-melody`→
  `queue-list`). The 7 without fall back to their branch icon. So per-node icons ARE specced — as
  Heroicon placeholders, not bespoke art.
- **`icon_path`** (custom purpose-drawn SVG, per-node): **0 of 57 set.** This is the bespoke-art tier
  (Phase B in the plan's Icon System). `SkillIcon.vue` already prefers icon_path → icon_key → branch
  fallback, so dropping custom SVGs in later is a pure design/content task, no code change.

### OPEN QUESTION (blocks the student tree) — multi-style tiles
The §7 mapping locked "tile color = style," but **~half the styled nodes carry 2+ styles** (e.g.
`shell-voicings` = jazz 3 + bossa 3; `secondary-dominants` = jazz 2 + classical 2 + bossa 1). A single
fill can't show two/three colors. **Unresolved — needs a design decision before the student tree is
built.** Candidate approaches:
- **Dominant-weight wins** — one color (highest weight); ties broken by a fixed style priority. Simplest.
- **Split/gradient tile** — 2-way split or conic gradient across the node's styles. Prettier, busier.
- **One base color + small style "pips"** — dominant style fills the tile, tiny dots mark the others.
- Foundational (0-style) nodes need a neutral default regardless (grey? branch color?).

### OPEN — mobile
Still TBD (see §7 "Mobile"). No mock built; placeholder direction is one-branch-at-a-time collapse.

### Deferred (lower priority — alpha)
- **Student-facing SVG tree render** — the read-only view students see. Mockup (`...-Mockup-A-Reference.html`)
  is a near-literal spec for it, but it's gated on the multi-style-tile decision above. Deprioritised
  while in alpha per Lucas 2026-06-25.
- **Custom per-node SVG art** (`icon_path`) — design task, no code.
- **Completion glow / "just leveled up" micro-moment** — §4; build with the student tree.
