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
