<?php

declare(strict_types=1);

use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Services\CollectionMovers;
use App\Modules\Collection\Services\Valuation;
use Tests\Support\MoverFixtures;
use Tests\Support\PriceRowReads;

function movers(): CollectionMovers
{
    return new CollectionMovers(new Valuation(new CardPriceResolver), new CardPriceResolver);
}

test('it ranks drops by percent among cards above the floor', function () {
    $f = new MoverFixtures;
    $f->card(900, 1000, name: 'Small drop');   // -10%
    $f->card(5000, 10000, name: 'Big drop');   // -50%
    $f->card(840, 960, name: 'Middle drop');   // -12.5%

    $result = movers()->rank($f->load(), 'USD');

    expect(array_map(fn ($m) => $m->card->name, $result['drops']))->toBe(['Big drop', 'Middle drop', 'Small drop'])
        ->and($result['rises'])->toBe([]);
});

test('it ranks rises by percent, biggest first', function () {
    $f = new MoverFixtures;
    $f->card(1100, 1000, name: 'Up 10');
    $f->card(2000, 1000, name: 'Up 100');

    $result = movers()->rank($f->load(), 'USD');

    expect(array_map(fn ($m) => $m->card->name, $result['rises']))->toBe(['Up 100', 'Up 10'])
        ->and($result['drops'])->toBe([]);
});

test('it ignores a card whose baseline is below the price floor', function () {
    $f = new MoverFixtures;
    // Both moves are well past the dollar-move floor, so only the price floor can leave them out.
    $f->card(20, 80, name: 'Cheap');                                                    // -75% of 80 cents
    $f->card(20, CollectionMovers::MIN_PRICE_MINOR - 1, name: 'Just under');
    $f->card(500, 1000, name: 'Listed');

    $result = movers()->rank($f->load(), 'USD');

    expect(array_map(fn ($m) => $m->card->name, $result['drops']))->toBe(['Listed']);
});

test('it lists a card whose baseline is exactly the price floor', function () {
    $f = new MoverFixtures;
    $f->card(50, CollectionMovers::MIN_PRICE_MINOR, name: 'On the floor');

    expect(array_map(fn ($m) => $m->card->name, movers()->rank($f->load(), 'USD')['drops']))->toBe(['On the floor']);
});

test('it ignores a move smaller than the minimum dollar move', function () {
    $f = new MoverFixtures;
    $f->card(10000 - (CollectionMovers::MIN_MOVE_MINOR - 1), 10000, name: 'Tiny');   // a hair under the floor
    $f->card(10000 - CollectionMovers::MIN_MOVE_MINOR, 10000, name: 'On the move floor');

    $result = movers()->rank($f->load(), 'USD');

    expect(array_map(fn ($m) => $m->card->name, $result['drops']))->toBe(['On the move floor']);
});

test('it leaves out cards priced in another currency and counts them in omitted', function () {
    $f = new MoverFixtures;
    $f->card(500, 1000, name: 'Dollars');
    $f->card(400, 1000, currency: 'EUR', name: 'Euros');
    $f->card(300, 1000, currency: 'EUR', name: 'More euros');

    $result = movers()->rank($f->load(), 'USD');

    expect(array_map(fn ($m) => $m->card->name, $result['drops']))->toBe(['Dollars'])
        ->and($result['omitted'])->toBe(2)
        ->and($result['currency'])->toBe('USD');
});

test('it lists neither block when every card is flat', function () {
    $f = new MoverFixtures;
    $f->card(1000, 1000);
    $f->card(2500, 2500);

    $result = movers()->rank($f->load(), 'USD');

    expect($result['drops'])->toBe([])
        ->and($result['rises'])->toBe([])
        ->and($result['omitted'])->toBe(0);
});

test('it returns fewer than four rows when fewer qualify', function () {
    $f = new MoverFixtures;
    $f->card(500, 1000);
    $f->card(1500, 1000);

    $result = movers()->rank($f->load(), 'USD');

    expect($result['drops'])->toHaveCount(1)
        ->and($result['rises'])->toHaveCount(1);
});

test('it keeps only the limit of each block', function () {
    $f = new MoverFixtures;
    foreach (range(1, CollectionMovers::LIMIT + 3) as $i) {
        $f->card(1000 - $i * 50, 1000);
    }

    expect(movers()->rank($f->load(), 'USD')['drops'])->toHaveCount(CollectionMovers::LIMIT);
});

test('it skips a card with no comparable price a week old', function () {
    $f = new MoverFixtures;
    $f->card(500, null, name: 'New today');
    $f->card(500, 1000, name: 'Has a baseline');

    expect(array_map(fn ($m) => $m->card->name, movers()->rank($f->load(), 'USD')['drops']))->toBe(['Has a baseline']);
});

test('it computes percent as delta over previous', function () {
    $f = new MoverFixtures;
    $f->card(852, 960);   // -$1.08 on $9.60

    $mover = movers()->rank($f->load(), 'USD')['drops'][0];

    expect($mover->deltaMinor)->toBe(-108)
        ->and($mover->previousMinor)->toBe(960)
        ->and($mover->currentMinor)->toBe(852)
        ->and($mover->percent)->toBe(-11.25)
        ->and($mover->currency)->toBe('USD');
});

test('it uses the single copy headline price, not price times quantity', function () {
    $f = new MoverFixtures;
    $f->card(500, 1000, quantity: 3);

    $mover = movers()->rank($f->load(), 'USD')['drops'][0];

    expect($mover->currentMinor)->toBe(500)
        ->and($mover->deltaMinor)->toBe(-500);
});

test('it breaks equal percents by the bigger dollar move, then by card id', function () {
    $f = new MoverFixtures;
    $small = $f->card(500, 1000, name: 'Small $');     // -50%, -$5
    $first = $f->card(5000, 10000, name: 'Big $ first'); // -50%, -$50
    $second = $f->card(5000, 10000, name: 'Big $ second');

    $names = array_map(fn ($m) => $m->card->name, movers()->rank($f->load(), 'USD')['drops']);

    expect($names)->toBe(['Big $ first', 'Big $ second', 'Small $']);
});

test('it breaks equal rise percents the same way', function () {
    $f = new MoverFixtures;
    $f->card(1500, 1000, name: 'Small $');       // +50%, +$5
    $f->card(15000, 10000, name: 'Big $ first');   // +50%, +$50
    $f->card(15000, 10000, name: 'Big $ second');

    $names = array_map(fn ($m) => $m->card->name, movers()->rank($f->load(), 'USD')['rises']);

    expect($names)->toBe(['Big $ first', 'Big $ second', 'Small $']);
});

test('it returns nothing for a collection with no primary currency', function () {
    $f = new MoverFixtures;
    $f->card(null, null);

    $result = movers()->rank($f->load(), null);

    expect($result)->toBe(['drops' => [], 'rises' => [], 'omitted' => 0, 'currency' => null]);
});

test('it reads no price rows beyond the ones already loaded', function () {
    $f = new MoverFixtures;
    foreach (range(1, 12) as $i) {
        $f->card(1000 + $i * 30, 1000);
        $f->card(1000 - $i * 30, 1000);
    }
    $cards = $f->load();

    $reads = PriceRowReads::during(fn () => movers()->rank($cards, 'USD'));

    expect($reads->deciding)->toBe([])
        ->and($reads->series)->toBe([])
        ->and($reads->stray)->toBe([]);
});
