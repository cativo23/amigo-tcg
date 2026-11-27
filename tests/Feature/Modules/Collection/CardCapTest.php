<?php

declare(strict_types=1);

use App\Livewire\Gallery\Activity;
use App\Livewire\Gallery\Index;
use App\Livewire\Gallery\Sets;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\CollectionValueSeries;
use App\Modules\Collection\Services\PublicCollection;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PriceRowReads;

/** A public collection that owns one more distinct, priced card than the cap allows. */
function collectionOverTheCap(): User
{
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $now = now();
    $count = PublicCollection::MAX_CARDS + 1;

    DB::table('cards')->insert(collect(range(1, $count))->map(fn (int $i) => [
        'tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i,
        'name' => sprintf('Card %04d', $i), 'created_at' => $now, 'updated_at' => $now,
    ])->all());
    DB::table('collection_items')->insert(Card::orderBy('id')->pluck('tcgdex_id', 'id')->map(fn (string $tcgdexId, int $id) => [
        'collection_id' => $collection->id, 'card_id' => $id, 'card_tcgdex_id' => $tcgdexId, 'variant' => 'normal',
        'condition' => 'NM', 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
    ])->values()->all());
    DB::table('card_price_snapshots')->insert(Card::orderBy('id')->pluck('id')->map(fn (int $id) => [
        'card_id' => $id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal',
        'captured_on' => today()->toDateString(), 'currency' => 'USD', 'market_minor' => 1000 + $id,
        'created_at' => $now, 'updated_at' => $now,
    ])->all());

    return $user;
}

/** @return list<int> the first MAX_CARDS card ids in id order: the population every page must share */
function cappedIds(User $user): array
{
    return PublicCollection::for($user)->cardsInIdOrder()->take(PublicCollection::MAX_CARDS)->get()->modelKeys();
}

test('the cap is 600 cards', function () {
    expect(PublicCollection::MAX_CARDS)->toBe(600);
});

test('the collection page loads the capped cards for its figures', function () {
    $user = collectionOverTheCap();

    $reads = PriceRowReads::during(fn () => Livewire::test(Index::class, ['username' => 'carlos'])->viewData('collectionTotal'));

    expect($reads->decidingIds(0))->toHaveCount(600)
        ->and($reads->decidingIds(0))->toEqualCanonicalizing(cappedIds($user));
});

test('the sets list loads the capped cards for its values', function () {
    $user = collectionOverTheCap();

    $reads = PriceRowReads::during(fn () => Livewire::test(Sets::class, ['username' => 'carlos'])->viewData('setValues'));

    expect($reads->decidingIds(0))->toHaveCount(600)
        ->and($reads->decidingIds(0))->toEqualCanonicalizing(cappedIds($user));
});

test('the value series loads the capped cards for its total and its line', function () {
    $user = collectionOverTheCap();

    $result = (new CollectionValueSeries(new Valuation(new CardPriceResolver)))
        ->forPublicCollection(PublicCollection::for($user), ChartRange::fromInput('1m'));

    expect($result['cards'])->toHaveCount(600)
        ->and($result['cards']->modelKeys())->toEqualCanonicalizing(cappedIds($user));
});

test('the activity page headline uses the same capped cards as the collection page', function () {
    $user = collectionOverTheCap();

    $reads = PriceRowReads::during(fn () => Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('feed'));

    expect($reads->decidingIds(0))->toEqualCanonicalizing(cappedIds($user));
});

test('the warm job covers the capped cards, not a smaller population', function () {
    $user = collectionOverTheCap();
    $service = new CollectionValueSeries(new Valuation(new CardPriceResolver));

    $warmed = $service->warm(PublicCollection::for($user), [ChartRange::fromInput('1m')]);

    // warm() fills the keys the page reads: a page read right after must hit them.
    $result = $service->forPublicCollection(PublicCollection::for($user), ChartRange::fromInput('1m'));

    expect($warmed['computed'])->toBeGreaterThan(0)
        ->and($result['cached'])->toBeTrue();
});
