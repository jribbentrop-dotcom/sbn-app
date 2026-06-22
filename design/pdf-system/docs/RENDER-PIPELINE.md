# PDF Asset Render Pipeline

How to generate chord diagram SVGs and TAB notation SVGs for booklet pages.
All commands run from the project root (`C:/Users/info/sbn-app`).

---

## Chord Diagrams

```bash
node scripts/pdf/render-diagram.cjs <slug> --bw > design/pdf-system/assets/chord-svgs/<slug>.svg
```

- `--bw` strips CSS vars to hard `#000` — always use for print
- Slug must exist in `sbn_chord_diagrams`; query to verify:
  ```bash
  node -e "const db=require('better-sqlite3')('database/sbn.db',{readonly:true}); console.log(db.prepare('SELECT slug FROM sbn_chord_diagrams WHERE slug=?').get('<slug>'));"
  ```
- Output: self-contained SVG, `viewBox="0 0 88 98"`, inline in HTML or saved to file

---

## Item Page Practice TABs

The `top10` leadsheet (`sbn_leadsheets.slug = 'top10'`) contains the practice progressions for all 10 item pages. Query it, slice the measures you want, pipe to `render-tab.cjs`:

```bash
node -e "
  const db = require('better-sqlite3')('database/sbn.db', {readonly:true});
  const row = db.prepare('SELECT json_data FROM sbn_leadsheets WHERE slug=?').get('top10');
  const d = JSON.parse(row.json_data);
  // d.measures is 0-indexed; check count first:
  console.log('measure count:', d.measures.length);
" 
```

Then render a slice:
```bash
node -e "
  const db = require('better-sqlite3')('database/sbn.db', {readonly:true});
  const row = db.prepare('SELECT json_data FROM sbn_leadsheets WHERE slug=?').get('top10');
  const d = JSON.parse(row.json_data);
  const payload = {
    measures: d.measures.slice(START, END),  // e.g. slice(0, 3) for first 3 bars
    timeSig: d.timeSignature,
    barsPerRow: 3,
    showChordNames: true
  };
  process.stdout.write(JSON.stringify(payload));
" | node scripts/pdf/render-tab.cjs > design/pdf-system/assets/notation-svgs/top10-item01.svg
```

---

## Example Page Notation (Song Excerpts)

Same pattern — use the song's own leadsheet slug:

```bash
node -e "
  const db = require('better-sqlite3')('database/sbn.db', {readonly:true});
  const row = db.prepare('SELECT json_data FROM sbn_leadsheets WHERE slug=?').get('SLUG');
  const d = JSON.parse(row.json_data);
  const payload = {
    measures: d.measures.slice(START, END),
    timeSig: d.timeSignature,
    barsPerRow: 4,
    showChordNames: true
  };
  process.stdout.write(JSON.stringify(payload));
" | node scripts/pdf/render-tab.cjs > design/pdf-system/assets/notation-svgs/SLUG-bars-N-M.svg
```

### Example slugs and bar ranges for TOP10 booklet

| Example | Slug | Bars (0-indexed slice) |
|---------|------|----------------------|
| 1 — Ipanema | `the-girl-from-ipanema` | `slice(0, 16)` |
| 2 — So Danço Samba | `so-danco-samba` | `slice(0, 4)` |
| 3 — Blue Bossa | `blue-bossa` | `slice(0, 14)` |
| 4 — Manhã de Carnaval | `manha-de-carnaval` | `slice(0, 30)` |
| 5 — Once I Loved | `once-i-loved` | `slice(0, 16)` |
| 6 — Insensatez | `insensatez` | `slice(6, 10)` |
| 7 — Corcovado | `corcovado` | `slice(0, 14)` |

Songs not in `sbn_leadsheets`: use `[NOTATION: description]` placeholder — never hand-draw SVG coordinates.

---

## render-tab.cjs Input Format

```json
{
  "measures": [ MeasureObject, ... ],
  "timeSig": "4/4",
  "barsPerRow": 4,
  "showChordNames": true
}
```

`MeasureObject` shape (from `json_data.measures`):
```json
{
  "index": 0,
  "events": [ EventObject, ... ],
  "chordNames": ["Dm7"],
  "repeatStart": false,
  "repeatEnd": false,
  "volta": null
}
```

The `json_data` from `sbn_leadsheets` already has measures in this exact shape — no transformation needed, just slice and wrap.

---

## Embedding SVG in HTML

Inline the SVG directly into the HTML template — do not use `<img src="...">` for weasyprint compatibility:

```html
<div class="item__diagram-feature">
  <!-- paste SVG file contents here -->
  <svg viewBox="0 0 88 98" ...> ... </svg>
</div>

<div class="pattern-tab">
  <!-- paste render-tab.cjs output here -->
  <svg viewBox="0 0 560 120" ...> ... </svg>
</div>
```
