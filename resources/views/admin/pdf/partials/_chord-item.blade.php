{{--
    Partial: chord item page.
    Expects: $c (chord data array), $i (0-based index), $total (default 10), $title
--}}
@php
    $total = $total ?? 10;
    $num   = sprintf('%02d', $i + 1);
    $of    = $num . ' / ' . $total;
    $pills = [];
    if (!empty($c['intervals'])) {
        foreach (array_filter(array_map('trim', explode(',', $c['intervals']))) as $token) {
            $parts = explode(':', $token, 2);
            $pills[] = ['label' => trim($parts[0]), 'kind' => trim($parts[1] ?? 'ext')];
        }
    }
    $rgFingers = $c['_rhythm_pattern'] ?? '';
    $rgThumb   = $c['_rhythm_thumb']   ?? '';
    $rgLabels  = $c['_rhythm_labels']  ?? [];
    $beats     = max(strlen($rgFingers), strlen($rgThumb), count($rgLabels), 1);
@endphp
<div class="pdf-page item">
    <div class="item__eyebrow">
        <span>SBN Teaching Hub &middot; Chord Catalog</span>
        <span>{{ $of }}</span>
    </div>
    <div class="item__head">
        <span class="badge badge--md">{{ $num }}</span>
        <div class="item__title">{{ $c['title'] ?? '' }}</div>
    </div>
    <div class="item__layout">
        <div class="item__main">
            <div class="item__intro">
                <div class="item__diagram-feature">
                    <div class="item__diagram-chordname">
                        <span class="sbn-chord-symbol" data-chord="{{ $c['display_chord'] ?? '' }}"></span>
                    </div>
                    {!! $c['_diagram_svg'] ?? '' !!}
                </div>
                <p class="item__lede">{{ $c['lede'] ?? '' }}</p>
            </div>
            <div class="item__body">{!! $c['body'] ?? '' !!}</div>
        </div>

        <div class="item__margin">
            <div class="item__margin-identity">
                <div class="item__margin-chordname">
                    <span class="sbn-chord-symbol" data-chord="{{ $c['display_chord'] ?? '' }}"></span>
                </div>
                <span class="item__voicing-pill">{!! nl2br(e($c['voicing_pill'] ?? '')) !!}</span>
            </div>

            @if(!empty($pills))
            <div class="item__intervals">
                <div class="item__margin-label">Interval Structure</div>
                <div class="item__interval-row">
                    @foreach($pills as $pill)
                    <span class="item__interval-pill item__interval-pill--{{ $pill['kind'] }}">{{ $pill['label'] }}</span>
                    @endforeach
                </div>
            </div>
            @endif

            @if(!empty($c['listen']))
            <div>
                <div class="item__margin-label">Listen</div>
                <div class="item__margin-note">{!! $c['listen'] !!}</div>
            </div>
            @endif

            @if(!empty($c['try_this']))
            <div>
                <div class="item__margin-label">Try this</div>
                <div class="item__margin-note">{!! $c['try_this'] !!}</div>
            </div>
            @endif

            @if(!empty($c['related']))
            <div>
                <div class="item__margin-label">Related</div>
                <div class="item__margin-note">{!! $c['related'] !!}</div>
            </div>
            @endif
        </div>
    </div>

    <div class="item__section-label">Suggested Practice Pattern</div>
    <div class="pattern-steps">
        <div class="pattern-step">
            <span class="pattern-step__num">1</span>
            <div class="pattern-step__body">
                <div class="pattern-step__label">Tap your foot and clap the rhythm</div>
                <div class="pattern-step__meta">{{ $c['rhythm_meta'] ?? '' }}</div>
                <div class="rhythm-grid">
                    <div class="rg-row rg-row--labels">
                        <span class="rg-rowlabel"></span>
                        <div class="rg-cells">
                            @for($b = 0; $b < $beats; $b++)
                            <span class="rg-cell">{{ $rgLabels[$b] ?? ($b + 1) }}</span>
                            @endfor
                        </div>
                    </div>
                    @if($rgFingers !== '')
                    <div class="rg-row">
                        <span class="rg-rowlabel">Fingers</span>
                        <div class="rg-cells">
                            @for($b = 0; $b < $beats; $b++)
                            @php $ch = $rgFingers[$b] ?? '.'; @endphp
                            <span class="rg-cell {{ in_array($ch, ['x','X']) ? 'rg-hit' : 'rg-rest' }}"></span>
                            @endfor
                        </div>
                    </div>
                    @endif
                    @if($rgThumb !== '')
                    <div class="rg-row">
                        <span class="rg-rowlabel">Thumb</span>
                        <div class="rg-cells">
                            @for($b = 0; $b < $beats; $b++)
                            @php $ch = $rgThumb[$b] ?? '.'; @endphp
                            <span class="rg-cell rg-cell--thumb {{ in_array($ch, ['x','X']) ? 'rg-hit' : 'rg-rest' }}"></span>
                            @endfor
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="pattern-step">
            <span class="pattern-step__num">2</span>
            <div class="pattern-step__body">
                <div class="pattern-step__label">{!! $c['practice_label'] ?? '' !!}</div>
                <div class="pattern-step__meta">{{ $c['practice_meta'] ?? '' }}</div>
                <div class="pattern-tab">{!! $c['_tab_svg'] ?? '' !!}</div>
            </div>
        </div>
    </div>

    <div class="item__footer">
        <span>SBN Teaching Hub</span>
        <span>{{ $title ?? '' }}</span>
        <span>soulbossanova.com</span>
    </div>
</div>
