<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionTotal;
use App\Modules\Collection\Services\PublicCollection;
use Illuminate\Support\Facades\Cache;
use Tests\Support\PriceRowReads;

beforeEach(function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    Cache::flush();
    $this->user = User::factory()->create(['username' => 'carlos']);
    $this->collection = Collection::factory()->for($this->user)->create(['is_public' => true, 'slug' => 'main']);
    $this->set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $this->own = function (string $localId, int $minor, int $quantity = 1, string $currency = 'USD'): CollectionItem {
        $card = Card::create(['tcgdex_id' => "me05-{$localId}", 'set_id' => $this->set->id, 'local_id' => $localId, 'name' => "Card {$localId}"]);
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => $currency === 'USD' ? 'tcgplayer' : 'cardmarket', 'variant' => 'normal', 'captured_on' => today(), 'currency' => $currency, 'market_minor' => $minor]);

        return CollectionItem::create(['collection_id' => $this->collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => $quantity]);
    };
    $this->total = fn (): array => app(CollectionTotal::class)->for(PublicCollection::for($this->user));
});

test('totals the public collection by currency, the way the cover and the series do', function () {
    ($this->own)('001', 5000);
    ($this->own)('002', 1000, 2);
    ($this->own)('003', 700, 1, 'EUR');

    expect(($this->total)())->toBe(['USD' => 7000, 'EUR' => 700]);
});

test('a collection with nothing public totals nothing', function () {
    ($this->own)('001', 5000);
    $this->collection->update(['is_public' => false]);

    expect(($this->total)())->toBe([]);
});

test('the first read loads the price rows once, and a warm read loads none', function () {
    ($this->own)('001', 5000);
    ($this->own)('002', 1000);

    $cold = PriceRowReads::during(fn () => ($this->total)());
    // The price rows' own cache would answer a rebuild without a query; only the stored total can.
    Cache::forget(DecidingPriceRows::cacheKey(Card::pluck('id')->all()));
    $warm = PriceRowReads::during(fn () => ($this->total)());

    expect($cold->deciding)->toHaveCount(1)
        ->and($cold->stray)->toBe([])
        ->and($warm->deciding)->toBe([])
        ->and($warm->stray)->toBe([])
        ->and(($this->total)())->toBe(['USD' => 6000]);
});

test('a price written after the total was cached is seen on the next read', function () {
    $item = ($this->own)('001', 5000);
    expect(($this->total)())->toBe(['USD' => 5000]);

    CardPriceSnapshot::where('card_id', $item->card_id)->update(['market_minor' => 6500]);

    expect(($this->total)())->toBe(['USD' => 6500]);
});

test('a copy added, a quantity changed and a copy removed each change the cached total', function () {
    $first = ($this->own)('001', 5000);
    expect(($this->total)())->toBe(['USD' => 5000]);

    $second = ($this->own)('002', 1000);
    expect(($this->total)())->toBe(['USD' => 6000]);

    $second->update(['quantity' => 3]);
    expect(($this->total)())->toBe(['USD' => 8000]);

    $first->delete();
    expect(($this->total)())->toBe(['USD' => 3000]);
});

test('a collection turned private, then public again, follows it', function () {
    ($this->own)('001', 5000);
    expect(($this->total)())->toBe(['USD' => 5000]);

    $this->collection->update(['is_public' => false]);
    expect(($this->total)())->toBe([]);

    $this->collection->update(['is_public' => true]);
    expect(($this->total)())->toBe(['USD' => 5000]);
});

test('refreshing stores the total, so the next read is warm', function () {
    ($this->own)('001', 5000);

    $refreshed = app(CollectionTotal::class)->refresh(PublicCollection::for($this->user));
    Cache::forget(DecidingPriceRows::cacheKey(Card::pluck('id')->all()));
    $warm = PriceRowReads::during(fn () => expect(($this->total)())->toBe(['USD' => 5000]));

    expect($refreshed)->toBe(['USD' => 5000])
        ->and($warm->deciding)->toBe([]);
});

test('sums over more cards than one price-row batch, and keeps USD first', function () {
    ($this->own)('000', 100, 1, 'EUR');
    for ($i = 1; $i < CollectionTotal::CHUNK + 5; $i++) {
        ($this->own)(sprintf('%03d', $i), 200);
    }

    expect(array_keys(($this->total)()))->toBe(['USD', 'EUR'])
        ->and(($this->total)()['USD'])->toBe(200 * (CollectionTotal::CHUNK + 4));
});
