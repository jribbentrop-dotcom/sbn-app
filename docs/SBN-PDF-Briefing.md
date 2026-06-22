# SBN PDF Briefing — Claude.ai Handoff

Dieses Dokument ist das Briefing für claude.ai bei der Erstellung neuer PDF-Produkte für den
SBN Teaching Hub Shop. Es enthält das Config-Format, verfügbare Assets und Konventionen.

---

## Workflow

1. **Input:** Du bekommst ein MuseScore-PDF (oder SVG-Export) mit dem originalen Content
2. **Aufgabe:** Erstelle eine fertige PHP-Config-Datei für das SBN-PDF-Pipeline-System
3. **Output:** Eine `.php`-Datei die direkt unter `config/pdf/{slug}.php` abgelegt wird
4. **Render:** Claude Code rendert das PDF aus der Config via `GET /admin/pdf/download/{slug}`

---

## Config-Format

Datei: `config/pdf/{slug}.php` — gibt ein PHP-Array zurück.

```php
<?php
return [
    // ── Meta ──────────────────────────────────────────────────────────
    'title'       => "TOP 10\nBossa Nova Akkorde",   // \n = Zeilenumbruch auf Cover
    'subtitle'    => 'Essential Voicings for Guitar', // kursiv unter Titel
    'series'      => 'SBN Teaching Hub · Top 10',    // Eyebrow-Label
    'description' => 'Kurzbeschreibung für Cover-Seite (1-2 Sätze).',
    'intro_html'  => '<p>Fließtext für Intro-Seite. Kann mehrere &lt;p&gt;-Tags enthalten.</p>',

    // ── Chord-Seiten ──────────────────────────────────────────────────
    // Slugs aus der SBN Chord Library (siehe Abschnitt "Verfügbare Slugs")
    'chords' => [
        'maj7-shell-roota',    // Reihenfolge = Seiten-Reihenfolge im PDF
        'maj7-drop2-rootd',
        // ...
    ],

    // Beschreibungen pro Chord-Slug (2 Absätze, getrennt durch \n\n)
    // Absatz 1: musikalische Funktion des Akkordtyps
    // Absatz 2: Bossa-Nova-spezifischer Kontext, typische Songs, Spielweise
    'chord_descriptions' => [
        'maj7-shell-roota' => "Das Maj7-Shell-Voicing ist der Einstieg in den Bossa-Nova-Klang. Mit nur drei Tönen — Grundton, Terz und Septime — klingt es transparent und offen.\n\nIn der Bossa Nova tritt dieses Voicing vor allem in langsamen Balladen auf. João Gilberto nutzte ähnliche Shell-Formen als Basis für seine Basslinien.",
        // ...
    ],

    // ── Rhythmus-Patterns ─────────────────────────────────────────────
    // 1-2 Slugs aus der SBN Rhythm Library (siehe unten)
    // Werden auf jeder Chord-Seite als 2-Spalten-Grid gezeigt
    'rhythms' => [
        'gilberto-rhythm',
        'samba',
    ],

    // ── Notation / TAB (optional) ────────────────────────────────────
    // MuseScore SVG-Exports, einem Akkord-Slug zugeordnet.
    // Jeder Eintrag: ['file' => 'svgexport/{dateiname}.svg', 'system' => N]
    //   file   = Pfad relativ zum App-Root (svgexport/-Ordner)
    //   system = 1-basierter Index der TAB-Systeme auf dieser SVG-Seite
    //            (jede MuseScore-Seite hat typischerweise 2 TAB-Systeme)
    'notation_svg' => [
        'maj7-drop2-roota' => ['file' => 'svgexport/MEIN-FILE-2.svg', 'system' => 1],
        'm7-drop2-roota'   => ['file' => 'svgexport/MEIN-FILE-2.svg', 'system' => 2],
        // ... weitere Akkorde
    ],

    // ── Song-Beispiele (optional) ─────────────────────────────────────
    // Leadsheet-Slugs aus der SBN Song Library
    'songs' => [
        [
            'slug'       => 'body-and-soul',   // SBN-Leadsheet-Slug
            'label'      => 'Body and Soul',
            'measures'   => [0, 7],             // Takt-Range (0-basiert)
            'barsPerRow' => 4,
        ],
    ],
];
```

---

## Chord-Descriptions — Stil-Guide

**Länge:** 2 Absätze, je 2-3 Sätze. Insgesamt ~80-120 Wörter.

**Absatz 1 — Musiktheoretische Funktion:**
- Was macht diesen Akkordtyp aus? (Intervallstruktur, Charakter)
- Warum klingt er so? (was fehlt, was ist besonders)
- Neutral, sachlich, aber nicht trocken

**Absatz 2 — Bossa Nova Kontext:**
- Wo taucht er in der Bossa Nova auf? (Songs, Komponisten, Situationen)
- Wie wird er gespielt? (Daumen, Arpeggios, Basslinien)
- Kann eine prägnante Referenz enthalten (Jobim, Gilberto, Bonfa etc.)

**Sprache:** Deutsch. Musikbegriffe auf Deutsch (Grundton, Terz, Septime, Quinte).

**Beispiel — gut:**
> Das Maj7-Shell-Voicing ist der Einstieg in den Bossa-Nova-Klang. Mit nur drei Tönen —
> Grundton, Terz und Septime — klingt es transparent und offen. Der fehlende Quintton
> gibt dem Klang seine typische Leichtigkeit.
>
> Dieses Voicing eignet sich besonders für langsame Balladen und als Ausgangspunkt für
> melodische Basslinien. João Gilberto nutzte ähnliche Formen in „Chega de Saudade".

**Beispiel — zu vermeiden:**
> ~~Dieses Voicing hat Root, Third und Seventh. Es ist ein Shell Voicing. Es klingt gut in Bossa Nova.~~

---

## Verfügbare Chord-Slugs

Die Slugs sind **root-unabhängig** — dasselbe Voicing in C, D, G etc. hat denselben Slug-Prefix.
Das System rendert immer die C-Root-Voicing; die Description bezieht sich auf den Akkordtyp, nicht die Root.

### Maj7
| Slug | Voicing | Position |
|------|---------|----------|
| `maj7-shell-roota` | Shell | Root auf A-Saite |
| `maj7-shell-roote` | Shell | Root auf E-Saite |
| `maj7-drop2-roota` | Drop 2 | Root auf A-Saite |
| `maj7-drop2-rootd` | Drop 2 | Root auf D-Saite |
| `maj7-drop3-roote` | Drop 3 | Root auf E-Saite |

### m7
| Slug | Voicing | Position |
|------|---------|----------|
| `m7-shell-roota` | Shell | Root auf A-Saite |
| `m7-shell-roote` | Shell | Root auf E-Saite |
| `m7-drop2-roota` | Drop 2 | Root auf A-Saite |
| `m7-drop2-rootd` | Drop 2 | Root auf D-Saite |

### dom7
| Slug | Voicing | Position |
|------|---------|----------|
| `dom7-shell-roota` | Shell | Root auf A-Saite |
| `dom7-shell-roote` | Shell | Root auf E-Saite |
| `dom7-drop2-roota` | Drop 2 | Root auf A-Saite |
| `dom7-drop2-rootd` | Drop 2 | Root auf D-Saite |

### m7b5 (Halbvermindert)
| Slug | Voicing | Position |
|------|---------|----------|
| `m7b5-drop2-roota` | Drop 2 | Root auf A-Saite |
| `m7b5-drop3-roote` | Drop 3 | Root auf E-Saite |

### o7 (Vermindert)
| Slug | Voicing | Position |
|------|---------|----------|
| `o7-drop2-roota` | Drop 2 | Root auf A-Saite |
| `o7-drop3-roote` | Drop 3 | Root auf E-Saite |

### m6
| Slug | Voicing | Position |
|------|---------|----------|
| `m6-shell-roota` | Shell | Root auf A-Saite |
| `m6-shell-roote` | Shell | Root auf E-Saite |
| `m6-drop2-rootd` | Drop 2 | Root auf D-Saite |

### mMaj7
| Slug | Voicing | Position |
|------|---------|----------|
| `mmaj7-shell-roote` | Shell | Root auf E-Saite |

### Weitere Qualities
`maj6`, `add9`, `7sus4`, `aug`, `sus2`, `sus4` — auf Anfrage.

> **Hinweis:** Wenn ein Akkordtyp aus dem MuseScore-File keinen passenden Slug hat,
> schreibe `// TODO: slug für [Akkordname]` als Kommentar in die Config.

---

## Verfügbare Rhythm-Pattern-Slugs

### Bossa Nova
| Slug | Name | Takt |
|------|------|------|
| `gilberto-rhythm` | Gilberto Rhythm | 2/4 |
| `extended-gilberto-rhythm` | Extended Gilberto Rhythm | 2/4 |
| `samba` | Samba | 2/4 |
| `samba-brasil` | Samba Brasil | 2/4 |
| `partido-alto` | Partido Alto | 2/4 |
| `partido-alto-reversed` | Partido Alto Reversed | 2/4 |
| `desafinado` | Desafinado | 2/4 |
| `bonfa` | Choro | 2/4 |
| `insensatez` | Insensatez | 2/4 |
| `baiao` | Baião | 2/4 |

### Jazz / Latin
| Slug | Name | Takt |
|------|------|------|
| `bossa-nova-clave` | Bossa Nova Clave | 4/4 |
| `jazz-bossa-nova` | Jazz Bossa Nova | 4/4 |
| `swing` | Swing | 4/4 |
| `son-clave-3-2` | Son Clave 3-2 | 4/4 |
| `charleston` | Charleston | 4/4 |

> **Empfehlung:** Pro PDF 2 Rhythmus-Patterns wählen — einen Basis- und einen Variationspattern.
> Für Bossa Nova: `gilberto-rhythm` + `samba` oder `gilberto-rhythm` + `partido-alto`.

---

## Layout-Struktur einer Chord-Seite

Zur Orientierung — das ist was das Template rendert:

```
┌─────────────────────────────────────────────────┐
│ SBN TEACHING HUB          TOP 10 Bossa Nova      │  ← Page Header
├─────────────────────────────────────────────────┤
│  1   CMaj7(9)                                    │  ← Zone A: Nummer + Name
│      [Drop 2] [Root A] [Root Position]           │      + Voicing-Tags
├──────────────┬──────────────────────────────────┤
│  [Diagramm]  │  BESCHREIBUNG                    │  ← Zone B: Diagram (links)
│  (farbig,    │  Fließtext Absatz 1...            │      + Description (rechts)
│   mit Guide- │                                   │
│   Tones)     │  Fließtext Absatz 2...            │
│              │                                   │
│  R · 3 · 5   │                                   │
│  C · E · G   │                                   │
├─────────────────────────────────────────────────┤
│  RHYTHMUS-PATTERN                                │  ← Zone C: 2-Spalten Rhythmus
│  [Gilberto]          [Samba]                    │
│  [SVG-Pattern]       [SVG-Pattern]              │
│  Beschreibung...     Beschreibung...            │
├─────────────────────────────────────────────────┤
│ SBN Teaching Hub    CMaj7(9)    sbn-teaching.com │  ← Page Footer
└─────────────────────────────────────────────────┘
```

---

## Dein Input für claude.ai

Beim Erstellen einer neuen PDF-Config übergibst du:

1. **Dieses Dokument** (SBN-PDF-Briefing.md)
2. **Das MuseScore-PDF** (oder SVG-Export, Seiten 1-3 reichen für den Content-Überblick)
3. **Die MSCX/MusicXML-Datei** (für präzise Chord-Symbole und Reihenfolge)
4. **Prompt:** „Erstelle die PHP-Config für dieses PDF. Verwende das Config-Format aus dem Briefing. Schreibe die chord_descriptions auf Deutsch im beschriebenen Stil. Wähle passende Slugs aus den verfügbaren Listen."

---

## Render-Befehle (Claude Code)

```bash
# Preview im Browser
GET http://sbn-app.test/admin/pdf/preview/{slug}

# PDF herunterladen
GET http://sbn-app.test/admin/pdf/download/{slug}

# Config liegt unter:
config/pdf/{slug}.php
```

---

## TAB-Renderer Pipeline (Stand 2026-06-16)

Der TAB-Renderer ist **produktionsreif** für Leadsheet-basierte PDFs. Er arbeitet
vollständig server-seitig (PHP → Node → SVG), kein Browsershot-JavaScript nötig.

### Komponenten

| Datei | Rolle |
|---|---|
| `app/Services/TabXmlParser.php` | Parsed `tab_xml` (MusicXML) aus `sbn_leadsheets` → Measure/Event-Array mit Beams, Ties, xPos |
| `scripts/pdf/render-tab-v2.cjs` | Node-Script: rendert ein SVG-Row aus dem JSON-Payload |
| `scripts/pdf/render-diagram-inline.cjs` | Node-Script: rendert ein B&W Chord-Diagramm aus Inline-Voicing-Daten (kein DB-Lookup) |
| `scripts/pdf/render-diagram.cjs` | Node-Script: rendert ein Chord-Diagramm aus einem DB-Slug (mit `--bw` Flag für B&W) |

### Datenfluss

```
sbn_leadsheets.tab_xml (MusicXML)
    → TabXmlParser::parse($xml, $chordNames)
    → { measures[], timeSig }

sbn_leadsheets.json_data.sections[0]
    → lineBreaks[]     (Takte pro Zeile)
    → measures[].chords[].name   (Akkordnamen pro Takt)

shortcode_content [sbn_voicings] Block
    → { chordName: { frets, position, fingers } }
    → render-diagram-inline.cjs → SVG (B&W, 3 Frets, inline)

PHP baut Rows + ruft render-tab-v2.cjs auf:
    Input: { measures, timeSig, barsPerRow, showChordNames, voicings }
    voicings: { chordName: svgMarkup }  ← raw SVG, kein Data-URI
    Output: <svg>...</svg> Row
```

### render-tab-v2.cjs Input-Format

```json
{
  "measures": [...],
  "timeSig": "2/4",
  "barsPerRow": 4,
  "showChordNames": true,
  "voicings": {
    "Db6(9)/Ab": "<svg class=\"sbn-chord-svg\"...>...</svg>",
    "G7": "<svg ...>...</svg>"
  }
}
```

`voicings` ist optional. Wenn übergeben, erscheint das Diagramm **beim ersten Vorkommen**
des Akkordnamens in der Row über dem Chord-Name-Text. Jeder Akkord erscheint nur einmal
(PHP trackt `$seenChords` über alle Rows).

### Visuelles Layout einer Row (mit Diagrammen)

```
┌────────────────────────────────────────────────┐
│  Db6(9)/Ab          G7                          │  ← Chord-Name (zentriert über Diagramm)
│  [Diagramm]         [Diagramm]                  │  ← B&W Chord-Diagram (65×68px)
│  ──────────────────────────────────────────     │  ← TAB-Strings (6 Linien)
│  4    4    4    4                               │  ← Fret-Nummern
│  ──────────────────────────────────────────     │
│  (Beams, Ties, Rests, Repeat Signs)             │
└────────────────────────────────────────────────┘
```

### Schrift-Abhängigkeiten

- **Bravura** (`/public/fonts/Bravura.otf`) — SMuFL-Glyphen: Flags, Rests
- **Crimson Text** (Google Fonts CDN) — Fret-Nummern (13px, 900 weight), Chord-Namen (14px, 600 weight)
- Beide müssen im HTML geladen sein; SVG wartet mit `document.fonts.load()` vor Anzeige

### Prototype-Script

`tinker_tmp.php` im App-Root ist das Prototype-Script für den Test-Render nach
`public/pdf-test.html` → `http://sbn-app.test/pdf-test.html`. Enthält den vollen
Datenfluss: Parser → Row-Split → Diagram-Render → TAB-Render → HTML.

### Bekannte Lücken / nächste Schritte

- **Voltas** (1./2. Klammer): Parser liest sie noch nicht aus MusicXML
- **Pickup-Takte**: `pickupBeats` wird nicht aus MusicXML gelesen
- **Diagramm-Clipping**: 3-Fret-Crop funktioniert für die meisten Shapes; ein Akkord
  mit einer Note im 4. Fret (relativ zur Position) würde abgeschnitten — selten in der Praxis
- **Voicings ohne `[sbn_voicings]` Block**: Manche Leadsheets haben keinen Block;
  dann keine Diagramme, nur Chord-Namen-Text
- **Blade-Template**: `tinker_tmp.php` ist Prototyp. Für Produktion: eigene
  Blade-View + Controller-Route nach dem Muster von `chord-book.blade.php`

---

## Bekannte Einschränkungen (Stand 2026-06-16)

- **Notation/TABs aus MuseScore-SVG:** Extraktion implementiert (`scripts/pdf/extract-tab-svg.cjs`). Für Chord-Book-PDFs referenziert via `notation_svg`-Key. **Layout-Integration im Chord-Book-Template steht noch aus.**
- **Song-Beispiele als TAB:** Vollständig implementiert via `TabXmlParser` + `render-tab-v2.cjs` (siehe TAB-Renderer Pipeline oben). Funktioniert wenn Leadsheet in DB (`sbn_leadsheets`) existiert und `tab_xml` befüllt ist.
- **Chord-Descriptions in DB:** Die meisten Voicings haben keine DB-Description — `chord_descriptions` in der Config überschreibt/ersetzt die DB.
- **Root-Transposition:** Das System zeigt immer C-Root-Voicings. Slug-Matching ist root-unabhängig.
