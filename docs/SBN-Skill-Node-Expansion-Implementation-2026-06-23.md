# SBN Skill Node Expansion — Implementation Brief (for Claude Code)

> Handoff doc. The code changes below are **already written** (by a Cowork session with file
> access to this repo, no artisan available there). What's left needs `php artisan`, which that
> session didn't have. This doc tells you exactly what to run and verify.
>
> Full evidence/reasoning for every node: `docs/SBN-Skill-Node-Expansion-Audit-2026-06-23.md`.
> Background on the system itself: `docs/SBN-Skill-System-Plan.md`.

## What's already done (code, not yet seeded)

Three files were edited/created, all additive — nothing existing was removed or renamed:

1. **`database/seeders/SkillNodeSeeder.php`** — added 15 nodes to the `NODES` const:
   - Rhythm (+6): `meter-basics`, `waltz-feel`, `swing-feel`, `polyrhythm`, `clave-systems`,
     `brazilian-rhythm-styles`
   - Harmony (+7): `diatonic-harmony`, `cadences`, `pop-progressions`, `turnarounds`,
     `secondary-dominants`, `borrowed-chords`, `voice-leading`
   - Reading & Theory (+2): `scale-degrees`, `tab-reading-basics`

   Also changed 3 existing nodes' `prereqs` (additive — they keep their old prereqs plus the new one):
   - `pulse-subdivision`: now requires `meter-basics` (was a root node, now has a floor under it)
   - `leadsheet-reading`: now also requires `scale-degrees`
   - `nashville-number-system`: now also requires `scale-degrees`

   **Deliberately NOT added:** `fretboard-note-names`. It's a real gap (CAGED, position-shifting,
   and shell-voicings all implicitly assume fretboard note knowledge) but no lesson currently teaches
   it — see the audit doc's "Technique" section. Seeding it now would create a node pointing at no
   content. Decide separately whether to write that lesson first; the node can be added in five
   minutes once it exists.

2. **`database/seeders/CourseSkillNodeMappingSeeder.php`** (new file) — additive
   `syncWithoutDetaching` pivot inserts for 7 courses, keyed by slug, including the previously
   **completely unmapped** Course 5 (Choro) and the previously 3-of-8-lessons-mapped Course 70
   (Chord Progressions & Voice Leading). Full mapping table is in the file's `MAPPINGS` const and
   in the audit doc.

3. **`database/seeders/DatabaseSeeder.php`** — registered `CourseSkillNodeMappingSeeder::class`
   in the `$this->call([...])` array, after `SkillNodeSeeder::class` (order matters — the new node
   slugs must exist before the pivot seeder looks them up).

I verified the new `NODES` array by hand-parsing it (regex graph walk, not `php artisan`): **no
dangling prerequisite refs, no cycles, no self-prerequisites**, 35 existing + 15 new = 50 entries in
the const. That's a static check of the PHP array, not a DB check — re-verify after seeding (below).

## What you need to do

1. **Run the two seeders directly** (not full `db:seed`, which would also re-run
   `User::factory()->create()` in `DatabaseSeeder::run()` — harmless if idempotent but no need to
   touch it):
   ```bash
   php artisan db:seed --class=SkillNodeSeeder
   php artisan db:seed --class=CourseSkillNodeMappingSeeder
   ```
   Both are idempotent (`updateOrCreate` / `insertOrIgnore` / `syncWithoutDetaching`), so re-running
   is safe if something fails partway.

2. **If you hit a "disk image malformed" or disk I/O error** running artisan against
   `database/sbn.db` directly — that's the known mount issue documented at the top of this repo's
   `CLAUDE.md` ("Database workflow (critical)"). Copy the db to a local working path, point your DB
   connection at the copy, run the seeders, copy back. Shouldn't apply if you're running natively on
   Lucas's machine rather than through a network/Cowork mount, but the workflow is there if needed.

3. **Verify the seed**, e.g. via `php artisan tinker`:
   ```php
   \App\Models\SkillNode::count(); // expect 53 (38 current + 15 new)
   \App\Models\SkillNode::where('branch', 'rhythm')->count(); // expect 10
   \App\Models\SkillNode::where('branch', 'harmony')->count(); // expect 18
   \App\Models\SkillNode::where('branch', 'reading-theory')->count(); // expect 6
   DB::table('sbn_course_skill_node')->count(); // expect 72 + (new rows from the 7 courses)
   ```
   Then re-run the cycle/dangling-ref check the original migration's docblock mentions doing on
   first seed — same idea, just against the live `sbn_skill_node_prerequisites` table this time
   instead of a static parse of the PHP source.

4. **Spot-check in the admin Skill Nodes editor** (`/admin/skill-nodes`):
   - Course 5 (Choro) should now show 5 nodes instead of 0.
   - Course 70 (Chord Progressions & Voice Leading) should show 10 nodes instead of 3.
   - `pulse-subdivision` should show `meter-basics` as a prerequisite, not show as a root anymore.

5. **Decide on the two things I resolved by judgment call, not certainty** (flagged in case you or
   Lucas disagree):
   - Gave `clave-systems` / `brazilian-rhythm-styles` their own new sub-branch, `"Latin Rhythm"`,
     under Rhythm, rather than folding into the existing `"Feels"` sub-branch. Easy to change — it's
     just the `sub_branch` string in the `NODES` array, no migration needed.
   - Left `fretboard-note-names` out entirely (see above) rather than seeding it with no content.

6. **Update `docs/SBN-Skill-System-Plan.md`'s status header** once the seed is confirmed — it
   currently says "35 nodes ... 38 prerequisite edges ... 15 of 16 courses." After this lands it
   should read something closer to "53 nodes, X edges, course coverage closes the Choro gap." I
   didn't pre-edit that doc since I don't have the real post-seed edge count without running artisan.

## Files touched

- `database/seeders/SkillNodeSeeder.php` (edited)
- `database/seeders/CourseSkillNodeMappingSeeder.php` (new)
- `database/seeders/DatabaseSeeder.php` (edited — one line added to the `$this->call([...])` array)
- `docs/SBN-Skill-Node-Expansion-Audit-2026-06-23.md` (new, written earlier this session — the
  evidence/reasoning doc this implementation is based on)
