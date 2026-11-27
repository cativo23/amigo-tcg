@use('App\Modules\Catalog\Support\ChartRange')
@use('App\Modules\Collection\Services\CollectionCover')
@use('App\Support\Money')

@props([
    'cover',  // App\Modules\Collection\Services\CoverData with a currency
    'chart',  // ?App\Modules\Collection\Support\ValueChart of the cover's series
])

@php
    $currency = $cover->currency;
    $move = $cover->rangeMoveMinor;
    $direction = $move === null ? null : ($move > 0 ? 'up' : ($move < 0 ? 'down' : 'flat'));
    $range = ChartRange::OneMonth->label();
@endphp

<div class="col-cover" role="group" aria-label="Collection value">
    <div class="col-cover-main">
        <p class="col-figure">{{ Money::format($cover->totalMinor, $currency) }}</p>
        @if ($cover->asOf)
            <p class="col-asof col-mono">on {{ $cover->asOf->format('j M') }}</p>
        @endif
        @if ($direction)
            <p class="col-move price-move price-move--{{ $direction }}">{{ trim(Money::glyph($move).' '.Money::move($move, $currency)) }} <span class="col-chunk">· {{ CollectionCover::MOVE_LABEL }} · {{ $range }}</span></p>
        @endif
        @if ($cover->linePricedSince)
            <p class="col-since">The line starts on {{ $cover->linePricedSince->format('j M') }}, the first day most of these cards had a price.</p>
        @endif
    </div>
    @if ($chart)
        <div class="col-line-box">
            <svg class="col-spark" viewBox="0 0 {{ App\Modules\Collection\Support\ValueChart::WIDTH }} {{ App\Modules\Collection\Support\ValueChart::HEIGHT }}" preserveAspectRatio="none" role="img" aria-label="Collection value over {{ $range }}, from {{ Money::format($cover->series->first()['minor'], $currency) }} to {{ Money::format($cover->series->last()['minor'], $currency) }}">
                <path class="col-area{{ $chart->down ? ' chart--down' : '' }}" d="{{ $chart->area }}"/>
                <path class="col-line{{ $chart->down ? ' chart--down' : '' }}" d="{{ $chart->line }}"/>
            </svg>
        </div>
    @endif
</div>
