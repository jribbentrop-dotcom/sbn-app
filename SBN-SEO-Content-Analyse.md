# SBN – SEO & Content-Analyse

_Erstellt: 13. Juni 2026_

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

| Seite | Datei | Status |
|---|---|---|
| Homepage | `Home.vue` | ❌ kein Head |
| Akkord-Bibliothek | `Library/Chords/Index.vue` | ❌ kein Head |
| Akkord-Detail | `Library/Chords/Show.vue` | ❌ kein Head |
| Song-Bibliothek | `Library/Songs/Index.vue` | ❌ kein Head |
| Song-Detail | `Library/Songs/Show.vue` | ❌ kein Head |
| Progressionen | `Library/Progressions/Index.vue` | ❌ kein Head |
| Rhythmen | `Library/Rhythms/Index.vue` | ❌ kein Head |
| Music Theory | `Library/Theory/Index.vue` | ❌ kein Head |
| Kurse (Liste) | `Courses/Index.vue` | ❌ kein Head |
| Kurs (Detail) | `Courses/Show.vue` | ❌ kein Head |
| Top 10 Bossa Nova Songs | `Top10/BossaNovaSongs.vue` | ✅ |
| Top 10 Bossa Nova Chords | `Top10/BossaNovaChords.vue` | ✅ |
| Latin Jazz Standards | `Top10/LatinJazzStandards.vue` | ✅ |
| Shop (Liste) | `Shop/Index.vue` | ✅ |
| Shop (Produkt) | `Shop/Show.vue` | ✅ + OG |
| Song Viewer (Leadsheet) | `Library/Songs/Viewer.vue` | ✅ + OG |

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
