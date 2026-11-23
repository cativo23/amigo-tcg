<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use Illuminate\Support\Facades\Schema;

/** @return array{name: string, type: string, nullable: bool}|null */
function unconfirmedColumn(string $table, string $column): ?array
{
    foreach (Schema::getColumns($table) as $definition) {
        if ($definition['name'] === $column) {
            return ['name' => $definition['name'], 'type' => $definition['type_name'], 'nullable' => $definition['nullable']];
        }
    }

    return null;
}

function unconfirmedColumnsSet(): Set
{
    return Set::create(['tcgdex_id' => 'me06', 'name' => 'Delta Reign']);
}

function unconfirmedColumnsCard(Set $set, array $attributes = []): Card
{
    return Card::create([
        'tcgdex_id' => 'me06-100',
        'set_id' => $set->id,
        'local_id' => '100',
        'name' => 'Mega Rayquaza Cap',
        'variants' => ['normal' => true],
        'raw' => ['id' => 'me06-100'],
        ...$attributes,
    ]);
}

test('sets keep their reported variant counts in a nullable jsonb column', function () {
    expect(unconfirmedColumn('sets', 'variant_counts'))->toBe(['name' => 'variant_counts', 'type' => 'jsonb', 'nullable' => true]);
});

test('cards keep their deduced variant list in a nullable jsonb column', function () {
    expect(unconfirmedColumn('cards', 'inferred_variants'))->toBe(['name' => 'inferred_variants', 'type' => 'jsonb', 'nullable' => true]);
});

test('a set without recorded counts reads them as null', function () {
    expect(unconfirmedColumnsSet()->fresh()->variant_counts)->toBeNull();
});

test('a set round-trips its variant counts as an array', function () {
    $set = unconfirmedColumnsSet();
    $set->update(['variant_counts' => ['normal' => 0, 'holo' => 0, 'reverse' => 0, 'firstEd' => 0]]);

    // jsonb does not keep an object's key order, so the counts are compared by content.
    expect($set->fresh()->variant_counts)->toEqual(['normal' => 0, 'holo' => 0, 'reverse' => 0, 'firstEd' => 0]);
});

test('a card without a deduced list reads it as null', function () {
    expect(unconfirmedColumnsCard(unconfirmedColumnsSet())->fresh()->inferred_variants)->toBeNull();
});

test('a card round-trips its deduced list as an ordered array', function () {
    $card = unconfirmedColumnsCard(unconfirmedColumnsSet(), ['inferred_variants' => ['normal', 'reverse-holofoil']]);

    expect($card->fresh()->inferred_variants)->toBe(['normal', 'reverse-holofoil']);
});

test('rewriting a card from the catalog source leaves its deduced list untouched', function () {
    $set = unconfirmedColumnsSet();
    unconfirmedColumnsCard($set, ['inferred_variants' => ['holofoil']]);

    // The same attributes the nightly sync rewrites (CatalogSyncService::syncCard).
    Card::updateOrCreate(
        ['tcgdex_id' => 'me06-100'],
        [
            'set_id' => $set->id,
            'local_id' => '100',
            'name' => 'Mega Rayquaza Cap',
            'rarity' => 'Uncommon',
            'variants' => ['normal' => true, 'reverse' => false],
            'official_image_url' => null,
            'raw' => ['id' => 'me06-100', 'rewritten' => true],
            'synced_at' => now(),
        ],
    );

    $card = Card::where('tcgdex_id', 'me06-100')->firstOrFail();

    expect($card->raw)->toHaveKey('rewritten')
        ->and($card->inferred_variants)->toBe(['holofoil']);
});
