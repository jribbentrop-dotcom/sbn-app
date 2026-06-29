{{--
    Partial: song example page.
    Expects: $s (song data array), $title
--}}
@php
    $chips = [];
    if (!empty($s['legend'])) {
        foreach (array_filter(array_map('trim', explode("\n", $s['legend']))) as $line) {
            $spacePos = strpos($line, ' ');
            if ($spacePos !== false) {
                $chips[] = ['num' => substr($line, 0, $spacePos), 'chord' => trim(substr($line, $spacePos + 1))];
            } else {
                $chips[] = ['num' => $line, 'chord' => ''];
            }
        }
    }
@endphp
<div class="pdf-page example">
    <div class="example__eyebrow">{{ $s['eyebrow'] ?? '' }}</div>
    <div class="example__title">{{ $s['title'] ?? '' }}</div>
    <div class="example__sub">{!! $s['sub'] ?? '' !!}</div>

    @if(!empty($chips))
    <div class="example__legend">
        <span class="example__legend-title">Chords used</span>
        @foreach($chips as $chip)
        <span class="chip">
            <span class="badge badge--sm">{{ $chip['num'] }}</span>
            @if($chip['chord'])
            <span class="sbn-chord-symbol" data-chord="{{ $chip['chord'] }}"></span>
            @endif
        </span>
        @endforeach
    </div>
    @endif

    @foreach($s['_tab_svgs'] ?? [] as $rowSvg)
    <div class="example__notation-wrap">{!! $rowSvg !!}</div>
    @endforeach

    @if(!empty($s['note']))
    <div class="example__note">{!! $s['note'] !!}</div>
    @endif

    <div class="example__footer">
        <span>SBN Teaching Hub</span>
        <span>{{ $title ?? '' }} &middot; Examples</span>
        <span>soulbossanova.com</span>
    </div>
</div>
