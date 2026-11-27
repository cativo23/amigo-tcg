<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\CollectionCover;
use App\Modules\Collection\Services\CollectionValueSeries;
use App\Modules\Collection\Services\CoverData;
use App\Modules\Collection\Services\PublicCollection;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A public collection of owned cards, one price row each: `from` is how many
 * days ago the card was first priced (null: never priced), and the price holds
 * from then on. Returns the user.
 *
 * @param  list<array{from: ?int, price?: int, currency?: string, later?: array{0: int, 1: int}}>  $cards  `later` is a second row: days ago and price
 */
function coverCollection(array $cards): User
{
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::firstOrCreate(['tcgdex_id' => 'cv01'], ['name' => 'Cover']);
    $now = now();

    foreach ($cards as $i => $spec) {
        $card = Card::create(['tcgdex_id' => 'cv01-'.$user->id.'-'.($i + 1), 'set_id' => $set->id, 'local_id' => (string) ($i + 1), 'name' => 'Cover '.($i + 1)]);
        DB::table('collection_items')->insert([
            'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id,
            'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $rows = $spec['from'] === null ? [] : [[$spec['from'], $spec['price'] ?? 100], ...(isset($spec['later']) ? [$spec['later']] : [])];

        foreach ($rows as [$daysAgo, $price]) {
            DB::table('card_price_snapshots')->insert([
                'card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal',
                'captured_on' => today()->subDays($daysAgo)->toDateString(), 'currency' => $spec['currency'] ?? 'USD',
                'market_minor' => $price, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    return $user;
}

function coverFor(User $user, ChartRange $range = ChartRange::OneMonth): CoverData
{
    $valuation = new Valuation(new CardPriceResolver);

    return (new CollectionCover(new CollectionValueSeries($valuation)))->for(PublicCollection::for($user), $range);
}

/** 20 cards: 8 priced from ten days ago (40 %), 11 more from five days ago (19 of 20 = 95 %), one never priced. */
function lateCoverage(): User
{
    return coverCollection([
        ...array_fill(0, 8, ['from' => 10, 'price' => 1000]),
        ...array_fill(0, 11, ['from' => 5, 'price' => 1000]),
        ['from' => null],
    ]);
}

test('it draws the value line only from the first day with 90 percent of cards priced', function () {
    $cover = coverFor(lateCoverage());

    expect($cover->linePricedSince?->toDateString())->toBe(today()->subDays(5)->toDateString())
        ->and($cover->series->first()['date']->toDateString())->toBe(today()->subDays(5)->toDateString())
        ->and($cover->series->last()['date']->toDateString())->toBe(today()->toDateString())
        ->and($cover->series->every(fn (array $p) => $p['minor'] === 19000))->toBeTrue();
});

test('a day at exactly 90 percent of the priced cards is drawn, one card fewer is not', function () {
    // 10 cards priced today. 9 of them from ten days ago (90 percent): the line starts there.
    $at = coverFor(coverCollection([...array_fill(0, 9, ['from' => 10]), ['from' => 3]]));
    // 8 of 10 (80 percent) from ten days ago: the line starts when the ninth card is priced.
    $below = coverFor(coverCollection([...array_fill(0, 8, ['from' => 10]), ['from' => 6], ['from' => 3]]));

    expect($at->series->first()['date']->toDateString())->toBe(today()->subDays(10)->toDateString())
        ->and($at->linePricedSince)->toBeNull()
        ->and($below->series->first()['date']->toDateString())->toBe(today()->subDays(6)->toDateString())
        ->and($below->linePricedSince?->toDateString())->toBe(today()->subDays(6)->toDateString());
});

test('it keeps the whole line and shows no since date when coverage is full from the start', function () {
    $cover = coverFor(coverCollection(array_fill(0, 10, ['from' => 10, 'price' => 500])));

    expect($cover->linePricedSince)->toBeNull()
        ->and($cover->series->first()['date']->toDateString())->toBe(today()->subDays(10)->toDateString());
});

test('a card that was never priced does not keep the line hidden', function () {
    // 10 priced cards from day one and 6 that never have a price: the line covers every card that has one.
    $cover = coverFor(coverCollection([...array_fill(0, 10, ['from' => 10]), ...array_fill(0, 6, ['from' => null])]));

    expect($cover->linePricedSince)->toBeNull()
        ->and($cover->series->first()['date']->toDateString())->toBe(today()->subDays(10)->toDateString())
        ->and($cover->series->every(fn (array $p) => $p['minor'] === 1000 && $p['priced'] === 10))->toBeTrue();
});

test('a card priced only in another currency does not count against the line', function () {
    $cover = coverFor(coverCollection([...array_fill(0, 10, ['from' => 10]), ...array_fill(0, 4, ['from' => 10, 'currency' => 'EUR'])]));

    expect($cover->currency)->toBe('USD')
        ->and($cover->totalMinor)->toBe(1000)
        ->and($cover->linePricedSince)->toBeNull();
});

test('it labels the move collection value', function () {
    expect(CollectionCover::MOVE_LABEL)->toBe('collection value');
});

test('it returns no cover values when no card is priced', function () {
    $cover = coverFor(coverCollection(array_fill(0, 3, ['from' => null])));

    expect($cover->currency)->toBeNull()
        ->and($cover->totalMinor)->toBeNull()
        ->and($cover->series)->toHaveCount(0)
        ->and($cover->rangeMoveMinor)->toBeNull()
        ->and($cover->linePricedSince)->toBeNull()
        ->and($cover->asOf)->toBeNull();
});

test('it returns no cover values for a collection with no cards', function () {
    $user = User::factory()->create();
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $cover = coverFor($user);

    expect($cover->currency)->toBeNull()
        ->and($cover->totalMinor)->toBeNull()
        ->and($cover->cards)->toHaveCount(0);
});

test('it uses the page total as todays point', function () {
    $cover = coverFor(coverCollection([['from' => 10, 'price' => 700], ['from' => 3, 'price' => 300]]));

    expect($cover->totalMinor)->toBe(1000)
        ->and($cover->series->last()['minor'])->toBe(1000);
});

test('it sets the move to the last drawn point minus the first', function () {
    // One card worth 700 ten days ago and 1000 from three days ago: the line goes from 700 to 1000.
    $cover = coverFor(coverCollection([['from' => 10, 'price' => 700, 'later' => [3, 1000]]]));

    expect($cover->series->first()['minor'])->toBe(700)
        ->and($cover->series->last()['minor'])->toBe(1000)
        ->and($cover->rangeMoveMinor)->toBe(300);
});

test('it omits the move when the line has fewer than two points', function () {
    // 8 of 10 cards are priced from ten days ago (80 percent) and the other two only today: 90 percent is first reached on the last day.
    $late = coverFor(coverCollection([...array_fill(0, 8, ['from' => 10]), ...array_fill(0, 2, ['from' => 0])]));
    $single = coverFor(coverCollection(array_fill(0, 5, ['from' => 0])));

    expect($late->series)->toHaveCount(1)
        ->and($late->linePricedSince?->toDateString())->toBe(today()->toDateString())
        ->and($late->rangeMoveMinor)->toBeNull()
        ->and($single->series)->toHaveCount(0)   // a history of one day draws no line at all
        ->and($single->rangeMoveMinor)->toBeNull();
});

test('it dates the cover by the newest marketplace price', function () {
    $cover = coverFor(coverCollection([['from' => 10], ['from' => 2]]));

    expect($cover->asOf?->toDateString())->toBe(today()->subDays(2)->toDateString());
});

test('it hands back the cards it priced, so the page does not load them twice', function () {
    $cover = coverFor(coverCollection(array_fill(0, 3, ['from' => 4])));

    expect($cover->cards)->toHaveCount(3)
        ->and($cover->cards->first()->relationLoaded('priceSnapshots'))->toBeTrue();
});

test('today carries the live priced count when a price arrives after the series was cached', function () {
    // Card A is priced from ten days ago; card B is owned and unpriced when the series is built and cached.
    $user = coverCollection([['from' => 10, 'price' => 700], ['from' => null]]);
    $before = coverFor($user);
    $cardB = Card::where('tcgdex_id', 'like', 'cv01-%-2')->sole();

    // A price for an owned print leaves the series cache key alone; only the cached deciding rows must be dropped to see it.
    DB::table('card_price_snapshots')->insert([
        'card_id' => $cardB->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal',
        'captured_on' => today()->toDateString(), 'currency' => 'USD', 'market_minor' => 300, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Cache::forget(DecidingPriceRows::cacheKey($before->cards->modelKeys()));

    $after = coverFor($user);

    expect($before->series->last()['priced'])->toBe(1)
        ->and($after->series->last()['minor'])->toBe(1000)
        ->and($after->series->last()['priced'])->toBe(2)
        // The guard measures the past against the live count: 1 of 2 priced is under the floor, so only today is drawn.
        ->and($after->series)->toHaveCount(1)
        ->and($after->linePricedSince?->toDateString())->toBe(today()->toDateString());
});

test('every point of the stored series carries how many cards were priced that day', function () {
    $user = lateCoverage();
    $series = (new CollectionValueSeries(new Valuation(new CardPriceResolver)))
        ->forPublicCollection(PublicCollection::for($user), ChartRange::OneMonth)['series'];

    expect($series->first()['priced'])->toBe(8)
        ->and($series->last()['priced'])->toBe(19);
});

it('carries every currency total of the page, the primary one first', function () {
    $user = coverCollection([
        ['from' => 3, 'price' => 500],
        ['from' => 3, 'price' => 700, 'currency' => 'EUR'],
    ]);

    $cover = coverFor($user);

    expect($cover->totals)->toBe(['USD' => 500, 'EUR' => 700])
        ->and($cover->currency)->toBe('USD');
});

it('carries the totals even when nothing is priced', function () {
    $cover = coverFor(coverCollection([['from' => null]]));

    expect($cover->totals)->toBe([])
        ->and($cover->currency)->toBeNull();
});
