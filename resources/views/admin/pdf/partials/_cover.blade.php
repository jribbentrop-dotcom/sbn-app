{{-- Partial: cover page. Expects: $title, $eyebrow, $subtitle, $hook, $facts --}}
<div class="pdf-page cover">
    <img class="cover__logo" src="{{ asset('images/soulbossanova.jpg') }}" alt="Soul Bossa Nova">
    <div class="cover__eyebrow">{{ $eyebrow ?? 'SBN Teaching Hub' }}</div>
    <div class="cover__title">{!! nl2br(e($title ?? '')) !!}</div>
    <div class="cover__subtitle">{{ $subtitle ?? '' }}</div>
    <p class="cover__hook">{{ $hook ?? '' }}</p>
    @if(!empty($facts))
    <div class="cover__facts">
        @foreach(array_filter(explode("\n", $facts)) as $fact)
        <div class="cover__fact">{{ trim($fact) }}</div>
        @endforeach
    </div>
    @endif
    <div class="cover__footer">soulbossanova.com</div>
</div>
