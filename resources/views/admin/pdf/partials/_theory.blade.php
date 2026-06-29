{{-- Partial: theory page. Expects: $theory_title, $theory_html, $title --}}
<div class="pdf-page theory">
    <div class="theory__eyebrow">SBN Teaching Hub &middot; Chord Catalog</div>
    <div class="theory__title">{{ $theory_title ?? '' }}</div>
    <div class="theory__body">{!! $theory_html ?? '' !!}</div>
    <div class="theory__footer">
        <span>SBN Teaching Hub</span>
        <span>{{ $title ?? '' }}</span>
        <span>soulbossanova.com</span>
    </div>
</div>
