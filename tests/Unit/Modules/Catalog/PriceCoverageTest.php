<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\PriceCoverage;

function pcCard(string $setId, string $local, ?array $inferred): Card
{
    $set = Set::firstOrCreate(['tcgdex_id' => $setId], ['name' => strtoupper($setId)]);

    return Card::create([
        'tcgdex_id' => "{$setId}-{$local}", 'set_id' => $set->id, 'local_id' => $local, 'name' => 'Card '.$local,
        'inferred_variants' => $inferred,
    ]);
}

test('unconfirmedSets names each set with cards that have a deduced list, with the card count', function () {
    pcCard('me06', '001', ['normal', 'reverse-holofoil']);
    pcCard('me06', '002', ['holofoil']);
    pcCard('me06', '003', null);
    pcCard('me05', '001', null);

    expect(app(PriceCoverage::class)->unconfirmedSets())->toBe(['me06' => 2]);
});

test('unconfirmedSets is empty when no card has a deduced list', function () {
    pcCard('me05', '001', null);

    expect(app(PriceCoverage::class)->unconfirmedSets())->toBe([]);
});

test('unconfirmedSets lists the sets in id order', function () {
    pcCard('me06', '001', ['holofoil']);
    pcCard('me02', '001', ['normal']);

    expect(array_keys(app(PriceCoverage::class)->unconfirmedSets()))->toBe(['me02', 'me06']);
});
