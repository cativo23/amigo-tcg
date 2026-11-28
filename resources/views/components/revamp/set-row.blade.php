@use('App\Modules\Catalog\Support\Completion')

@props([
    'set',        // Set with owned_card_count loaded
    'username',
    'totals',     // array<string currency, int minor>
    'share',      // ?int percent of the collection value, primary currency only
    'total',      // int cards in the set
])

{{-- One set of the value tail: a compact row with the value on the right (phones keep it there). --}}
@php $owned = (int) $set->owned_card_count; @endphp
<li class="sts-trow">
    <a href="{{ route('gallery.show', ['username' => $username, 'setTcgdexId' => $set->tcgdex_id]) }}">
        <span class="sts-trow-name">{{ $set->name }}</span>
        <span class="sts-trow-meta">{{ $owned }} / {{ $total }} · {{ Completion::percent($owned, $total) }}%@if ($share !== null) · <span class="sts-share">{{ $share }}% of value</span>@endif</span>
        <span class="sts-trow-value"><x-value-totals :totals="$totals" /></span>
    </a>
</li>
