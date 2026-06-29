# SBN Jazz AI — Master Plan

**Status:** Living document, created 2026-06-21.
**Scope:** The spec the `SBN-Tech-Stack-Reference.md` keeps deferring to. Defines
*what* we are training, *what* the labels are, *what* success means, and *where*
the data comes from — for each of the two distinct LLM projects. The tech-stack
doc is the plumbing; this is the plan.
**Companions:** `SBN-Tech-Stack-Reference.md` (training/inference/deployment
stack), `SBN-Identifier-Reference.md` (the deterministic recognizer), 
`SBN-Builder-Reference.md` (the deterministic voicing builder).

---

## 0. One-paragraph orientation

We are adding a fine-tuned local LLM to the SBN jazz tools. It runs on Ollama on
a GTX 1070 (8GB). Training is cloud QLoRA (Unsloth), cheap and disposable. There
are **two separate projects** — they are *inverse tasks* and must not be folded
into one training objective. The deterministic Viterbi services (identifier,
builder) **stay** as the correctness backbone and the always-available fallback.
The LLM augments; it never silently replaces.

This plan was written after a few-shot spike (`sbn:llm-spike`, 2026-06-21)
proved base models score ~0 on both tasks — so **fine-tuning is mandatory**, not
optional, and **the data + eval harness is the real project**, not the model.

---

## 1. The two projects (do not conflate)

| | **Project R — Recognition** | **Project V — Style Voicing** |
|---|---|---|
| Task | fret string + context → chord name | chord name + context + style → voicing choice |
| Direction | voicing in, name out (discards voicing) | name in, voicing out (chooses voicing) |
| Augments | `VoicingCrossref` identifier | `ProgressionBuilder` (archetype layer) |
| Ground truth | MusicXML `<harmony>` labels | master transcriptions (the actual fingering) |
| Eval | Match-Check Engine — chord-name accuracy | voicing-similarity — **must be built** |
| Data today | `sbn_leadsheets` known-chord rows | thin; needs curation from masters |
| Maturity | **ship first** — clear label, clear eval | **second** — needs the eval invented |
| LLM role | candidate generator / re-ranker over identifier | **re-ranker only** over builder candidates — never free generation |

**Why not one model:** the tasks are literal inverses and want opposite
invariance. Recognition must be *invariant* to voicing (`x5767x` and `x5777x`
are both DMaj7); style must be *sensitive* to exactly that difference. One QLoRA
adapter on a small corpus cannot serve both cleanly (catastrophic-forgetting
risk). **Share** the base model, stream format, and Ollama deployment; **split**
the training objective and the eval. Implementation: one base, two LoRA adapters
(Route B in the tech-stack doc swaps adapters cheaply), or one adapter with an
instruction tag — but trained and *evaluated* as two tasks.

**Spike evidence (2026-06-21, base qwen2.5:7b, Georgia, n=6):** recognition 0/6
random; generation emitted open-C-major (`x32210`) for five different chords.
The base has zero fretboard grounding. This is *why* both projects need
fine-tuning and why Project V's generation must route through the deterministic
builder, with the LLM only re-ranking.

---

## 2. Base model decision

**Base: `Qwen3-8B`** (supersedes the tech-stack doc's `Qwen2.5-7B-Instruct`).

- Strongest open instruct model in its class as of mid-2026 (leads HumanEval
  among 7/8B); a generational step over Qwen2.5.
- Same family → zero migration: same ChatML template, same Ollama/Unsloth flow
  the tech-stack doc already specs. Swap is a one-line model-slug change.
- First-class Unsloth QLoRA support (4-bit, rank-8 adapters, ~30M trainable, 2×
  speedup) — a dedicated Qwen3 fine-tune tutorial exists.
- Fits the 1070 at Q4 (~5GB of 8GB). 8B is the comfortable edge; if inference is
  tight, fall back to a 7B-class Qwen3 variant or Q4-over-Q5.

**The base is the least important variable** (the spike showed all bases start
near zero; the curated data does the work). Switching later is a $5 cloud
re-run — **we are not locked in.** Re-verify the strongest small model at each
training round (tech-stack doc §8).

---

## 3. Project R — Recognition (ship first)

### 3.1 Task definition
Input: a 6-char fret string (`x5756x`; `x`=mute, `0`=open, digits=fret, EADGBE)
plus optional neighbor-chord and key context. Output: a JSON chord label
`{root, quality, extensions[], bass, name}` matching the identifier's schema.

### 3.2 Ground truth & data source
- **Primary corpus:** `sbn_leadsheets.json_data.chordVoicings` — a ready-made
  `{name → frets}` map per song. ~35 voicings in Georgia alone. Plus
  `measures[].chords` for sequence context.
- **Verification gate:** a leadsheet is eligible only when its chords are
  human-verified (the Verify View, §6). Unverified rows do not enter the corpus.
- **Export:** artisan command → JSONL, one example per line:
  `{"messages":[{"role":"system",...},{"role":"user","<stream>"},{"role":"assistant","<json label>"}]}`.
  Cumulative versioned sets (`recognition_v1.jsonl`, …) + a frozen held-out set.

### 3.3 Eval
The **Match-Check Engine** (the existing deterministic checker) is the scorer.
Metrics: exact-chord accuracy, root-only accuracy, "function-correct ignoring
extension" (graded credit: `D7` where truth is `D7(b9)` beats `Gm`). ~50
held-out verified sheets, never trained on. Run after every retrain; track per
model version to catch regression / catastrophic forgetting.

### 3.4 Win condition
The fine-tuned model **beats the deterministic identifier on the held-out set**,
OR usefully disagrees with it (disagreement-mining surfaces identifier bugs —
the same mechanism that found the 2026-05-19 dead-resolution bugs). Until it
beats Viterbi, Viterbi ships; the LLM runs in parallel-log mode.

---

## 4. Project V — Style Voicing (second)

### 4.1 Task definition
Input: chord name + key + surrounding progression + target style (e.g. "João
Gilberto, bossa, low register"). Output: a **re-ranking** of voicing candidates
the deterministic builder already produced — NOT a free-generated fret string.
The spike proved free generation is useless (open-C-major for everything).

### 4.2 Ground truth & data source
- **Master transcriptions** → `(harmonic context → the voicing the master
  actually chose)` pairs. Sources: existing transcribed leadsheets +
  hand-curated per-artist examples (user's call: NOT the audio pipeline).
- This corpus is **thin today** and is the gating bottleneck for Project V. A
  stronger Project-R identifier helps mine it from raw transcriptions.

### 4.3 Eval (must be built — does not exist)
A **voicing-similarity scorer**: given the model's re-ranked top choice vs the
master's actual voicing, score closeness (shared pitch classes on same strings,
register proximity, inversion match, voice-leading distance to neighbors). This
is new tooling and is the hard part of Project V. Without it, Project V is not
measurable and must not ship.

### 4.4 Relationship to the builder
The builder (`ProgressionBuilder`) already produces correct, playable,
category-locked candidates via Viterbi. Project V's LLM **re-ranks** those
candidates by style; it never bypasses the builder's hard constraints
(playability, bass-motion, position). This keeps "boring-but-correct"
guaranteed and confines the LLM to the one thing it can plausibly add: stylistic
preference among already-valid options. Maps onto the §15 player-archetype
concept — the LLM becomes the "render like X" selector.

---

## 5. Sequencing

```
1. Verify View + Match-Check Engine wiring       (data gate — both projects need it)
2. Export artisan → recognition_v1.jsonl          (Project R data)
3. Held-out eval harness (Match-Check scorer)     (Project R measurable)
4. Cloud QLoRA on Qwen3-8B → GGUF → Ollama        (Project R model)
5. Parallel-log against identifier; iterate        (Project R win condition)
   --- gate: R good enough to mine voicings ---
6. Curate master-transcription voicing corpus      (Project V data)
7. Build voicing-similarity eval                    (Project V measurable)
8. Project V re-ranker over builder candidates      (Project V model)
```

**Do not skip 1–3 to get to model training.** The spike proved the base is
unusable, which means an untested fine-tune is indistinguishable from noise
until the eval exists. The eval is the project; the model is a $5 by-product.

---

## 6. The data gate (shared infrastructure)

- **Verify View:** an admin surface where a human confirms a leadsheet's chord
  labels are correct, flipping a `verified` flag. Only verified rows export.
- **Match-Check Engine:** the deterministic checker that grades model output
  against verified truth. Reused as the Project-R scorer.
- **JSONL contract:** PHP (Laravel artisan) writes; Python (Unsloth) reads.
  Nothing else crosses the boundary except the trained GGUF coming back. Tag
  each set with `notation_version` for traceability.

These three do not exist yet. They are the prerequisite for everything below.

---

## 7. Standing principles

1. **Viterbi stays.** Identifier and builder remain the correctness backbone and
   the always-on fallback (Ollama down/timeout → deterministic path). The LLM
   never hard-fails a user request.
2. **Measure before ship.** No model ships without beating (or usefully
   informing) its deterministic counterpart on a held-out set. Inherits the
   builder's "spec → audit → measure → ship."
3. **Re-rank, don't generate** (Project V). Free LLM voicing generation is
   banned — the spike proved it produces garbage. The LLM orders builder output.
4. **The data is the project.** "None of this matters without curation"
   (tech-stack doc §171). The stack is easy; verified data is the work.
5. **Base model is fungible.** Re-verify the strongest small model each round;
   switching is a cheap cloud re-run.

---

## 8. Open questions for next session

- Does the **Match-Check Engine** exist in any form, or is it also to be built?
  (Referenced by the tech-stack doc as if real; not yet located in repo.)
- Project V eval: is same-string pitch-class overlap the right similarity
  primitive, or should it weight voice-leading to neighbors more heavily?
- One-adapter-with-instruction-tag vs two-adapters (Route B): decide empirically
  once Project R's adapter exists and Project V data is curated.
