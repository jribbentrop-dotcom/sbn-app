# SBN PDF Pipeline — Planungsdokument (überarbeitet, codebase-verifiziert)

**Zweck:** Druckbare PDFs für den SBN Shop, die **dieselben Komponenten und Daten wie die
Webapp** verwenden — Chord-Diagramme, Rhythmus-Patterns, TAB/Notation. Single Source of Truth.

**Stand:** Überarbeitet 2026-06-08 nach Codebase-Prüfung. Ersetzt den ursprünglichen
PHP/Python/ReportLab-Plan (der von claude.ai ohne Code-Kenntnis stammte).
**App-Kontext:** Laravel 11 / Vue 3 / Vite / Inertia · SQLite · Laravel Herd (Windows)

---

## 0. Kernentscheidungen (festgelegt)

| Frage | Entscheidung | Begründung |
|---|---|---|
| **Render-Engine** | HTML → Chromium (**Browsershot**) | Nutzt echte SVG-Renderer + CSS-Tokens. Kein Design-Doppelbau. PDF == Webapp. |
| **Komponenten** | **Kein Vue-Mount.** SVG-Kernfunktionen direkt aufrufen, SVG-String ins HTML einbetten | Die Vue-Komponenten sind nur dünne Hüllen um SVG-erzeugende Funktionen. Statisch reicht fürs PDF. |
| **Chord-Daten** | Referenz auf **Chord Library** (`sbn_chord_diagrams.slug`) | Diagramm, Intervalle, Beschreibung liegen schon in der DB. Kein Neu-Beschreiben. |
| **Notation/TAB** | **Aus eigenen Daten rendern** via vorhandenem SVG-Renderer | `TabMeasure.vue` (760 Z.) baut Noten/Hälse/Balken als SVG-String — keine VexFlow-Abhängigkeit. |
| **mscz → Daten** | User exportiert `.mscz` → XML; Akkorde landen als `[sbn_leadsheet]` ODER direkt verlinkt | Kein neuer MusicXML-Reader nötig für v1. |

---

## 0a. MACHBARKEIT VERIFIZIERT (2026-06-08) ✅

Die komplette Kette ist Ende-zu-Ende getestet und grün:

1. **Browsershot installiert** (`spatie/browsershot` via composer) + **`puppeteer`** (npm, mit
   `PUPPETEER_SKIP_DOWNLOAD=true` → nutzt **System-Chrome**, kein 150MB-Download).
2. **HTML → A4-PDF** funktioniert: `MediaBox 595×842pt`, `@page`, `page-break-after`,
   `showBackground()` (dunkle Header) ✓. Chrome-Pfad via `->setChromePath('C:\Program Files\Google\Chrome\Application\chrome.exe')`.
3. **`sbnRenderDiagramSVG()` läuft STANDALONE in Node** (kein Browser, kein Vue) — mit minimalem
   `document`-Stub für die `DOMContentLoaded`-Zeile am Modulende (`public/js/chords.js:620`).
   Liefert für einen echten DB-Akkord (`maj7-drop2-rootd-inv2`) einen 2KB-SVG-String mit
   korrekten Punkten/Bünden/Labels.
4. **SVG ins PDF**: SVG-String in HTML eingebettet → Browsershot → PDF mit **173 echten
   Vektor-Operatoren** im (dekomprimierten) Content-Stream. Geometrie ist drin, kein Platzhalter.

**Konsequenz:** Der riskanteste Pfad (Komponenten → PDF) ist bewiesen. Kein Vue-Runtime im PDF nötig.

### Gotchas aus dem Test (wichtig für die Umsetzung)
- **Herd-Toolchain läuft über PowerShell**, nicht Git-Bash — `php`/`composer` sind dort `.bat`-Wrapper, im Bash-PATH fehlt `php`.
- **SVG nutzt CSS-Variablen** (`var(--clr-text)`, `var(--clr-red)`) → die Print-Seite MUSS diese Tokens in `:root` definieren, sonst sind Linien/Punkte unsichtbar.
- `chords.js` ist **kein Modul** (kein `export`) — Funktion landet im globalen Scope; in Node via `vm.runInContext` mit `document`-Stub laden, dann `sandbox.sbnRenderDiagramSVG` greifen.
- `ChordDiagram.vue` macht die `diagram_data → fret/finger-String`-Umrechnung selbst (Zeilen 48–89) — diese Mapping-Logik muss in den standalone-Renderer mitportiert werden (nicht nur die SVG-Funktion).

### Bewiesenes Node-Pattern (Startpunkt für Schritt 1)

Dieses Snippet hat im Test funktioniert — `chords.js` in einer VM-Sandbox laden, dann
`sbnRenderDiagramSVG` aufrufen. Die fret/finger-Mapping-Logik (aus `ChordDiagram.vue:48–89`)
muss noch ergänzt werden, damit aus `diagram_data` der korrekte `voicing`-Input wird:

```js
// scripts/pdf/render-diagram.cjs  (Startpunkt)
const fs = require('fs'); const vm = require('vm');
const noop = () => {};
const fakeEl = new Proxy({}, { get: () => noop, set: () => true });
const sandbox = { window: {}, console, document: {
  addEventListener: noop, createElement: () => fakeEl,
  body: { appendChild: noop }, querySelectorAll: () => [], getElementById: () => null,
}};
sandbox.window.document = sandbox.document;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync('public/js/chords.js', 'utf8'), sandbox);
const render = sandbox.sbnRenderDiagramSVG;
// voicing = { frets, fret_string, position, start_fret, fingers }  ← aus diagram_data bauen
const svg = render(voicing, { showFingers: true, dotColor: '#1a1a2e', intervalLabels });
```

PHP-Seite ruft das via `Process::run('node scripts/pdf/render-diagram.cjs <slug>')` ODER man
portiert die ~40 Zeilen `sbnRenderDiagramSVG` direkt nach PHP (kein Node-Subprozess nötig).
Entscheidung Schritt 1.

---

## 1. Was die Codebase BEREITS hat (verifiziert)

Der Original-Plan wollte 6 Komponenten neu bauen. Tatsächlich existieren 4 davon:

| Original-Plan-Stufe | Realität in der Codebase | Status |
|---|---|---|
| [3] DB-Lookup Chord→Voicing | `app/Services/VoicingMaterializer.php` — nimmt `chord_name`+`frets`+`position`, baut `tab_xml` | ✅ vorhanden |
| [4] Chord-Diagramm-Renderer | `resources/js/Components/Library/ChordDiagram.vue` | ✅ vorhanden |
| [5] Rhythm-Pattern-Renderer | `RhythmStrip.vue` / `RhythmPattern.vue` (+ `RhythmPattern`-Model) | ✅ vorhanden |
| [2] Chord-Daten-Quelle | `LeadsheetParser.php` parst `[sbn_leadsheet]`-Shortcode → `chordVoicings`,`sections`,`measures` | ✅ vorhanden (eigenes Format, **kein** MusicXML-Reader) |
| Notation/TAB-Renderer | `resources/js/tab-editor/components/TabMeasure.vue` — reines SVG (Noten, Hälse, Balken, Bindebögen, Pausen, TAB-Zahlen) | ✅ vorhanden |
| Chord-Detail-Daten | `sbn_chord_diagrams` Tabelle: `slug`, `diagram_data` (JSON), `interval_labels`, `notes`, `description`, `start_fret` | ✅ vorhanden |

**DB-Korrekturen ggü. Original-Plan:**
- Tabelle heißt `sbn_chord_diagrams` (nicht `chord_diagrams`).
- Voicing liegt in **einer JSON-Spalte** `diagram_data`: `{"positions":[{"string":2,"fret":3,"finger":"2"}],"barres":[],"muted":[],"open":[]}` — nicht in flachen `frets[]`/`fingers[]`.
- Quality ist z.B. `o7` (nicht `dim7`). Normalisierungs-Tabelle muss gegen echte `quality`-Werte validiert werden.
- `interval_labels` (`"x,R,b5,bb7,b3,x"`) und `notes` (`"x,C,Gb,A,Eb,x"`) liegen fertig vor → Scale-Degrees müssen NICHT berechnet werden.

---

## 2. Architektur (überarbeitet)

```
.mscz  ──(User exportiert)──►  MusicXML/XML
                                   │
                                   │  v1: Akkordfolge manuell als [sbn_leadsheet]
                                   ▼
                        ┌──────────────────────┐
                        │  Datenaufbereitung    │
                        │  - Chord-Liste (10x)  │  ──► je Chord: sbn_chord_diagrams.slug
                        │  - Song-Beispiele     │  ──► LeadsheetParser + VoicingMaterializer
                        └──────────┬───────────┘
                                   ▼
                  ┌─────────────────────────────────┐
                  │  PDF-HTML-Builder (PHP/Blade)     │
                  │  GET /admin/pdf/preview/{slug}    │
                  │                                   │
                  │  bettet STATISCHE SVG-Strings ein:│
                  │   sbnRenderDiagramSVG()  (Node)   │
                  │   TabMeasure-SVG-Logik   (Node)   │
                  │   Rhythmus-SVG                    │
                  │   + :root CSS-Tokens + @page      │
                  │  (KEIN Vue-Runtime, kein Mount)   │
                  └──────────────┬──────────────────┘
                                 ▼
                        ┌──────────────────┐
                        │  Browsershot      │  (Chromium headless)
                        │  → A4 PDF         │
                        └──────────────────┘
                                 ▼
                  storage/app/private/products/pdfs/{slug}.pdf
                                 ▼
                  Shop-Produkt (wie caminhos-cruzados, the-gentle-rain)
```

---

## 3. Offene Punkte / noch zu klären

1. **Browsershot-Setup:** `spatie/browsershot` + Node + Chromium auf dem Herd-Windows-System. Prüfen ob Puppeteer-Chromium oder System-Chrome genutzt wird. (Noch NICHT installiert.)
2. **Print-CSS & Seitenumbrüche:** A4, feste Seiten pro Chord. `break-after: page`. Header/Footer via Browsershot `headerTemplate`/`footerTemplate` ODER fixed CSS.
3. **Komponenten isoliert rendern:** Können `ChordDiagram`/`TabMeasure` außerhalb ihres üblichen Kontexts (ohne Editor-State, ohne Audio) gemountet werden? `TabMeasure` hat Cursor/Input-Logik — evtl. read-only-Prop oder schlanke Print-Variante nötig.
4. **Chord→Slug-Zuordnung:** Die 10 Bossa-Akkorde aus der `.mscz` (`Maj7`,`Maj7(9)`,`m7`,...) auf konkrete `sbn_chord_diagrams.slug` mappen. Manuell für v1 (10 Stück), später automatisierbar.
5. **Notation neu rendern:** liefert `VoicingMaterializer` genug für die vollen Song-Beispiel-Seiten, oder nur Akkord-Blöcke? Melodie/Noten der Song-Beispiele prüfen.
6. **Referenz-PDFs** (`caminhos-cruzados-joao-bosco.pdf`, `the-gentle-rain.pdf`, `TOP10 Bossa Nova Chords.pdf`, 14 S.) als Layout-Vorlage — kein PDF-Rasterizer auf dem System installiert, daher visuell noch nicht inspiziert.

---

## 4. Implementierungs-Reihenfolge (überarbeitet)

| Schritt | Aufgabe | Aufwand | Abhängigkeit | Status |
|---|---|---|---|---|
| 0 | Browsershot+puppeteer installieren, A4-PDF + SVG-Einbettung beweisen | klein | Node/Chrome | ✅ erledigt 2026-06-08 |
| 1 | `SbnDiagramSvg` (Node-Skript): `slug`/`diagram_data` → SVG-String (chords.js + fret/finger-Mapping aus ChordDiagram.vue) | klein | 0 | |
| 2 | Print-Layout-Shell: Titel-, Intro-, Chord-Seite als Blade mit `:root`-Tokens + `@page` | mittel | 0 | |
| 3 | Chord-Seite: Diagramm-SVG + Intervalle + Beschreibung aus `sbn_chord_diagrams` | klein | 1,2 | |
| 4 | Rhythmus-SVG einbetten (statische Variante, ohne AudioEngine) | klein | 2 | |
| 5 | TAB/Notation-SVG: `TabMeasure`-Zeichenlogik standalone extrahieren | mittel | 2 | |
| 6 | Chord→Slug-Mapping für TOP10 Bossa (10 Akkorde) | klein | 3 | |
| 7 | Song-Beispiel-Seite (Pills + Mini-Diagramme + Notation) | mittel | 3,5 | |
| 8 | Admin-Route `/admin/pdf/preview/{slug}` + `/download` | klein | 2 | |
| 9 | Als Shop-Produkt anbinden (Pattern: bestehende PDF-Produkte) | klein | 8 | |

---

## 5. Stil-Referenzen (im Repo vorhanden)

- `TOP10 Bossa Nova Chords.pdf` (Repo-Root) — das **Ziel-Layout**, 14 Seiten.
- `AKKORDE - TOP10 Bossa Nova.mscz` (Repo-Root) — Quelle, 108 `<Harmony>`-Einträge, TPC-Roots.
- `storage/app/private/products/pdfs/{caminhos-cruzados,the-gentle-rain}.pdf` — bereits verkaufte PDFs als Stil-Referenz.

---

## 6. Verworfen ggü. Original-Plan

- ❌ ReportLab/Python-Composer — würde Design-System dreifach pflegen (Vue + PHP-Port + Python-Dict).
- ❌ PHP-Port von `sbnRenderDiagramSVG()` — `ChordDiagram.vue` existiert.
- ❌ Statische Rhythm-Pattern-SVGs — `RhythmStrip` existiert.
- ❌ Eigener MSCX/MusicXML-Reader für v1 — Akkorde manuell via `[sbn_leadsheet]` / Slug-Referenz.
- ❌ MuseScore-Notation als Bild einbetten — Notation wird aus eigenen Daten (`TabMeasure`) gerendert.
