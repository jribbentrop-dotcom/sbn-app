<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'SBN Chord PDF' }}</title>

    {{-- Google Fonts (same as admin layout) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=Crimson+Text:ital,wght@0,400;0,600;1,400&family=Fraunces:ital,opsz,wght@0,9..144,300..900;1,9..144,300..900&display=swap" rel="stylesheet">

    <style>
        /* ── Design Tokens (from sbn-design-system.css) ── */
        :root {
            --clr-bg:          #f8f9fb;
            --clr-white:       #ffffff;
            --clr-surface:     #ffffff;
            --clr-surface-2:   #f7fafc;
            --clr-text:        #1a1a2e;
            --clr-text-dim:    #5a5a5a;
            --clr-text-muted:  #8896a4;
            --clr-accent:      #f39c12;
            --clr-accent-dim:  #e67e22;
            --clr-red:         #e74c3c;
            --clr-border:      #e2e8f0;
            --clr-success:     #10b981;
            --clr-primary:     #3b82f6;
            --clr-seventh:     #8b5cf6;

            /* Guide-tone colors used by sbnRenderDiagramSVG */
            --clr-root:        #f39c12;
            --clr-third:       #3b82f6;
            --clr-fifth:       #10b981;

            --font-body:       'DM Sans', system-ui, sans-serif;
            --font-chord:      'Crimson Text', Georgia, serif;
            --font-display:    'Fraunces', Georgia, serif;
        }

        /* ── Page setup ── */
        @page {
            size: A4 portrait;
            margin: 15mm 18mm 20mm 18mm;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-body);
            font-size: 10pt;
            color: var(--clr-text);
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ── Page breaks ── */
        .pdf-page {
            page-break-after: always;
            break-after: page;
            position: relative;
            min-height: 100vh; /* only for browser preview */
        }
        .pdf-page:last-child {
            page-break-after: avoid;
            break-after: avoid;
        }

        /* ── Cover page ── */
        .pdf-cover {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 40pt 30pt;
        }
        .pdf-cover__logo {
            width: 56pt;
            height: 56pt;
            margin-bottom: 24pt;
        }
        .pdf-cover__series {
            font-family: var(--font-body);
            font-size: 9pt;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--clr-accent);
            margin-bottom: 10pt;
        }
        .pdf-cover__title {
            font-family: var(--font-display);
            font-size: 30pt;
            font-weight: 700;
            line-height: 1.15;
            color: var(--clr-text);
            margin-bottom: 14pt;
        }
        .pdf-cover__subtitle {
            font-family: var(--font-chord);
            font-style: italic;
            font-size: 13pt;
            color: var(--clr-text-dim);
            margin-bottom: 36pt;
        }
        .pdf-cover__divider {
            width: 60pt;
            height: 3pt;
            background: linear-gradient(90deg, #f39c12, #e74c3c);
            border-radius: 2pt;
            margin-bottom: 36pt;
        }
        .pdf-cover__desc {
            max-width: 340pt;
            font-size: 10pt;
            line-height: 1.65;
            color: var(--clr-text-dim);
        }
        .pdf-cover__footer {
            position: absolute;
            bottom: 12pt;
            left: 0; right: 0;
            text-align: center;
            font-size: 8pt;
            color: var(--clr-text-muted);
            letter-spacing: 0.06em;
        }

        /* ── Intro page ── */
        .pdf-intro {
            padding: 8pt 0;
        }
        .pdf-intro h2 {
            font-family: var(--font-display);
            font-size: 18pt;
            font-weight: 700;
            color: var(--clr-text);
            margin-bottom: 10pt;
        }
        .pdf-intro p {
            font-size: 10pt;
            line-height: 1.7;
            color: var(--clr-text-dim);
            margin-bottom: 8pt;
        }

        /* ── Page header (repeated on chord/song pages) ── */
        .pdf-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1.5pt solid var(--clr-border);
            padding-bottom: 6pt;
            margin-bottom: 16pt;
        }
        .pdf-page-header__brand {
            font-size: 7.5pt;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--clr-text-muted);
        }
        .pdf-page-header__title {
            font-family: var(--font-chord);
            font-style: italic;
            font-size: 10pt;
            color: var(--clr-text-dim);
        }

        /* ── Page footer ── */
        .pdf-page-footer {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 6pt;
            border-top: 0.75pt solid var(--clr-border);
            font-size: 7.5pt;
            color: var(--clr-text-muted);
        }

        /* ── Chord page ── */
        .pdf-chord-page { padding: 0; }

        /* Zone A: chord hero — number + name + tags */
        .pdf-chord-hero {
            padding: 20pt 24pt 14pt;
            border-bottom: 0.5pt solid #e5e5e5;
        }
        .pdf-chord-number-row {
            display: flex;
            align-items: baseline;
            gap: 10pt;
            margin-bottom: 8pt;
        }
        .pdf-chord-number {
            font-size: 36pt;
            font-weight: 500;
            line-height: 1;
            color: #d0d0d0;
            flex-shrink: 0;
        }
        .pdf-chord-name {
            font-family: var(--font-display);
            font-size: 22pt;
            font-weight: 500;
            color: #1a1a1a;
            line-height: 1.05;
        }
        .pdf-chord-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 4pt;
        }
        .pdf-chord-meta span {
            display: inline-block;
            background: #fff;
            border: 0.5pt solid #ccc;
            border-radius: 20pt;
            padding: 3pt 10pt;
            font-size: 8pt;
            color: #666;
        }

        /* Zone B: diagram + description side by side */
        .pdf-chord-body {
            display: grid;
            grid-template-columns: 200pt 1fr;
            align-items: start;
        }
        .pdf-chord-body--no-desc {
            grid-template-columns: 200pt 1fr;
        }
        .pdf-diagram-col {
            padding: 20pt 16pt 16pt;
            border-right: 0.5pt solid #e5e5e5;
        }
        .pdf-diagram-wrap {
            background: transparent;
            padding: 0;
        }
        .pdf-diagram-wrap svg {
            width: 100%;
            height: auto;
            display: block;
        }

        /* Interval pills below diagram */
        .pdf-interval-row {
            display: flex;
            flex-wrap: wrap;
            gap: 4pt;
            margin-top: 10pt;
        }
        .pdf-interval-pill {
            font-size: 8pt;
            font-weight: 500;
            padding: 3pt 8pt;
            border-radius: 5pt;
            border: 0.5pt solid #e5e5e5;
            background: #f5f5f5;
            color: #555;
        }
        /* Per-interval colors (exact hex, no CSS variables — for PDF accuracy) */
        .pdf-interval-pill.iv-R   { background: #FAECE7; color: #993C1D; border-color: #F0997B; }
        .pdf-interval-pill.iv-5   { background: #E6F1FB; color: #185FA5; border-color: #85B7EB; }
        .pdf-interval-pill.iv-7   { background: #EEEDFE; color: #534AB7; border-color: #AFA9EC; }
        .pdf-interval-pill.iv-3   { background: #E1F5EE; color: #0F6E56; border-color: #5DCAA5; }
        .pdf-interval-pill.iv-b7  { background: #EEEDFE; color: #534AB7; border-color: #AFA9EC; }
        .pdf-interval-pill.iv-b3  { background: #FAEEDA; color: #854F0B; border-color: #EF9F27; }
        .pdf-interval-pill.iv-b5  { background: #FCEBEB; color: #A32D2D; border-color: #F09595; }
        .pdf-interval-pill.iv-b9  { background: #FCEBEB; color: #A32D2D; border-color: #F09595; }
        .pdf-interval-pill.iv-13  { background: #E1F5EE; color: #0F6E56; border-color: #5DCAA5; }
        .pdf-interval-pill.iv-b13 { background: #FAEEDA; color: #854F0B; border-color: #EF9F27; }
        .pdf-interval-pill.iv-bb7 { background: #F1EFE8; color: #5F5E5A; border-color: #B4B2A9; }

        /* Notes row */
        .pdf-notes-row {
            display: flex;
            flex-wrap: wrap;
            gap: 4pt;
            margin-top: 6pt;
        }
        .pdf-note-pill {
            font-size: 8pt;
            padding: 3pt 8pt;
            border-radius: 5pt;
            color: #666;
            border: 0.5pt solid #e5e5e5;
            background: #fff;
        }

        /* Description column (right) */
        .pdf-info-col {
            padding: 20pt 20pt;
        }
        .pdf-info-label {
            font-size: 7.5pt;
            font-weight: 500;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #aaa;
            margin-bottom: 10pt;
        }
        .pdf-chord-desc {
            font-size: 10pt;
            line-height: 1.65;
            color: var(--clr-text-dim);
        }
        .pdf-chord-desc p { margin-bottom: 10pt; }
        .pdf-chord-desc p:last-child { margin-bottom: 0; }

        /* Zone C: rhythm section */
        .pdf-rhythm-block {
            padding: 16pt 24pt 20pt;
            border-top: 0.5pt solid #e5e5e5;
        }
        .pdf-rhythm-block-label {
            font-size: 7.5pt;
            font-weight: 500;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #aaa;
            margin-bottom: 12pt;
        }
        .pdf-rhythm-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16pt;
        }
        .pdf-rhythm-item {
            border: 0.5pt solid #e5e5e5;
            border-radius: 8pt;
            padding: 12pt 14pt;
        }
        .pdf-rhythm-name {
            font-size: 10pt;
            font-weight: 500;
            color: var(--clr-text);
            margin-bottom: 2pt;
        }
        .pdf-rhythm-meta {
            font-size: 8pt;
            color: #aaa;
            margin-bottom: 10pt;
        }
        .pdf-rhythm-svg-wrap {
            width: 100%;
        }
        .pdf-rhythm-svg-wrap svg {
            width: 100%;
            height: auto;
            display: block;
        }
        .pdf-rhythm-desc {
            font-size: 8pt;
            line-height: 1.55;
            color: #666;
            margin-top: 8pt;
        }

        /* ── Standalone rhythm page ── */
        .pdf-rhythms-page { padding: 0; }
        .pdf-rhythms-page h2 {
            font-family: var(--font-display);
            font-size: 18pt;
            font-weight: 700;
            color: var(--clr-text);
            margin-bottom: 16pt;
        }

        /* ── Song example page ── */
        .pdf-song-page { padding: 0; }
        .pdf-song-title {
            font-family: var(--font-display);
            font-size: 16pt;
            font-weight: 700;
            color: var(--clr-text);
            margin-bottom: 14pt;
        }
        .pdf-tab-row {
            margin-bottom: 12pt;
            overflow: visible;
        }
        .pdf-tab-row svg {
            display: block;
            width: 100%;
            height: auto;
            overflow: visible;
            font-family: 'Crimson Text', Georgia, serif;
        }
    </style>
</head>
<body>

{{-- ================================================================
     COVER PAGE
     ================================================================ --}}
<div class="pdf-page pdf-cover">
    {{-- SBN logo mark --}}
    <svg class="pdf-cover__logo" viewBox="0 0 56 56" fill="none">
        <defs>
            <linearGradient id="lg" x1="0" y1="0" x2="56" y2="56" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="#f39c12"/>
                <stop offset="100%" stop-color="#e74c3c"/>
            </linearGradient>
        </defs>
        <rect width="56" height="56" rx="12" fill="url(#lg)"/>
        <path d="M12 40V16l16 12-16 12z" fill="#fff" opacity="0.9"/>
        <path d="M28 40V16l16 12-16 12z" fill="#fff" opacity="0.6"/>
    </svg>

    <div class="pdf-cover__series">{{ $series ?? 'SBN Teaching Hub · Top 10' }}</div>
    <h1 class="pdf-cover__title">{!! nl2br(e($title)) !!}</h1>
    @if(!empty($subtitle))
        <p class="pdf-cover__subtitle">{{ $subtitle }}</p>
    @endif
    <div class="pdf-cover__divider"></div>
    @if(!empty($coverDescription))
        <p class="pdf-cover__desc">{{ $coverDescription }}</p>
    @endif

    <div class="pdf-cover__footer">sbn-teaching-hub.com &nbsp;·&nbsp; {{ date('Y') }}</div>
</div>

{{-- ================================================================
     INTRO PAGE (optional)
     ================================================================ --}}
@if(!empty($introHtml))
<div class="pdf-page pdf-intro">
    <div class="pdf-page-header">
        <span class="pdf-page-header__brand">SBN Teaching Hub</span>
        <span class="pdf-page-header__title">{{ $title }}</span>
    </div>

    <h2>Über dieses PDF</h2>
    {!! $introHtml !!}

    <div class="pdf-page-footer">
        <span>SBN Teaching Hub</span>
        <span>{{ $title }}</span>
        <span>sbn-teaching-hub.com</span>
    </div>
</div>
@endif

{{-- ================================================================
     CHORD PAGES  (one per chord)
     ================================================================ --}}
@foreach($chords as $i => $chord)
<div class="pdf-page pdf-chord-page">
    <div class="pdf-page-header">
        <span class="pdf-page-header__brand">SBN Teaching Hub</span>
        <span class="pdf-page-header__title">{{ $title }}</span>
    </div>

    {{-- Zone A: Chord number + name + tags --}}
    <div class="pdf-chord-hero">
        <div class="pdf-chord-number-row">
            <span class="pdf-chord-number">{{ $i + 1 }}</span>
            <span class="pdf-chord-name">{{ $chord['name'] }}</span>
        </div>
        <div class="pdf-chord-meta">
            @if(!empty($chord['voicing_category'])) <span>{{ $chord['category_label'] ?? $chord['voicing_category'] }}</span> @endif
            @if(!empty($chord['root_string'])) <span>{{ $chord['root_string_label'] ?? $chord['root_string'] }}</span> @endif
            @if(!empty($chord['inversion'])) <span>{{ $chord['inversion_label'] ?? $chord['inversion'] }}</span> @endif
            @if(!empty($chord['shape_family'])) <span>{{ $chord['shape_family'] }}</span> @endif
        </div>
    </div>

    {{-- Zone B: Diagram + Info --}}
    @php
        $hasDesc = !empty($chord['description']) && strlen(trim($chord['description'])) > 10;
        $configDesc = $config['chord_descriptions'][$chord['slug']] ?? null;
        $displayDesc = $configDesc ?? ($hasDesc ? $chord['description'] : null);
    @endphp
    <div class="pdf-chord-body{{ $displayDesc ? '' : ' pdf-chord-body--no-desc' }}">
        <div class="pdf-diagram-col">
            <div class="pdf-diagram-wrap">
                {!! $chord['svg'] !!}
            </div>

            {{-- Interval pills --}}
            @if(!empty($chord['interval_labels']))
                @php
                    $intervals = array_filter(array_map('trim', explode(',', $chord['interval_labels'])));
                    $ivClassMap = [
                        'R'   => 'iv-R',   '1'  => 'iv-R',
                        '3'   => 'iv-3',   'M3' => 'iv-3',
                        'b3'  => 'iv-b3',  'm3' => 'iv-b3',
                        '5'   => 'iv-5',
                        'b5'  => 'iv-b5',  '#5' => 'iv-b5',
                        '7'   => 'iv-7',   'M7' => 'iv-7',
                        'b7'  => 'iv-b7',
                        'bb7' => 'iv-bb7',
                        'b9'  => 'iv-b9',
                        '13'  => 'iv-13',
                        'b13' => 'iv-b13',
                    ];
                @endphp
                <div class="pdf-interval-row">
                    @foreach($intervals as $iv)
                        @if($iv === 'x') @continue @endif
                        @php $cls = $ivClassMap[$iv] ?? ''; @endphp
                        <span class="pdf-interval-pill {{ $cls }}">{{ $iv }}</span>
                    @endforeach
                </div>
            @endif

            {{-- Note names --}}
            @if(!empty($chord['notes']))
                @php
                    $noteList = array_filter(array_map('trim', explode(',', $chord['notes'])), fn($n) => $n !== 'x');
                @endphp
                <div class="pdf-notes-row">
                    @foreach($noteList as $note)
                        <span class="pdf-note-pill">{{ $note }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        @if($displayDesc)
        <div class="pdf-info-col">
            <div class="pdf-info-label">Beschreibung</div>
            <div class="pdf-chord-desc">
                @foreach(explode("\n\n", trim($displayDesc)) as $para)
                    @if(trim($para))<p>{{ trim($para) }}</p>@endif
                @endforeach
            </div>
        </div>
        @endif
    </div>

    {{-- Zone C: Rhythmus --}}
    @if(!empty($rhythms))
    <div class="pdf-rhythm-block">
        <div class="pdf-rhythm-block-label">Rhythmus-Pattern</div>
        <div class="pdf-rhythm-grid">
            @foreach($rhythms as $rhythm)
            <div class="pdf-rhythm-item">
                <div class="pdf-rhythm-name">{{ $rhythm['name'] }}</div>
                @if(!empty($rhythm['meta']))
                <div class="pdf-rhythm-meta">{{ $rhythm['meta'] }}</div>
                @endif
                <div class="pdf-rhythm-svg-wrap">{!! $rhythm['svg'] !!}</div>
                @if(!empty($rhythm['description']))
                <div class="pdf-rhythm-desc">{{ Str::limit($rhythm['description'], 180) }}</div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <div class="pdf-page-footer">
        <span>SBN Teaching Hub</span>
        <span>{{ $chord['name'] }}</span>
        <span>sbn-teaching-hub.com</span>
    </div>
</div>
@endforeach

{{-- ================================================================
     SONG EXAMPLE PAGES (one page per song, if songs defined in config)
     ================================================================ --}}
@if(!empty($songExamples))
@foreach($songExamples as $song)
<div class="pdf-page pdf-song-page">
    <div class="pdf-page-header">
        <span class="pdf-page-header__brand">SBN Teaching Hub</span>
        <span class="pdf-page-header__title">{{ $title }} &nbsp;·&nbsp; Song-Beispiel</span>
    </div>

    <div class="pdf-song-title">{{ $song['title'] }}</div>

    @foreach($song['tabSvgs'] as $tabSvg)
    <div class="pdf-tab-row">{!! $tabSvg !!}</div>
    @endforeach

    <div class="pdf-page-footer">
        <span>SBN Teaching Hub</span>
        <span>{{ $song['title'] }}</span>
        <span>sbn-teaching-hub.com</span>
    </div>
</div>
@endforeach
@endif

{{-- ================================================================
     STANDALONE RHYTHMS PAGE (optional, only if rhythms defined in config)
     ================================================================ --}}
@if(!empty($rhythms))
<div class="pdf-page pdf-rhythms-page">
    <div class="pdf-page-header">
        <span class="pdf-page-header__brand">SBN Teaching Hub</span>
        <span class="pdf-page-header__title">{{ $title }} &nbsp;·&nbsp; Rhythmus-Pattern</span>
    </div>

    <h2>Rhythmus-Pattern</h2>

    @foreach($rhythms as $rhythm)
    <div class="pdf-rhythm-item">
        <div class="pdf-rhythm-name">{{ $rhythm['name'] }}</div>
        <div style="font-size:7.5pt;color:var(--clr-text-muted);margin-bottom:6pt">{{ $rhythm['meta'] }}</div>
        <div class="pdf-rhythm-svg">{!! $rhythm['svg'] !!}</div>
        @if(!empty($rhythm['description']))
        <div class="pdf-rhythm-desc">{{ $rhythm['description'] }}</div>
        @endif
    </div>
    @endforeach

    <div class="pdf-page-footer">
        <span>SBN Teaching Hub</span>
        <span>Rhythmus-Pattern</span>
        <span>sbn-teaching-hub.com</span>
    </div>
</div>
@endif

</body>
</html>
