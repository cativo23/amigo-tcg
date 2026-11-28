<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\ActivityFeedGrouper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

const GROUPER_THRESHOLD = 100;

/** An "added" feed entry the way Activity builds it; $minor null is a copy with no price of its own. */
function addedEntry(int $id, ?int $minor, string $at, string $set = 'Pitch Black', string $currency = 'USD', string $name = 'Card'): array
{
    $card = (new Card)->forceFill(['id' => $id, 'name' => "{$name} {$id}"]);
    $card->setRelation('set', (new Set)->forceFill(['name' => $set]));
    $item = (new CollectionItem)->forceFill(['id' => $id, 'card_id' => $id, 'quantity' => 1]);

    return [
        'kind' => 'added',
        'card' => $card,
        'item' => $item,
        'snapshot' => $minor === null ? null : (new CardPriceSnapshot)->forceFill(['market_minor' => $minor, 'currency' => $currency]),
        'at' => CarbonImmutable::parse($at, 'UTC'),
    ];
}

function movedEntry(int $id, string $at): array
{
    return ['kind' => 'move', 'card' => (new Card)->forceFill(['id' => $id, 'name' => "Mover {$id}"]), 'delta' => null, 'at' => CarbonImmutable::parse($at, 'UTC')];
}

/** @param  list<array<string, mixed>>  $entries */
function grouped(array $entries, int $threshold = GROUPER_THRESHOLD): Collection
{
    return ActivityFeedGrouper::group(new Collection($entries), $threshold);
}

test('it collapses the additions priced under the threshold into one group per day', function () {
    $entries = [
        addedEntry(1, 533, '2026-11-23 10:00:05'),
        addedEntry(2, 450, '2026-11-23 10:00:04'),
        ...array_map(fn (int $i) => addedEntry(10 + $i, 30, '2026-11-23 10:00:03'), range(1, 28)),
    ];

    $rows = grouped($entries);

    expect($rows->pluck('type')->all())->toBe(['entry', 'entry', 'group'])
        ->and($rows[2]['count'])->toBe(28)
        ->and($rows[2]['entries'])->toHaveCount(28)
        ->and($rows[2]['maxMinor'])->toBe(30)
        ->and($rows[2]['setName'])->toBe('Pitch Black')
        ->and($rows[2]['day']->toDateString())->toBe('2026-11-23');
});

test('it lists the notable additions individually, in the order they came', function () {
    $rows = grouped([addedEntry(1, 533, '2026-11-23 10:00:00'), addedEntry(2, 450, '2026-11-23 10:00:00')]);

    expect($rows->pluck('type')->all())->toBe(['entry', 'entry'])
        ->and($rows->map(fn (array $r) => $r['card']->id)->all())->toBe([1, 2]);
});

test('an entry row is the original entry, untouched, with its type', function () {
    $entry = addedEntry(1, 533, '2026-11-23 10:00:00');

    $row = grouped([$entry])->first();

    expect($row)->toBe([...$entry, 'type' => 'entry']);
});

test('a price equal to the threshold is notable, one cent under is small', function () {
    $rows = grouped([
        addedEntry(1, 100, '2026-11-23 10:00:00'),
        addedEntry(2, 99, '2026-11-23 10:00:00'),
        addedEntry(3, 99, '2026-11-23 10:00:00'),
    ]);

    expect($rows->pluck('type')->all())->toBe(['entry', 'group'])
        ->and($rows[1]['count'])->toBe(2);
});

test('a single small addition stays a row of its own, not a group of one', function () {
    $rows = grouped([addedEntry(1, 30, '2026-11-23 10:00:00'), addedEntry(2, 533, '2026-11-23 10:00:00')]);

    expect($rows->pluck('type')->all())->toBe(['entry', 'entry']);
});

test('an addition with no price of its own is never called small', function () {
    $rows = grouped([addedEntry(1, null, '2026-11-23 10:00:00'), addedEntry(2, null, '2026-11-23 10:00:00')]);

    expect($rows->pluck('type')->all())->toBe(['entry', 'entry']);
});

test('price moves stay individual however small the card is', function () {
    $rows = grouped([movedEntry(1, '2026-11-23 00:00:00'), movedEntry(2, '2026-11-23 00:00:00')]);

    expect($rows->pluck('type')->all())->toBe(['entry', 'entry'])
        ->and($rows->pluck('kind')->all())->toBe(['move', 'move']);
});

test('small additions are grouped per day, never across days', function () {
    $rows = grouped([
        addedEntry(1, 30, '2026-11-23 10:00:00'),
        addedEntry(2, 30, '2026-11-23 09:00:00'),
        addedEntry(3, 30, '2026-11-22 10:00:00'),
        addedEntry(4, 30, '2026-11-22 09:00:00'),
        addedEntry(5, 30, '2026-11-22 08:00:00'),
    ]);

    expect($rows->pluck('type')->all())->toBe(['group', 'group'])
        ->and($rows->pluck('count')->all())->toBe([2, 3])
        ->and($rows->map(fn (array $r) => $r['day']->toDateString())->all())->toBe(['2026-11-23', '2026-11-22']);
});

test('a day is a UTC calendar day, as the feed cuts it', function () {
    $rows = grouped([
        addedEntry(1, 30, '2026-11-23 00:01:00'),
        addedEntry(2, 30, '2026-11-22 23:59:00'),
    ]);

    expect($rows->pluck('type')->all())->toBe(['entry', 'entry']);
});

test('small additions in different currencies are never grouped together', function () {
    $rows = grouped([
        addedEntry(1, 50, '2026-11-23 10:00:00', currency: 'USD'),
        addedEntry(2, 50, '2026-11-23 10:00:00', currency: 'EUR'),
        addedEntry(3, 40, '2026-11-23 10:00:00', currency: 'USD'),
    ]);

    expect($rows->pluck('type')->all())->toBe(['group', 'entry'])
        ->and($rows[0]['currency'])->toBe('USD')
        ->and($rows[0]['maxMinor'])->toBe(50)
        ->and($rows[0]['count'])->toBe(2);
});

test('the group sits where its newest member sat, and the rows around it keep their order', function () {
    $rows = grouped([
        addedEntry(1, 533, '2026-11-23 12:00:00'),
        addedEntry(2, 30, '2026-11-23 11:00:00'),
        addedEntry(3, 450, '2026-11-23 10:30:00'),
        addedEntry(4, 30, '2026-11-23 10:00:00'),
    ]);

    expect($rows->pluck('type')->all())->toBe(['entry', 'group', 'entry'])
        ->and($rows[0]['card']->id)->toBe(1)
        ->and($rows[2]['card']->id)->toBe(3);
});

test('a group is named after its set when one set has most of it, else it says several sets', function () {
    $one = grouped([addedEntry(1, 30, '2026-11-23 10:00:00', 'Pitch Black'), addedEntry(2, 30, '2026-11-23 10:00:00', 'Pitch Black')]);
    $majority = grouped([
        addedEntry(1, 30, '2026-11-23 10:00:00', 'Pitch Black'),
        addedEntry(2, 30, '2026-11-23 10:00:00', 'Pitch Black'),
        addedEntry(3, 30, '2026-11-23 10:00:00', 'Stellar Crown'),
    ]);
    $split = grouped([addedEntry(1, 30, '2026-11-23 10:00:00', 'Pitch Black'), addedEntry(2, 30, '2026-11-23 10:00:00', 'Stellar Crown')]);

    expect($one[0]['setName'])->toBe('Pitch Black')
        ->and($majority[0]['setName'])->toBe('Pitch Black')
        ->and($split[0]['setName'])->toBe('Several sets');
});

test('an empty feed is an empty list', function () {
    expect(grouped([]))->toHaveCount(0);
});

test('it takes the time of a copy as the model carries it', function () {
    $entries = [addedEntry(1, 30, '2026-11-23 10:00:00'), addedEntry(2, 30, '2026-11-23 09:00:00')];
    // A model's created_at is an Illuminate Carbon, not an immutable one.
    $entries = array_map(fn (array $e) => [...$e, 'at' => Carbon::parse($e['at']->toDateTimeString(), 'UTC')], $entries);

    $row = grouped($entries)->sole();

    expect($row['type'])->toBe('group')
        ->and($row['day']->toDateString())->toBe('2026-11-23')
        ->and($row['day'])->toBeInstanceOf(CarbonImmutable::class);
});
