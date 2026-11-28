@props(['entry', 'username'])
@use('App\Modules\Catalog\Support\CardImage')
@use('App\Modules\Catalog\Support\CardVariants')
@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')

@php
    $card = $entry['card'];
    $href = $card && $card->set ? route('gallery.card', ['username' => $username, 'setTcgdexId' => $card->set->tcgdex_id, 'localId' => $card->local_id]) : null;
    $thumb = CardImage::tile($card?->official_image_url);
    // The key and wire:ignore keep a morph from rewriting a retried picture's src or putting back one the script removed.
    $rowKey = $entry['kind'] === 'move' ? 'move-'.$card->id : 'added-'.$entry['item']->id;
@endphp
<li class="act-row" wire:key="{{ $rowKey }}">
    <span class="act-thumb" wire:ignore>
        @if ($thumb)<img src="{{ $thumb }}" alt="" width="46" height="64" loading="lazy" decoding="async">@endif
        @if ($entry['kind'] === 'added')<span class="act-plus" aria-hidden="true">+</span>@endif
    </span>
    @if ($entry['kind'] === 'move')
        @php $delta = $entry['delta']; $up = $delta->isUp(); @endphp
        <div class="act-body">
            <p class="act-rt">@if ($href)<a href="{{ $href }}">{{ $card->name }} <span class="act-mono">#{{ $card->local_id }}</span></a>@else{{ $card->name }} <span class="act-mono">#{{ $card->local_id }}</span>@endif <span class="act-verb">{{ $up ? 'went up' : 'went down' }}</span></p>
            <p class="act-rs">{{ Rarity::label($card->rarity) ?: 'Card' }} · {{ $card->set?->name }} · {{ $delta->latest->sourceLabel() }}</p>
        </div>
        <p class="act-amt {{ $up ? 'act-amt--up' : 'act-amt--down' }}">{{ trim(Money::glyph($delta->deltaMinor).' '.Money::move($delta->deltaMinor, $delta->latest->currency)) }} <small>now {{ Money::format($delta->latest->market_minor, $delta->latest->currency) }}</small></p>
    @else
        @php $item = $entry['item']; $snapshot = $entry['snapshot']; @endphp
        <div class="act-body">
            <p class="act-rt"><span class="act-verb">Added</span> @if ($href)<a href="{{ $href }}">{{ $card->name }}</a>@else{{ $card?->name ?? 'a card' }}@endif @if ($item->quantity > 1)<span class="act-mono">×{{ $item->quantity }}</span>@endif</p>
            <p class="act-rs">{{ $card?->set?->name }} · {{ $item->grade_company ? $item->grade_company.' '.$item->grade_value : $item->condition }}@if ($item->variant) · {{ CardVariants::label($item->variant) }}@endif</p>
        </div>
        @if ($snapshot && $snapshot->market_minor !== null)<p class="act-amt">{{ Money::format($snapshot->market_minor, $snapshot->currency) }}</p>@endif
    @endif
</li>
