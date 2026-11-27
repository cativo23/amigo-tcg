@use('App\Modules\Catalog\Support\CardImage')
@use('App\Support\Money')

@props([
    'drop' => null,  // ?App\Modules\Collection\Services\Mover, the biggest 7-day drop
    'rise' => null,  // ?Mover, the biggest 7-day rise
    'username',
])

<section class="col-strip" aria-labelledby="col-strip-h">
    <h2 class="col-strip-t" id="col-strip-h">This week</h2>
    <ul class="col-strip-row" role="list">
        @foreach (array_filter(['down' => $drop, 'up' => $rise]) as $direction => $mover)
            @php $card = $mover->card; $thumb = CardImage::tile($card->official_image_url); @endphp
            <li>
                <a class="col-chip" href="{{ route('gallery.card', ['username' => $username, 'setTcgdexId' => $card->set->tcgdex_id, 'localId' => $card->local_id]) }}">
                    <span class="col-chip-pic" aria-hidden="true">@if ($thumb)<img src="{{ $thumb }}" alt="" width="46" height="64" loading="lazy" decoding="async">@endif</span>
                    <span class="col-chip-pct price-move price-move--{{ $direction }}">{{ Money::glyph($mover->deltaMinor) }} {{ $mover->percentText() }}</span>
                    <span class="col-chip-nm">{{ $card->name }}</span>
                </a>
            </li>
        @endforeach
    </ul>
    <a class="col-strip-all" href="{{ route('gallery.activity', ['username' => $username]) }}">See all movers</a>
</section>
