# Code & Security Audit — Soul Bossa Nova

**Date:** 2026-07-09 (updated 2026-07-09)
**Scope:** Code & architecture, security & config (Laravel 13 / PHP 8.3, Vue + Inertia)
**Method:** Static review of routes, controllers, services, middleware, models, config, and repo state. The PHPUnit suite was **not** executed (no PHP runtime in the audit sandbox); test observations come from the result cache and file inventory.

**Status legend:** ✅ Fixed · 🟡 Partial · ⬜ Open (for follow-up)

---

## Resuming locally — read this first

*(This section is written as direct instructions to whichever Claude Code session picks this up next — on the user's local Windows machine, after the `claude/security-patch-d0q1nn` branch was developed remotely on GitHub. If you're that session: follow it in order. If you're a human reading this, it doubles as a changelog of what still needs doing.)*

**Where things stand:** `claude/security-patch-d0q1nn` has several commits not yet in local `main` (check `git log origin/main..claude/security-patch-d0q1nn --oneline` for the current count — it grew across multiple remote sessions on 2026-07-13 and -14). Local `main` and the remote `origin/main` were identical when this branch was created, and nothing else has touched local `main` since, so this should be a clean merge, not a three-way one. If that assumption turns out to be wrong (local `main` has moved), stop and reconcile with the user before merging rather than forcing it.

**Note on test execution:** the 2026-07-13 pass had no `vendor/` in the remote sandbox, so nothing could actually be run there. On 2026-07-14, `composer install` was made to work remotely, which meant everything *not* touching the real `sbn.db` (all of `tests/Unit`, plus `tests/Feature/Identifier/DetectionFilterTest.php` and `AudioContextRerankTest.php`) has already been run and verified remotely — including the `LeadsheetController` → `TranscriptionAssembler` extraction below. So Step 2 below is really about the DB-touching tests now; the rest is a final confirmation, not first-time execution.

### Step 1 — merge the branch

```bash
git fetch origin
git checkout claude/security-patch-d0q1nn   # review the diff if you want: git diff main...claude/security-patch-d0q1nn
git checkout main
git merge claude/security-patch-d0q1nn      # should fast-forward or merge cleanly
```

### Step 2 — run the tests (this is the part that couldn't happen remotely — no `vendor/`, no DB access there)

Three new test files exist specifically to catch problems in tonight's changes. Run them first, individually, before the full suite — they're small and if something's wrong you want to know which change broke it:

```bash
php artisan test --filter=ChordQualityMapperTest        # pure unit, no DB — run this one first
php artisan test --filter=UserProfileFillableTest        # touches real dev DB, wrapped in a rolled-back transaction
php artisan test --filter=LeadsheetAdminRequestsTest      # same — transaction-wrapped, nothing persisted
```

`ChordQualityMapperTest` needs nothing but `vendor/`. The other two connect to the real `database/sbn.db` (same pattern as the existing `tests/Feature/InstructorGateTest.php`: `DB::beginTransaction()` in `setUp()`, `DB::rollBack()` in `tearDown()`) — they read/write through Laravel's normal Eloquent connection inside a transaction that's always rolled back, which is safe and does **not** need `scripts/db_checkout.py` (that script is for direct sqlite3/file-level access outside a Laravel transaction — not what these tests do). If any of the three fail, don't just patch the test to make it pass — read the failure, decide whether it's revealing a real bug in the change or an over-strict test, and say which before fixing anything.

Once those three are green, establish the full suite baseline (audit finding #10 — this has never been done, per the original 2026-07-09 audit):

```bash
php artisan test
```

Expect some pre-existing non-passing tests unrelated to this branch (the original audit noted ~105 non-passing entries out of 258 in the PHPUnit result cache, before any of tonight's work). The goal isn't a fully green suite — it's knowing which failures are pre-existing vs. new. If anything **new** fails outside the three files above, that's a regression from tonight's changes and needs investigating before you consider this branch done.

### Step 3 — manual smoke test (things the automated tests can't fully cover)

The `LeadsheetAdminRequestsTest` deliberately skips the *successful* backing-track upload path, because it writes real files into `public/audio/backing-tracks/` — not something to do from an automated test against the real project directory. Click through this by hand as the last check:

1. Log in as an instructor, go to any leadsheet's admin edit view, toggle `is_pro` on a **public_domain** song (should succeed) and try it on a **copyrighted** one (should now be *rejected* with a validation error — before tonight's fix it would have silently succeeded, which was the actual security-relevant bug found while doing #7).
2. Toggle a leadsheet's status between draft/publish.
3. Upload a real backing-track audio file and confirm it saves and the URL comes back correctly.
4. Visit `/account/profile` as a **brand-new** user (one with no existing `sbn_user_profiles` row) and confirm the page loads without a DB error — this is the specific regression the `#8` fix could have caused (see finding #8 below) if `user_id` hadn't been added back to `UserProfile::$fillable`.
5. Update the profile form (name/bio/public toggle) and confirm it saves.

### Step 4 — what's still open after this

Check the Summary table below for current status, but in short: **#3** (needs someone with production server access to confirm `APP_DEBUG=false` on the live `.env` — not verifiable from any sandbox), **#5** (the `LeadsheetController` split — the two safe pieces are done and verified; the real 4-way split around `createFromLookup`'s dual CRUD/transcription personality is still open, see finding #5 below), and the rest of **#7** (FormRequests for everything in the admin write surface beyond the three endpoints already done). Ask the user which of these they want next rather than assuming.

---

## Summary

| # | Finding | Area | Severity | Status |
|---|---|---|---|---|
| 1 | `api/admin/*` gated by `auth` only — missing `instructor` | Security | **High** | ✅ Fixed |
| 2 | Dead `routes/admin.php` with `auth`-only admin CRUD | Security | Medium | ✅ Fixed |
| 3 | `APP_DEBUG=true` in `.env` — confirm production is `false` | Config | Low | ⬜ Open (verify prod) |
| 4 | `.env.example` missing config keys | Config | Low | ✅ Fixed |
| 5 | God controller: `LeadsheetController` (4,728 lines / 80 methods) | Architecture | Medium | 🟡 Partial |
| 6 | Harmonic scorer duplicated across two services | Architecture | Medium | ✅ Fixed |
| 7 | Thin request validation (only 3 FormRequests) | Code quality | Medium | 🟡 Partial |
| 8 | `UserProfile` uses `$guarded = []` (open mass assignment) | Code quality | Low | ✅ Fixed |
| 9 | Tracked/stray files that should be ignored or removed | Repo hygiene | Low | ✅ Fixed |
| 10 | Test suite state unverified; leftover `console.log`/TODOs | Code quality | Low | 🟡 Partial |

**What's already solid:** `.env` and `sbn.db` are correctly untracked; the Stripe webhook verifies its signature and is idempotent; the CSRF exception is narrowly scoped to the webhook route; the beta auth gate (`redirectGuestsTo → register`) is coherent.

---

## Security

### 1. Broken access control on `api/admin/*` — **High** — ✅ Fixed 2026-07-09

> **Resolved:** `routes/web.php:274` now uses `Route::middleware(['auth', 'instructor'])`. The `instructor` alias is registered in `bootstrap/app.php:19`. All admin JSON endpoints now reject non-instructor accounts.

`routes/web.php:274` opens the entire admin JSON API with `auth` middleware only, omitting the `instructor` guard that its sibling `/admin` web group carries (`routes/web.php:122`). The controllers perform **no internal authorization** — e.g. `LeadsheetController::destroy()` deletes immediately with no role check.

**Impact:** any authenticated (free) account that knows the URLs can:

- `DELETE /api/admin/leadsheets/{id}` and `DELETE /api/admin/exercises/{id}` — destroy content
- `POST /api/admin/leadsheets/{id}/is-pro` and `.../status` — flip `is_pro`/publish state. Per project rules this drives monetization **and** can expose copyrighted songs through the pro Viewer/Cinema path.
- Invoke `redetect`, `transcribe-stem`, `youtube/search`, `reprocess-progressions` — expensive operations that incur YouTube-API and transcription cost and enable denial-of-service.

This is a classic broken-access-control gap (OWASP A01). Client-side UI hiding does not protect the endpoints.

**Fix:** add the role guard to the group:

```php
// routes/web.php:274
Route::middleware(['auth', 'instructor'])->prefix('api/admin')->name('api.admin.')->group(function () {
```

Then confirm no legitimate non-instructor caller depends on these routes (they shouldn't). Consider adding `authorize()`/policy checks in the controllers as defense in depth.

### 2. Dead `routes/admin.php` — Medium — ✅ Fixed 2026-07-09

> **Resolved:** file deleted. Confirmed it was never loaded (`bootstrap/app.php` registers only `web.php`, `console.php`, `channels.php`) and referenced nowhere, so removal has no functional impact.

`routes/admin.php` defined admin rhythm CRUD behind `auth` only (no `instructor`), but the file was **never loaded**. It was misleading and repeated the mistake in #1 if anyone wired it in.

### 3. Debug flag — Low — ⬜ Open (checked 2026-07-13, still needs live verification)

`.env` has `APP_ENV=local` and `APP_DEBUG=true`. Correct for local work, but if the production `.env` inherits `APP_DEBUG=true`, stack traces and environment details leak on errors.

`deploy/env-production.txt` (the reference template for the production `.env`) correctly declares `APP_ENV=production` / `APP_DEBUG=false`, and nothing in `deploy/` overwrites it with a different value. That's good evidence of intent, but this repo has no automated deploy step that applies the template and no SSH access to the live server, so the *actual* file on production has not been directly confirmed. Someone with server access should run `grep APP_DEBUG /path/to/.env` on the production host to close this out.

### 4. `.env.example` drift — Low — ✅ Fixed 2026-07-09

> **Resolved:** added the Reverb broadcasting block (`REVERB_*` + `VITE_REVERB_*`) and the missing LLM/API keys (`DEEPSEEK_*`, `GROQ_*`, `COHERE_API_KEY`, `YOUTUBE_API_KEY`). Stripe/`PAYMENTS_PROVIDER` were already present as intentional production-only comments; `DB_*` remain commented for the default SQLite setup.

Several keys the application reads were absent from `.env.example`, so a fresh deploy or new developer would boot with silently missing config.

---

## Code & architecture

### 5. God controller — `Admin/LeadsheetController.php` — Medium — 🟡 Partial (safe pieces done 2026-07-14)

Was 4,728 lines and 80 methods spanning CRUD, audio transcription, progression detection, YouTube search, and voicing identification — the largest maintainability liability in the codebase and the concentrator of #1's blast radius.

> **Scoped 2026-07-13, safe pieces done 2026-07-14 with real test verification.** `composer install` was run in the remote sandbox this time (it wasn't available in the 2026-07-13 pass), which unlocked actually running the unit/algorithmic test suite — not just `php -l` — so the three low-risk moves flagged on 2026-07-13 were completed and verified rather than deferred further:
>
> 1. **`youtubeSearch`** moved to a new single-action `Admin/YoutubeSearchController` (matches the existing single-action convention in `Webhooks/PaymentWebhookController`). Route unchanged (`api.admin.youtube.search`).
> 2. **`resolveNumerals`** moved to `Admin/ProgressionController` (its route was already named `progressions.resolveNumerals`, not `leadsheets.*`).
> 3. **The ~900-line self-contained MIDI/tab/beat-grid algorithm cluster** — `assembleTranscription`, `optimizeTabPositions`, `bassSnapBeatTimes`, `rebucketBeats`, `resolveDetectionParams`, `melodyPositionHints`, and 10 more private helpers — extracted into `app/Services/TranscriptionAssembler.php`. `LeadsheetController` now takes it as a 4th constructor-injected dependency and delegates through 8 call sites; the extracted class's 3 externally-called methods (`resolveDetectionParams`, `assembleTranscription`, `melodyPositionHints`) are `public`, the other 13 are `private`. Pure move, no logic changes.
>
> **Verification:** `tests/Feature/Identifier/DetectionFilterTest.php` and `AudioContextRerankTest.php` both invoke `assembleTranscription`/`rebucketBeats` via Reflection against `LeadsheetController` — updated to target `TranscriptionAssembler` instead, then actually run (all 6 cases pass), confirming the extraction is behavior-preserving, not just lint-clean. `php artisan route:list` confirms both relocated routes still resolve. Net result: **`LeadsheetController` is 3,387 lines**, down from 4,728 (~28% reduction), with zero route or behavior changes.
>
> **Still open:** the real 4-way split (Crud/Transcription/Voicing/Progression controllers) — `createFromLookup` (~380 lines) is simultaneously a CRUD-create action and a full audio-transcription pipeline, and three remaining helpers (`normalizeChordNamesInJson`, the `serializeLeadsheet`/`backfillFingersFromCrossref` pair, and the injected `LeadsheetParser`) still cross every proposed cluster boundary. That's real design work, not mechanical extraction — worth doing next now that DB-independent verification is available in this sandbox, but Feature tests that touch the real `sbn.db` still can't run here, so treat any change touching DB-backed code paths with the same caution as before.

**Fix:** split by responsibility — e.g. `LeadsheetCrudController`, `LeadsheetVoicingController` — pushing logic into services. Related oversized files worth decomposing: `ProgressionBuilder.php` (4,369), `VoicingCrossref.php` (3,335), `resources/js/tab-editor/TabEditor.vue` (3,547).

### 6. Duplicated harmonic scorer — Medium — ✅ Fixed 2026-07-13

> **Resolved, and smaller than it first looked.** Investigating the three `TODO(harmonic-scorer)` notes (`ProgressionBuilder.php:1628,1694,1729`) found: (a) no actual "scorer" — `ProgressionBuilder` has no equivalent of `ProgressionDetector::resolveFamily()`/`tokenScore()`; that part of the finding (and `docs/SBN-Builder-Reference.md`'s note on a future `HarmonicScorer`) was aspirational, not a description of present duplication; (b) `ProgressionBuilder::qualityToSuffix()`, the method whose name most directly claims to duplicate `ProgressionDetector::qualityToSuffix()`, had **zero call sites** — dead code; (c) the two `normalizeQuality*` methods, while both flagged as duplicates, actually solve different problems (Builder wants near-exact quality preserved for chord-name display; Detector deliberately collapses extensions to base harmonic function for pattern matching) and would break one caller if forced into a single shared implementation.
>
> Extracted `app/Services/Harmony/ChordQualityMapper.php` as the single source of truth for both real (non-dead) use cases, keeping them as two distinct named methods rather than one incorrectly-merged `normalize()`: `normalizeAlias()`/`toChordNameSuffix()` (the display path, ex-`ProgressionBuilder`) and `normalizeForFunction()`/`toRomanSuffix()` (the functional path, ex-`ProgressionDetector`). Both classes now take an optional `ChordQualityMapper` constructor param (defaults to `new ChordQualityMapper()`, so no existing call site — including the two places tests construct these classes directly with `new` — needed to change) and delegate to it instead of keeping private copies. The dead `ProgressionBuilder::qualityToSuffix()` was deleted outright rather than preserved as a delegate. Pure move + delegate, no behavior changes beyond removing the dead method. Added `tests/Unit/ChordQualityMapperTest.php` (no DB dependency) to lock in both paths' behavior, including a test that deliberately documents where they diverge (`maj6`: display keeps "6", functional collapses to "maj7").

`ProgressionBuilder` duplicated `qualityToSuffix` and (per the original finding's phrasing) "scorer logic" from `ProgressionDetector`, flagged by three in-code `TODO(harmonic-scorer)` notes. Divergence meant the detector and the builder could silently disagree on the same harmony.

### 7. Thin request validation — Medium — 🟡 Partially fixed 2026-07-13

> **Partial:** the three endpoints named in this finding (`updateIsPro`, `updateStatus`, `uploadBackingTrack` on `Admin/LeadsheetController`) now use dedicated FormRequests — `LeadsheetIsProRequest`, `LeadsheetStatusRequest`, `LeadsheetBackingTrackRequest` (`app/Http/Requests/Admin/`). Each `authorize()`s via `$user->isInstructor()` as defense in depth on top of the route-level `instructor` middleware. `LeadsheetIsProRequest` also closes a real gap found while fixing this: the old `updateIsPro` docblock claimed to "nudge the admin" against enabling `is_pro` on non-`public_domain` rows, but no such check existed in code — an instructor could set `is_pro=true` on a copyrighted song and expose it via the pro Viewer/Cinema path (the exact impact flagged in finding #1). The FormRequest now rejects that combination with a validation error.
>
> The rest of the ~80-method admin write surface (leadsheet CRUD, exercises, chords, progressions, rhythm patterns, etc.) still takes raw `Request` and has not been converted — that remains open, tracked below.

Only 3 `FormRequest` classes existed for a large admin write surface (now 6). Most write endpoints still accept a raw `Request`. This pairs naturally with fixing #1.

**Fix:** introduce FormRequests (with authorization + validation rules) for the remaining admin write endpoints — `AdminExerciseController`, `ChordController`, `ProgressionController`, `ProgressionBuilderController`, and the rest of `LeadsheetController`.

### 8. `UserProfile` mass assignment — Low — ✅ Fixed 2026-07-13

> **Resolved:** `app/Models/UserProfile.php` now uses `protected $fillable = ['display_name', 'bio', 'public']`. Verified against all call sites (`AccountController::updateProfile`/`profile`, `BackfillCustomerBackend`) — none mass-assign beyond those three fields; `avatar_path`/`last_seen_at` are set via direct property assignment and are unaffected.

`app/Models/UserProfile.php` used `protected $guarded = []`, leaving every attribute mass-assignable. 17 of 29 models correctly use `$fillable`.

### 9. Repo hygiene — Low — ✅ Fixed 2026-07-13

> **Resolved:** `git rm --cached` run on both `resources/js/tab-editor.zip` and `public/images/mega-menu/featured-collection.png` — untracked from git (already covered by the `*.zip`/`*.png` gitignore rules going forward), files left in place on disk. Neither is referenced anywhere in app code. The stray uncommitted files listed below were already gone from the working tree by the time this was checked — nothing to clean up.

- ~~Tracked despite now being in `.gitignore` (committed before the rules): `resources/js/tab-editor.zip` (80 KB) and `public/images/mega-menu/featured-collection.png`. Run `git rm --cached` on both.~~
- ~~Stray uncommitted files to remove or ignore: `# SBN Homepage — Skill Path Section.txt`, `ssr-test-tmp.mjs`, `scripts/probe_665785.php`, `scripts/probe_regress.php`, `database/sbn.db.bak-20260702-precleanup`.~~
- `ffmpeg.exe` (~100 MB) and `yt-dlp.exe` (~18 MB) sit in the repo root — correctly gitignored, but heavy working-tree clutter; consider relocating outside the project. (Still open — low priority.)

### 10. Tests & residue — Low — 🟡 Partial (test coverage added, baseline still not run)

47 test files across Unit/Feature/Integration. The PHPUnit result cache records ~105 non-passing entries against 258 timed tests, and `tests/Unit/IdentifierRegressionCases.php` has uncommitted changes. The suite was not run here (no `vendor/` in this sandbox — `composer install` never ran).

Added `tests/Feature/Admin/LeadsheetAdminRequestsTest.php` and `tests/Feature/Account/UserProfileFillableTest.php` to cover the #7/#8 changes above. Both follow `InstructorGateTest`'s pattern (transaction-wrapped against the real dev DB, rolled back in `tearDown`, never committed) rather than `RefreshDatabase`. Writing `UserProfileFillableTest` caught a real regression in the #8 fix: `firstOrCreate(['user_id' => ...], [...])` mass-assigns via `create()`, and Eloquent's `fillableFromArray()` strips any key not in `$fillable` — including `user_id` — *before* it reaches the model, so the initial `$fillable = ['display_name','bio','public']` (missing `user_id`) would have made every new profile row fail to persist its primary key. `user_id` was added to `$fillable`; safe because no call site ever mass-assigns it from raw request input. **These new tests have not been run** (same `vendor/` gap) — run them first when back on a machine with PHP + composer, they're the most direct regression check for tonight's changes.

**Fix:** run `php artisan test` (or at minimum the two new files above) to establish the current green/red baseline before relying on it. Also: 21 `console.log` calls remain in shipped JS and there are 68 `TODO/FIXME/HACK` markers across `app/` and `resources/js/`.

---

## Recommended order

Completed in this pass: **#1** (instructor guard), **#2** (deleted dead route file), **#4** (synced `.env.example`), **#6** (extracted shared `ChordQualityMapper`), **#8** (`UserProfile` mass-assignment fix), **#9** (untracked the two stray tracked files).

Partially completed: **#7** (FormRequests + `is_pro`/`public_domain` business-rule fix for the three endpoints the finding named; the rest of the admin write surface is still raw `Request`). **#3** checked — `deploy/env-production.txt` correctly templates `APP_DEBUG=false`, but the live production `.env` itself hasn't been directly inspected (no server access from this environment). **#10** — added `tests/Feature/Admin/LeadsheetAdminRequestsTest.php`, `tests/Feature/Account/UserProfileFillableTest.php`, and `tests/Unit/ChordQualityMapperTest.php` to cover tonight's changes; the full-suite baseline is still not established (no `vendor/` in this sandbox).

Deliberately deferred (2026-07-13): **#5** — scoped (see finding #5 above for the method inventory and the two low-risk pieces to start with) but not attempted; the real 4-way split needs a session with local test execution to verify safely.

**2026-07-14 update:** got `composer install` working in the remote sandbox (it failed/wasn't available on 2026-07-13), which unlocked real test execution — not just `php -l` — for anything that doesn't need `sbn.db`. Used that to complete the two low-risk #5 pieces flagged the day before (moved `youtubeSearch`/`resolveNumerals` out, extracted the ~900-line transcription cluster into `TranscriptionAssembler`), verified by actually running the two Identifier tests that exercise that code, not just reading it. `LeadsheetController`: 4,728 → 3,387 lines. Also caught and fixed a real bug in `tests/Unit/ChordQualityMapperTest.php` itself this way — it was silently running 0 test cases due to a PHPUnit-version mismatch (docblock `@dataProvider` vs. this repo's PHPUnit 12, which requires the `#[DataProvider]` attribute).

Remaining follow-up work:

1. **#7** — convert the remaining admin write endpoints (leadsheet CRUD, exercises, chords, progressions, rhythm patterns) to FormRequests.
2. **#3** — someone with production server access should confirm the live `.env` actually has `APP_DEBUG=false`.
3. **#5** — the real 4-way controller split (Crud/Voicing/Progression, plus resolving `createFromLookup`'s dual CRUD/transcription personality) is still open; the safe pieces are done.
4. **#10** — run `php artisan test` locally (with the real `sbn.db`) to establish the full green/red baseline. From this sandbox: all of `tests/Unit` plus `DetectionFilterTest`/`AudioContextRerankTest` pass except pre-existing, unrelated failures (`RhythmMaterializerTest`, 5 cases — untouched by any of this work) and DB-connection failures from tests that need the real `sbn.db` (expected, it isn't here).
