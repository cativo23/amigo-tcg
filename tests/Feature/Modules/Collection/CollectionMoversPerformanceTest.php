<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Services\WeekBaselines;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\CollectionMovers;
use App\Modules\Collection\Services\PublicCollection;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Support\Facades\DB;

/*
 * Timing harness for the ranked movers. Not part of the default run: it seeds
 * 600 cards with 90 days of prices each. Run it with
 *   vendor/bin/sail artisan test --group=benchmark
 * It says how long rank() takes and whether it adds queries; the production
 * timing of the whole page is a separate, manual step.
 */
/** A tripwire for the dev container, not the production budget: rank() measures about 115 ms there, of which weekDeltaFor is about 55 ms (a model hydrated per card). Production is timed by hand. */
const MOVERS_TRIPWIRE_MS = 250.0;

test('it adds no queries and stays under the tripwire for 600 cards with 90 price days', function () {
    $user = User::factory()->create(['username' => 'bench']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'bn01', 'name' => 'Bench']);
    $now = now();
    $cards = PublicCollection::MAX_CARDS;

    DB::table('cards')->insert(collect(range(1, $cards))->map(fn (int $i) => [
        'tcgdex_id' => "bn01-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i,
        'name' => "Card {$i}", 'created_at' => $now, 'updated_at' => $now,
    ])->all());

    $ids = Card::orderBy('id')->pluck('tcgdex_id', 'id');

    DB::table('collection_items')->insert($ids->map(fn (string $tcgdexId, int $id) => [
        'collection_id' => $collection->id, 'card_id' => $id, 'card_tcgdex_id' => $tcgdexId, 'variant' => 'normal',
        'condition' => 'NM', 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
    ])->values()->all());

    foreach ($ids->keys()->chunk(50) as $chunk) {
        DB::table('card_price_snapshots')->insert($chunk->flatMap(fn (int $id) => collect(range(0, 89))->map(fn (int $d) => [
            'card_id' => $id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal',
            'captured_on' => today()->subDays($d)->toDateString(), 'currency' => 'USD',
            // A wobble per card and day, so some cards rise and some fall.
            'market_minor' => 1000 + (($id * 37 + $d * 11) % 400),
            'created_at' => $now, 'updated_at' => $now,
        ]))->all());
    }

    app(WeekBaselines::class)->refreshAll();

    $loaded = app(DecidingPriceRows::class)->loadOnto(
        PublicCollection::for($user)->cardsInIdOrder()->take($cards)->get(),
    );
    // The page's own headline pass runs first on the same resolver, so the per-card indexes are already built; only what the movers add is timed.
    $resolver = new CardPriceResolver;
    $valuation = new Valuation($resolver);
    $loaded->each(fn (Card $card) => $valuation->headlineSnapshot($card));
    $movers = new CollectionMovers($valuation, $resolver);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $before = count(DB::getQueryLog());

    $started = hrtime(true);
    $result = $movers->rank($loaded, 'USD');
    $milliseconds = (hrtime(true) - $started) / 1e6;

    $after = count(DB::getQueryLog());
    fwrite(STDERR, sprintf("rank(): %.1f ms for %d cards, %d queries added\n", $milliseconds, $loaded->count(), $after - $before));

    expect($loaded)->toHaveCount($cards)
        ->and($result['drops'])->not->toBe([])
        ->and($result['rises'])->not->toBe([])
        ->and($after)->toBe($before)
        ->and($milliseconds)->toBeLessThan(MOVERS_TRIPWIRE_MS);
})->group('benchmark');
