<?php
return [
    // ── Meta ──────────────────────────────────────────────────────────
    'title'       => "TOP 10\nBossa Nova Akkorde",
    'subtitle'    => 'Essential Voicings for Guitar',
    'series'      => 'SBN Teaching Hub · Top 10',
    'description' => 'Die zehn wichtigsten Bossa-Nova-Akkorde für Gitarre — von Maj7 und m7 bis zu verminderten Klängen mit Erweiterungstönen. Kompakt erklärt, mit Rhythmuspattern und Song-Beispielen.',
    'intro_html'  => '
<p>Bossa Nova ist bekannt für seinen weichen, warmen Klang — und der kommt zu einem großen Teil aus den Akkorden. Die meisten Bossa-Nova-Harmonien sind Vierklänge: Septakkorde, die mit einem Erweiterungston wie der None (9) oder der Dreizehnten (13) zusätzliche Farbe bekommen.</p>
<p>In diesem Heft findest du die zehn häufigsten und schönsten Bossa-Nova-Akkordtypen für Gitarre. Jeder Akkord wird als Diagramm und in Notation gezeigt — mit einer kurzen Erklärung zur Funktion und zum typischen Einsatz in der Bossa Nova.</p>
<p>Die Akkorde sind root-unabhängig notiert: Das Shape bleibt gleich, egal ob du CMaj7, FMaj7 oder GbMaj7 spielst. Das System zeigt immer das C-Voicing — du verschiebst es einfach auf den gewünschten Grundton.</p>
    ',

    // ── Chord-Seiten ──────────────────────────────────────────────────
    'chords' => [
        'maj7-drop2-roota',     // #1  CMaj7(9)
        'm7-drop2-roota',       // #2  Cm7(9)
        'm6-drop3-roote',       // #3  Am6
        'dom7-drop3-roote-13',  // #4  G7(13)
        'm7b5-drop2-roota',     // #5  Bm7b5
        'dom7-shell-roota-b9',  // #6  E7(b9)
        'o7-drop2-roota',       // #7  C#°7
        'dom7-shell-roota-9',   // #8  C7(9)
        'o7-drop3-roote-b13',   // #9  G#°7(b13)
        'dom7-drop3-roote-b13', // #10 G7(b13)
    ],

    // ── Chord-Descriptions ────────────────────────────────────────────
    'chord_descriptions' => [

        'maj7-drop2-roota' => 'Der Maj7-Akkord mit None ist der Einstiegsklang der Bossa Nova. Das Drop-2-Voicing verteilt die Töne — Grundton, Terz, Septime und None — auf vier Saiten und klingt dabei offen und transparent. Die große Septime gibt dem Akkord seinen charakteristischen warmen, schwebenden Charakter.'
            . "\n\n" .
            'Dieser Akkord steht gleich zu Beginn von „The Girl from Ipanema" (FMaj7(9)) und ist damit wohl der berühmteste Bossa-Nova-Klang überhaupt. Tom Jobim nutzte ihn als tonales Zentrum in ruhigen, lichten Kompositionen — gespielt mit sanftem Daumenanschlag auf der Basssaite.',

        'm7-drop2-roota' => 'Der m7-Akkord mit None ist die moll-Variante des offenen Septakkordklangs. Die kleine Septime verleiht dem Voicing eine nachdenkliche, leicht melancholische Färbung — die hinzugefügte None macht ihn gleichzeitig weicher und luftiger als ein einfacher m7.'
            . "\n\n" .
            'In „Blue Bossa" von Kenny Dorham ist dieser Akkordtyp allgegenwärtig. Im Bossa-Nova-Kontext taucht er häufig als ii-Stufe in einer ii-V-I-Kadenz auf — João Gilberto spielte ähnliche Formen mit seinem charakteristischen Wechsel zwischen Bassnotenstimme und Akkordschlag.',

        'm6-drop3-roote' => 'Der m6-Akkord ersetzt die Septime durch die große Sexte — das ergibt einen dunkleren, erdverbundenen Klang. Gegenüber dem m7 wirkt er weniger schwebend und mehr geerdet, fast schon herbstlich. Das Drop-3-Voicing mit Root auf der E-Saite gibt dem Akkord eine volle, resonante Tiefe.'
            . "\n\n" .
            'In der Bossa Nova wird der m6 fast immer als Tonika-Akkord in Moll-Stücken verwendet. Jobims „Corcovado" ist ein klassisches Beispiel: Der Am6-Akkord verleiht dem Stück seine typische stille, nachdenkliche Stimmung — gespielt mit ruhigem, gleichmäßigem Anschlag.',

        'dom7-drop3-roote-13' => 'Der Dom7-Akkord mit Dreizehnter ist ein Dominant-Voicing mit eingebautem Spannungsmoment. Die große Dreizehnte liegt eine große Sexte über dem Grundton und sitzt als höchster Ton des Akkords — das ergibt einen hellen, leicht herbsüßen Klang über der charakteristischen Dominantspannung.'
            . "\n\n" .
            'In Kombination mit dem m7(9)-Akkord bildet dieser Dom7(13) die klassische Bossa-Nova-Kadenz. Das Intro von Jobims „Wave" ist das Schulbeispiel: G7(13) löst sich nach CMaj7 auf — ein Klangpaar, das den Sonido Bossa Nova auf den Punkt bringt.',

        'm7b5-drop2-roota' => 'Der halbverminderte Akkord — auch m7b5 oder ø-Akkord genannt — hat eine verminderte Quinte statt der reinen Quinte. Das gibt ihm seinen charakteristisch schwebenden, leicht unruhigen Klang: weder ganz dunkel wie ein verminderter, noch entspannt wie ein normaler m7.'
            . "\n\n" .
            'In der Bossa Nova und im Jazz ist der m7b5 die II-Stufe der wichtigsten Moll-Kadenz (ii°-V-I). In „Blue Bossa", „Corcovado" und „Garota de Ipanema" (Mittelteil) taucht er regelmäßig auf — oft gefolgt von einem Dom7(b9), mit dem er die Moll-Kadenz bildet.',

        'dom7-shell-roota-b9' => 'Das Dom7-Shell-Voicing mit kleiner None ist ein kompaktes, aber spannungsgeladenes Akkordshape. Die kleine None (b9) liegt nur einen Halbton über dem Grundton und erzeugt starke Reibung — genau das macht diesen Akkord zur idealen Auflösungsstufe in einer Moll-Kadenz.'
            . "\n\n" .
            'E7(b9) nach Am ist die Moll-Kadenz in ihrer reinsten Form. João Gilberto verwendete das Shell-Voicing oft als rhythmisch präzises, klanglich schlankes Dominantsignal — die fehlende Quinte macht es durchsichtig und lässt der Melodiestimme Raum.',

        'o7-drop2-roota' => 'Der verminderte Septakkord besteht ausschließlich aus kleinen Terzen — das macht ihn symmetrisch: Jeder Ton des Akkords kann als Grundton gehört werden. Diese Mehrdeutigkeit ist seine stärkste Eigenschaft: Er lässt sich enharmonisch umdeuten und in viele harmonische Richtungen auflösen.'
            . "\n\n" .
            'Tom Jobim nutzte den verminderten Akkord in zahlreichen Stücken als chromatischen Durchgangsakkord — in „Once I Loved" ist er besonders präsent. Im Bossa-Nova-Gitarrenspiel wird er oft mit einem Halbton-Schritt in den nächsten Akkord geführt, was Bewegung und Spannung erzeugt.',

        'dom7-shell-roota-9' => 'Der Dom7(9)-Akkord verbindet die typische Dominantspannung mit dem wärmenden Klang der großen None. Das Shell-Voicing macht ihn gitarristisch kompakt und klanglich durchsichtig — der Daumen bleibt frei für Basslinien.'
            . "\n\n" .
            'Dieser Akkordtyp ist in der Bossa Nova allgegenwärtig. In Bossa-Nova-Stücken findet er sich häufig als Dominante vor einem Maj7-Akkord — gespielt mit leichtem Daumenakzent auf der Bassnote und zartem Anschlag auf den Oberstimmen.',

        'o7-drop3-roote-b13' => 'Der verminderte Akkord mit kleiner Dreizehnter ist eine Erweiterung des reinen °7-Klangs. Die b13 fügt eine zusätzliche Farbe hinzu, die den Akkord etwas weicher und harmonisch reicher macht, ohne seine grundlegende Funktion als Spannungsakkord zu verändern.'
            . "\n\n" .
            'João Gilberto spielte dieses Voicing in „Corcovado" in einer charakteristischen Form: als G#°7(b13) vor einem C-Akkord, was eine besonders sanfte chromatische Auflösung ergibt. Die b13 verleiht dem Akkord einen leicht modalen Anklang und war ein typisches Stilmittel im authentischen Bossa-Nova-Gitarrenspiel der frühen 1960er Jahre.',

        'dom7-drop3-roote-b13' => 'Der Dom7-Akkord mit kleiner Dreizehnter ist die dunklere Variante des Dom7(13). Die b13 liegt einen Halbton tiefer als die große Dreizehnte und verleiht dem Akkord einen deutlich ernsteren, spannungsreicheren Charakter — ideal für Auflösungen nach Moll.'
            . "\n\n" .
            'In der Bossa Nova und im Jazz wird Dom7(b13) oft bewusst eingesetzt, um eine Auflösung nach Dur besonders expressiv zu gestalten: Der Halbtonschritt der b13 zur Quinte des Zielakkords erzeugt zusätzliche melodische Spannung. Dieser Klang findet sich häufig in den komplexeren Harmonisierungen von Jobim und in Arrangements von „How Insensitive".',
    ],

    // ── Rhythmus-Patterns ─────────────────────────────────────────────
    'rhythms' => [
        'gilberto-rhythm',
        'partido-alto',
    ],

    // ── Song-Beispiele ────────────────────────────────────────────────
    'songs' => [
        [
            'slug'       => 'girl-from-ipanema',
            'label'      => 'The Girl from Ipanema',
            'measures'   => [0, 7],
            'barsPerRow' => 4,
        ],
        [
            'slug'       => 'so-danco-samba',
            'label'      => 'So Danco Samba',
            'measures'   => [0, 7],
            'barsPerRow' => 4,
        ],
        [
            'slug'       => 'blue-bossa',
            'label'      => 'Blue Bossa',
            'measures'   => [0, 7],
            'barsPerRow' => 4,
        ],
        [
            'slug'       => 'corcovado',
            'label'      => 'Corcovado',
            'measures'   => [0, 7],
            'barsPerRow' => 4,
        ],
    ],
];
