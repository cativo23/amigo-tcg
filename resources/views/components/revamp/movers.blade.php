@use('App\Modules\Catalog\Support\CardImage')
@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')

@props([
    'drops' => [],  // list<App\Modules\Collection\Services\Mover>
    'rises' => [],
    'username',
])

<section class="col-movers" aria-labelledby="col-mv-h">
    <h2 class="sr-only" id="col-mv-h">Biggest price moves over 7 days</h2>
    <div class="col-mv-grid">
        @foreach (['down' => ['Biggest drops', $drops], 'up' => ['Biggest rises', $rises]] as $direction => [$title, $movers])
            @continue(count($movers) === 0)
            <div class="col-mv-block">
                <h3 class="col-mv-t">{{ $title }} <span>· 7 days</span></h3>
                <ol class="col-mv-list">
                    @foreach ($movers as $mover)
                        @php
                            $card = $mover->card;
                            $thumb = CardImage::tile($card->official_image_url);
                            $sub = trim(($card->set->abbreviation ? $card->set->abbreviation.' ' : '').$card->local_id);
                            $abbreviation = Rarity::abbreviate($card->rarity);
                        @endphp
                        <li class="col-mv-row">
                            <a class="col-mv-link" href="{{ route('gallery.card', ['username' => $username, 'setTcgdexId' => $card->set->tcgdex_id, 'localId' => $card->local_id]) }}">
                                <span class="col-mv-pic" aria-hidden="true">@if ($thumb)<img src="{{ $thumb }}" alt="" width="46" height="64" loading="lazy" decoding="async">@endif</span>
                                <span class="col-mv-who">
                                    <span class="col-mv-nm">{{ $card->name }}</span>
                                    <span class="col-mv-sub col-mono">{{ $sub }}@if ($abbreviation !== '') · {{ $abbreviation }}@endif</span>
                                </span>
                                <span class="col-mv-move price-move price-move--{{ $direction }}">
                                    <b>{{ Money::glyph($mover->deltaMinor) }} {{ $mover->percentText() }}</b>
                                    <small>{{ Money::move($mover->deltaMinor, $mover->currency) }}</small>
                                </span>
                                <span class="col-mv-now col-mono">{{ Money::format($mover->currentMinor, $mover->currency) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endforeach
    </div>
</section>
