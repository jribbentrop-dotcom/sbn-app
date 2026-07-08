# SBN – SEO & Content-Analyse

_Erstellt: 13. Juni 2026 — Update: 7. Juli 2026_

---

## 🔄 Update 7. Juli 2026 — Umsetzungsstand

Alle Punkte aus dem ursprünglichen Audit (unten) wurden geprüft. Der Großteil war
bereits umgesetzt; ergänzt wurden JSON-LD sowie zwei Korrekturen, die beim
Nachprüfen aufgefallen sind.

**Bereits erledigt (vor diesem Update umgesetzt):**
- `APP_NAME="Soul Bossa Nova"` in `.env`
- `<Head>` (Title/Description/OG) auf Homepage, allen Library-Index/Show-Seiten,
  Progressions, Rhythms, Theory, Courses Index/Show, allen drei Top10-Seiten und
  Shop-Seiten
- `sitemap.xml` existiert (`SitemapController`, eigene Implementierung statt
  `spatie/laravel-sitemap`), `robots.txt` verweist darauf

**Neu in diesem Update:**
- **JSON-LD hinzugefügt:** `Organization`/`WebSite` auf der Homepage, `Course`
  auf `Courses/Show.vue`, `ItemList` auf `Courses/Index.vue` und allen drei
  Top10-Seiten. Technischer Hinweis: Vue verbietet literale `<script>`-Tags
  in Templates (werden beim Kompilieren stillschweigend entfernt) — Workaround
  ist `<component :is="'script'" type="application/ld+json">`, das Inertias
  `Head`-Komponente korrekt in ein echtes `<script>`-Element umwandelt.
- **`<Head>` ergänzt auf `Contact/Index.vue` und `Grades/Index.vue`** (hatten nur
  einen nackten `<title>`, keine Description/OG) — waren nicht in der
  Original-Tabelle unten, aber öffentliche, indexierbare Seiten.
- **Sitemap-Bug behoben:** Kurs-URLs zeigten auf `/courses` bzw.
  `/courses/{slug}` — das ist aber die auth-geschützte Account-Route. Die
  öffentliche Kurs-Seite liegt unter `/learn` bzw. `/learn/{slug}`
  (`routes/web.php:427-428`). Google bekam hier durchgehend 404/Redirect
  gemeldet. Jetzt korrigiert.
- **Sitemap bereinigt:** `/library/*`, `/theory` und die einzelnen
  `/library/songs/{slug}`-Einträge wurden entfernt — siehe kritischer Punkt
  unten. `/skills` und `/grades` (öffentlich, vorher fehlend) wurden ergänzt.

### 🔴 Neu entdecktes kritisches Problem: Auth-Gate blockiert Indexierung

`/library/*`, `/theory` und alle Unterseiten sitzen hinter `auth`-Middleware
(Beta-Account-Wall, siehe `routes/web.php:363-381` und `redirectGuestsTo()` in
`bootstrap/app.php`). Ein Gast — und damit auch Googlebot — wird auf
`/register` umgeleitet, **bevor** er den Seiteninhalt oder die neuen
Meta-Tags je zu sehen bekommt. Die Chord-, Song-, Progression-, Rhythm- und
Theory-Bibliothek sind aktuell also für Google technisch unsichtbar, egal wie
gut die SEO-Verpackung ist.

Das war im Original-Audit nicht berücksichtigt (es ging davon aus, diese
Seiten seien öffentlich crawlbar). Zwei Optionen:
1. **So lassen** (aktuell umgesetzt) — Account-Wall bleibt als bewusste
   Beta-/Conversion-Strategie bestehen; die Seiten wurden aus der Sitemap
   entfernt, um Search-Console-Fehler zu vermeiden.
2. **Einzelne Seiten öffnen** — z. B. gezielt die publikumsstärksten
   Song-Referenzseiten (Girl from Ipanema, Wave, Corcovado …) als Gäste-Teaser
   freigeben, um SEO-Traffic UND Registrierungen zu kombinieren. Größere
   Entscheidung, nicht im Rahmen dieses Updates umgesetzt.

### ⚠️ Weiterer Befund: Kein Server-Side-Rendering

Die App ist reines Client-Side-Rendering (Inertia ohne `resources/js/ssr.ts`,
`app.blade.php` hat nur `<title inertia>{{ config('app.name') }}</title>` als
Fallback). Titel, Description und OG-Tags werden erst nach dem Laden von
JavaScript gesetzt. Für Google ist das in der Regel unproblematisch (Googlebot
rendert JS), aber Social-Media-Crawler (Facebook, Slack, X/Twitter, LinkedIn)
führen meist **kein** JavaScript aus — Link-Vorschaubilder für Top10-, Shop-
und Song-Seiten funktionieren dadurch aktuell wahrscheinlich nicht zuverlässig,
obwohl die OG-Tags im Code vorhanden sind. Volle Abhilfe würde Inertia SSR
erfordern (zusätzlicher Node-Prozess neben PHP) — das ist ein größeres
Infrastruktur-Vorhaben und war außerhalb des Rahmens dieses Updates.

---

## 🔴 Kritische SEO-Probleme (sofort beheben)

### 1. APP_NAME = "Laravel" — Browser-Titel ist falsch
**Datei:** `.env`

```
APP_NAME=Laravel   ← das ist der aktuelle Wert
```

Jede Seite ohne explizites `<Head>` trägt den Titel **"Laravel"** in Google-Suchergebnissen und Browser-Tabs. Das ist das größte einzelne SEO-Problem der App.

**Fix:**
```
APP_NAME="Soul Bossa Nova"
```

---

### 2. Homepage (`Home.vue`) hat kein `<Head>`-Tag
Die wichtigste Seite der App hat weder Titel, Description noch OG-Tags.

**Fix — in `resources/js/Pages/Home.vue` hinzufügen:**
```vue
<Head>
  <title>Soul Bossa Nova — Gitarrenunterricht für Bossa Nova & Latin Jazz</title>
  <meta name="description" content="Lerne Bossa Nova Gitarre mit interaktiven Leadsheets, Akkord-Bibliothek, Rhythmus-Patterns und Video-Kursen. Für Einsteiger und Fortgeschrittene." />
  <meta property="og:title" content="Soul Bossa Nova — Gitarrenunterricht" />
  <meta property="og:description" content="Interaktive Bossa Nova Gitarren-Plattform mit Leadsheets, Theorie-Widgets und Kursen." />
</Head>
```

---

## 🟠 Hohe Priorität — Fehlende Meta-Tags auf öffentlichen Seiten

> **Stand 7. Juli 2026:** Diese Tabelle zeigt den Befund vom 13. Juni — alle
> ❌-Zeilen sind seither behoben (Head-Blöcke mit Title/Description/OG sind
> im Code vorhanden, siehe Update-Abschnitt ganz oben). Wichtige Einschränkung:
> `Library/*`, `Theory/Index.vue` und `Library/Songs/Viewer.vue` sitzen hinter
> der `auth`-Middleware (Beta-Account-Wall) — die Head-Tags sind zwar da, aber
> für Google/Gäste nicht erreichbar, solange das Gate steht (siehe kritischen
> Punkt im Update-Abschnitt oben).

| Seite | Datei | Status (13. Juni) | Status (7. Juli) |
|---|---|---|---|
| Homepage | `Home.vue` | ❌ kein Head | ✅ Head + JSON-LD, öffentlich crawlbar |
| Akkord-Bibliothek | `Library/Chords/Index.vue` | ❌ kein Head | ✅ Head vorhanden, aber **auth-gated** |
| Akkord-Detail | `Library/Chords/Show.vue` | ❌ kein Head | ✅ Head vorhanden, aber **auth-gated** |
| Song-Bibliothek | `Library/Songs/Index.vue` | ❌ kein Head | ✅ Head vorhanden, aber **auth-gated** |
| Song-Detail | `Library/Songs/Show.vue` | ❌ kein Head | ✅ Head vorhanden, aber **auth-gated** |
| Progressionen | `Library/Progressions/Index.vue` | ❌ kein Head | ✅ Head vorhanden, aber **auth-gated** |
| Rhythmen | `Library/Rhythms/Index.vue` | ❌ kein Head | ✅ Head vorhanden, aber **auth-gated** |
| Music Theory | `Library/Theory/Index.vue` | ❌ kein Head | ✅ Head vorhanden, aber **auth-gated** |
| Kurse (Liste) | `Courses/Index.vue` | ❌ kein Head | ✅ Head + JSON-LD (ItemList), öffentlich crawlbar |
| Kurs (Detail) | `Courses/Show.vue` | ❌ kein Head | ✅ Head + JSON-LD (Course), öffentlich crawlbar |
| Top 10 Bossa Nova Songs | `Top10/BossaNovaSongs.vue` | ✅ | ✅ + JSON-LD (ItemList) |
| Top 10 Bossa Nova Chords | `Top10/BossaNovaChords.vue` | ✅ | ✅ + JSON-LD (ItemList) |
| Latin Jazz Standards | `Top10/LatinJazzStandards.vue` | ✅ | ✅ + JSON-LD (ItemList) |
| Shop (Liste) | `Shop/Index.vue` | ✅ | ✅ unverändert |
| Shop (Produkt) | `Shop/Show.vue` | ✅ + OG | ✅ unverändert |
| Song Viewer (Leadsheet) | `Library/Songs/Viewer.vue` | ✅ + OG | ✅ + OG, aber **auth-gated** |
| Contact | `Contact/Index.vue` | *(nicht geprüft)* | ✅ Head ergänzt (hatte nur nackten Title) |
| Grades | `Grades/Index.vue` | *(nicht geprüft)* | ✅ Head ergänzt (hatte nur nackten Title) |
| Skills Glossary | `Skills/Glossary.vue` | *(nicht geprüft)* | ✅ war bereits vollständig |

### Empfohlene Titles & Descriptions für die wichtigsten Seiten

**Chord Library:**
```
Title: Bossa Nova & Jazz Akkorde | Soul Bossa Nova
Description: Durchsuche hunderte Gitarren-Akkorde mit interaktiven Diagrammen — Voicings, Intervalle und Fingersätze für Bossa Nova und Latin Jazz.
```

**Progressions Library:**
```
Title: Jazz Akkord-Progressionen | Soul Bossa Nova
Description: Lerne klassische ii-V-I, Bossa Nova und Latin Jazz Progressionen mit interaktiven Diagrammen und Audio-Playback.
```

**Theory Page:**
```
Title: Musiktheorie für Gitarristen | Soul Bossa Nova
Description: Interaktive Musik-Theorie-Widgets: Dreiklang-Builder, Quintenzirkel, Drop-2-Voicings und Voice-Leading — visuell und sofort verständlich.
```

**Courses:**
```
Title: Gitarren-Kurse | Soul Bossa Nova
Description: Video-Kurse für Bossa Nova Gitarre — von Grundlagen bis zu fortgeschrittenen Techniken mit Leadsheets und Übungen.
```

---

## 🟡 Mittlere Priorität

### 3. Kein Sitemap.xml
Die `robots.txt` ist vorhanden, aber leer (kein `Disallow`, keine Sitemap-Referenz). Google findet die Seiten nur durch Crawling.

**Empfehlung:** `spatie/laravel-sitemap` installieren und alle öffentlichen Library-, Top10-, Shop- und Course-URLs einbinden.

```bash
composer require spatie/laravel-sitemap
```

**robots.txt ergänzen:**
```
Sitemap: https://yourdomain.com/sitemap.xml
```

### 4. Keine OG-Tags auf Library- und Top10-Seiten
Beim Teilen der URLs in Social Media erscheinen keine Vorschaubilder/Texte. Nur `Shop/Show.vue` und `Library/Songs/Viewer.vue` haben OG-Tags.

### 5. Kein strukturiertes JSON-LD
Google kann Kurse, Songs und Akkord-Seiten nicht als Rich Results darstellen. Empfohlen:
- `Course` Schema auf `/courses/{slug}`
- `MusicComposition` auf Leadsheet-Seiten
- `FAQPage` auf Theory-Widgets

---

## 📈 Content-Analyse & Empfehlungen

### Was gut funktioniert
- **Top10-Seiten** sind SEO-optimiert und haben klare Keyword-Fokus (Bossa Nova Songs, Jazz Standards)
- **Interactive Widgets** (Triad Builder, Circle of Fifths, Drop2, Voice Leading) sind einzigartiger Content, den wenige Konkurrenten haben
- **Leadsheet Viewer** hat OG-Tags und eine gute Struktur
- **Chord Library** hat umfangreiche Daten — fehlt nur die SEO-Verpackung

### Content-Lücken & Chancen

| Thema | Suchvolumen-Potenzial | Aufwand | Empfehlung |
|---|---|---|---|
| "Bossa Nova Gitarre Anfänger" | Hoch | Mittel | Dedicated Landing Page oder Blog-Artikel |
| "Girl from Ipanema Akkorde" | Hoch | Niedrig | Song-Seite SEO-optimieren (Viewer hat schon OG) |
| "ii-V-I Progression Gitarre" | Mittel | Niedrig | Progressionen-Seite + Head-Tag |
| "Bossa Nova Rhythmus Gitarre" | Mittel | Niedrig | Rhythmen-Seite + Head-Tag |
| "Jazz Akkorde für Gitarristen" | Hoch | Niedrig | Chord Library + Head-Tag |
| "Musiktheorie für Gitarristen" | Mittel | Niedrig | Theory Page + Head-Tag |
| "Drop 2 Voicings Gitarre" | Niedrig-Mittel | Niedrig | Theory Widget-Seite SEO-optimieren |

### Empfohlene neue Content-Seiten
1. **Einsteiger-Guide**: "Bossa Nova Gitarre lernen — Schritt für Schritt" (Artikel/Landing Page)
2. **Rhythmus-Guides**: Eine Seite pro Rhythmus-Pattern (SEO-freundliche URLs wie `/rhythms/bossa-nova`)
3. **Song-Analyse-Seiten**: Für Top-Songs wie "Girl from Ipanema", "Corcovado", "Wave" — mit Akkorden, Rhythmus und Theorie-Erklärung
4. **FAQ-Bereich**: "Wie lerne ich Bossa Nova Gitarre?", "Was sind Drop-2-Voicings?" etc.

---

## ✅ Sofort-Maßnahmen (Priorität-Liste)

1. **`.env`**: `APP_NAME` von "Laravel" auf deinen Brand-Namen ändern
2. **`Home.vue`**: `<Head>` mit Title + Description + OG-Tags hinzufügen
3. **Library-Seiten** (5 Dateien): Je einen `<Head>`-Block hinzufügen — ca. 10 Minuten Arbeit pro Seite
4. **`Courses/Show.vue`**: Dynamischen Title aus Kurs-Daten erzeugen
5. **Sitemap**: `spatie/laravel-sitemap` einrichten

---

_Analyse basiert auf Code-Inspection der SBN-App (Laravel + Inertia + Vue 3)._
