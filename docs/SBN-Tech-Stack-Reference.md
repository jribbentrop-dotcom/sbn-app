# SBN — Jazz AI Tech Stack Reference

*The concrete stack: training, inference, data, app integration, evaluation. Version details verified against current (mid-2026) sources where they drift; "verify at build time" flagged where they will keep moving.*

> **Companion (the *what/why*):** [`SBN-Jazz-AI-Master-Plan.md`](SBN-Jazz-AI-Master-Plan.md)
> is the project spec this doc serves — the two projects, labels, evals, data
> gate, and sequencing. **This doc is the *how* (plumbing); the Master Plan is
> the authority on decisions.** Where the two disagree, the Master Plan wins.
> **Base model:** decided in Master Plan §2 — currently **Qwen3-8B** (this doc's
> older `Qwen2.5-7B` references predate that decision; the Unsloth/GGUF/Ollama
> mechanics below are unchanged by the swap — same family).

---

## 1. The five layers

```
TRAINING (cloud, Python)          →  produces a LoRA adapter
   ↓ convert + merge → GGUF
INFERENCE (local, Ollama on 1070) →  serves the model
   ↑ HTTP
APP INTEGRATION (Laravel/PHP)     →  the three tools call it
DATA (JSONL, versioned)           →  feeds training, gates on `verified`
EVALUATION (held-out benchmark)   →  scores each model version
```

---

## 2. Training stack (cloud, Python)

**Confirmed current (mid-2026):**
- **Framework:** Unsloth (~2× faster, ~70% less VRAM than standard LoRA). Actively maintained, Qwen2.5 supported.
- **Base model:** **see Master Plan §2 — currently `unsloth/Qwen3-8B`** (4-bit). Supersedes the original `Qwen2.5-7B-Instruct`; same family, so the conversion/serving steps below are unchanged. Permissively licensed, strong structured-output performance. Mistral 7B / Llama 3.1 8B remain fallbacks.
- **Environment:** Python 3.11+, PyTorch 2.5+, CUDA 12.x, HF stack (transformers, datasets, peft, trl).
- **Method:** QLoRA (4-bit base, adapters in higher precision).

**Confirmed LoRA config (typical, adjust empirically):**
```python
lora_r = 16
lora_alpha = 32          # some refs use 16; 32 is a fine start
lora_dropout = 0
load_in_4bit = True
num_epochs = 3
learning_rate = 2e-4
micro_batch_size = 8     # drop if VRAM-limited on the cloud card
gradient_accumulation_steps = 4
max_seq_length = 4096    # raise if your condensed sheets are long
optim = "adamw_8bit"
lr_scheduler_type = "linear"
seed = 3407
```
A 7B QLoRA run is reported **under ~$5** and **~1 hr** on a rented A100/4090 — per-minute billing means you pay only for training time. *(Verify current pricing at build time — it moves.)*

**Cloud GPU:** Runpod / Lambda / Vast.ai (A100 or even RTX 4090, 24GB, handles 7B LoRA). Pick one for the cumulative-retrain loop; all bill per-minute.

> Watch loss: refs note loss can **climb late in training** — keep checkpoints and export the **lowest-loss** one, not necessarily the final.

---

## 3. Inference stack (local, the 1070)

**The adapter→Ollama path (this is the part people get wrong — specced from current Ollama docs):**

A raw LoRA adapter is **not portable** — you can't drop safetensors into Ollama. Two valid routes:

**Route A — merge into base, single GGUF (simpler to serve):**
1. Train → adapter (safetensors) via Unsloth.
2. Merge adapter into base, convert to GGUF (`convert_hf_to_gguf.py` from llama.cpp), quantize (Q4_K_M is the usual sweet spot for 8GB).
3. Modelfile:
   ```
   FROM ./sbn-jazz-merged-q4.gguf
   SYSTEM "You are a jazz harmony analyst."
   TEMPLATE """..."""   # ChatML for Qwen, see §3.1
   PARAMETER stop "<|im_end|>"
   ```
4. `ollama create sbn-jazz -f Modelfile`

**Route B — keep adapter separate (`ADAPTER` instruction):**
1. Convert base → GGUF and adapter → GGUF (`convert_lora_to_gguf.py`).
2. Modelfile uses both:
   ```
   FROM ./qwen2.5-7b-q4.gguf
   ADAPTER ./sbn-jazz-lora.gguf
   SYSTEM "You are a jazz harmony analyst."
   ```
3. `ollama create sbn-jazz -f Modelfile`

**Recommendation:** Route A (merged) for simplicity and predictable VRAM on the 1070. Route B is lighter to iterate if you swap adapters often.

### 3.1 Qwen prompt template (ChatML)
```
TEMPLATE """{{ if .System }}<|im_start|>system
{{ .System }}<|im_end|>{{ end }}<|im_start|>user
{{ .Prompt }}<|im_end|>
<|im_start|>assistant"""
PARAMETER stop "<|im_start|>"
PARAMETER stop "<|im_end|>"
```

### 3.2 VRAM on the 1070 (8GB)
- 7B at Q4 ≈ 4–5GB → fits, with headroom for context.
- Keep `num_ctx` modest; your condensed streams should fit. Watch it if a whole dense sheet (Georgia) is long.

*(Ollama Modelfile syntax + convert scripts: verify against docs.ollama.com/import at build time — these evolve.)*

---

## 4. App integration (Laravel / PHP)

**Dev:** Laravel Herd on Windows, Ollama running locally, app → `http://localhost:11434`.

```php
$res = Http::timeout(60)->post('http://localhost:11434/api/generate', [
    'model'  => 'sbn-jazz:latest',
    'prompt' => $formattedPrompt,   // your condensed stream
    'format' => 'json',             // force JSON output
    'stream' => false,
]);
$analysis = json_decode($res->json()['response'], true);
```

- **JSON mode** (`'format' => 'json'`) makes the identifier/builder return parseable structure — pair with a schema-instructing system prompt.
- **Parallel Viterbi:** call both, compare, log disagreements (weak-spot mining). Keep Viterbi for parsing/normalisation regardless.
- **Fallback:** if Ollama is down/timeout, fall back to Viterbi so the tools never hard-fail.

---

## 5. Data stack

- **Format:** JSONL, one verified sheet per line (Export spec).
- **Versioning:** `identifier_v1.jsonl`, `_v2`… Keep **cumulative** sets (always retrain on everything) + a frozen **held-out** set never trained on.
- **Storage:** in-repo for small sets is fine early; move to object storage (or Git LFS) once sets are large. Tag each set with `notation_version` so re-normalizations are traceable.
- **The Python↔PHP boundary:** PHP (Laravel export command) **writes** JSONL → Python (Unsloth) **reads** it. JSONL is the contract. Nothing else crosses. The trained GGUF crosses back (cloud → your 1070) as a file.

---

## 6. Evaluation harness

A small but real piece of tooling — don't skip it:
1. Hold out ~50 **verified** sheets, never train on them.
2. For each: feed the stream to the model, get chords, compare to ground-truth labels.
3. Score: exact-chord accuracy, root-only accuracy, and "function correct ignoring extension" (graded credit — naming a `D7` where truth is `D7(b9)` is closer than naming `Gm`).
4. Run after **every** retrain; track the number per model version to catch regressions / catastrophic forgetting.
5. The **Match-Check Engine** (your deterministic checker) is the natural scorer here — reuse it to grade model output against truth.

---

## 7. The full loop, end to end

```
MusicXML upload → DB (existing import)
   → VERIFY VIEW (+ Match-Check Engine) → `verified`
   → EXPORT (artisan) → identifier_vN.jsonl   [PHP writes]
   → cloud: Unsloth QLoRA on Qwen2.5-7B       [Python reads]
   → merge + convert → GGUF (Route A)
   → Modelfile → `ollama create sbn-jazz`     [on the 1070]
   → Laravel tools call localhost:11434
   → EVAL HARNESS scores vs held-out
   → curate more → repeat (cumulative)
```

---

## 8. What to verify at build time (will drift)

These are the moving parts — check current docs rather than trusting any snapshot:
- **Cloud GPU pricing** (Runpod/Lambda/Vast) — changes constantly.
- **Ollama Modelfile syntax + import/convert scripts** — docs.ollama.com/import.
- **Unsloth API surface** (`FastLanguageModel.from_pretrained` args, model slugs like `unsloth/Qwen2.5-7B-Instruct`) — unsloth.ai/docs.
- **llama.cpp convert script names** (`convert_hf_to_gguf.py`, `convert_lora_to_gguf.py`) — occasionally renamed.
- **Whether to jump base model** — Qwen2.5-7B is solid now; a newer small model may be better by the time you train. Re-check the current strong 7B-class instruct model before committing.

---

## 9. Honest notes

- **The 1070 is the long-term constraint, not training.** Training is cheap and cloud-based; your daily inference lives on 8GB. If the model ever needs to grow past a comfortable Q4 7B, that's a hardware conversation, not a software one.
- **Route A vs B** is a real fork — merged is simpler, separate-adapter is lighter to iterate. Decide based on how often you'll swap adapters.
- **None of this matters without curation.** The stack is the easy part; verified data is the hard part. This doc is the plumbing — the Master Plan's curation gate is the project.
