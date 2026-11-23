<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\CatalogSyncService;
use App\Modules\Catalog\Support\CardVariants;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\DataCollection;

/** @return array<string, mixed> */
function uvsPlaceholderRaw(): array
{
    return ['id' => 'me06-001', 'variants_detailed' => [['type' => 'normal', 'size' => 'standard', 'variantId' => 'generated']]];
}

/** @param  list<string>  $tcgplayerLabels  variants tcgdex prices on TCGplayer tonight */
function uvsDetail(array $tcgplayerLabels = []): CardDetailData
{
    return new CardDetailData(
        tcgdexId: 'me06-001', setTcgdexId: 'me06', localId: '001', name: 'Mega Rayquaza Cap',
        rarity: null, variants: ['normal' => true, 'holo' => false, 'reverse' => false],
        officialImageUrl: 'https://assets.tcgdex.net/en/me/me06/001/high.webp',
        prices: new DataCollection(PriceEntryData::class, array_map(
            fn (string $label) => new PriceEntryData(source: 'tcgplayer', variant: $label, currency: 'USD', marketMinor: 100, lowMinor: null, trendMinor: null, sourceUpdatedAt: null, raw: []),
            $tcgplayerLabels,
        )),
        raw: uvsPlaceholderRaw(),
    );
}

/** @param  array{normal: int, holo: int, reverse: int, firstEd: int}  $counts */
function uvsStoredCard(array $counts = ['normal' => 0, 'holo' => 0, 'reverse' => 0, 'firstEd' => 0]): Card
{
    $set = Set::create(['tcgdex_id' => 'me06', 'name' => 'Delta Reign', 'variant_counts' => $counts]);

    return Card::create([
        'tcgdex_id' => 'me06-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Mega Rayquaza Cap',
        'variants' => ['normal' => true, 'holo' => false, 'reverse' => false], 'raw' => uvsPlaceholderRaw(),
    ]);
}

/** @param  list<string>  $tcgplayerLabels */
function uvsSync(array $tcgplayerLabels = []): Card
{
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me06-001')->andReturn(uvsDetail($tcgplayerLabels));
    $provider->shouldReceive('findSet')->with('me06')->andReturn(new SetSummaryData(
        tcgdexId: 'me06', name: 'Delta Reign', series: null, releasedOn: null, cardCount: null, logoUrl: null,
        variantCounts: ['normal' => 0, 'holo' => 0, 'reverse' => 0, 'firstEd' => 0],
    ));

    return (new CatalogSyncService($provider))->syncCard('me06-001');
}

function uvsRow(Card $card, string $variant, int $daysAgo = 0, string $source = 'tcgplayer', string $origin = 'tcgcsv'): void
{
    CardPriceSnapshot::create([
        'card_id' => $card->id, 'source' => $source, 'origin' => $origin, 'variant' => $variant, 'currency' => 'USD',
        'captured_on' => CarbonImmutable::today()->subDays($daysAgo)->toDateString(), 'market_minor' => 100,
    ]);
}

test('a placeholder card in a zero-count set stores normal and reverse-holofoil when TCGplayer prices both', function () {
    uvsStoredCard();

    expect(uvsSync(['normal', 'reverse-holofoil'])->inferred_variants)->toBe(['normal', 'reverse-holofoil']);
});

test('a placeholder card with only a holofoil TCGplayer price stores holofoil alone', function () {
    uvsStoredCard();

    expect(uvsSync(['holofoil'])->inferred_variants)->toBe(['holofoil']);
});

test('a placeholder card with holofoil and reverse-holofoil prices stores both, in display order', function () {
    uvsStoredCard();

    expect(uvsSync(['reverse-holofoil', 'holofoil'])->inferred_variants)->toBe(['holofoil', 'reverse-holofoil']);
});

test('a placeholder card with no TCGplayer rows stores null and keeps today\'s list', function () {
    uvsStoredCard();

    $card = uvsSync();

    expect($card->inferred_variants)->toBeNull()
        ->and(CardVariants::available($card->variants, $card->raw, $card->inferred_variants))
        ->toBe(CardVariants::available($card->variants, $card->raw))
        ->toBe(['normal']);
});

test('a stored tcgcsv TCGplayer row counts even when tcgdex sends no price tonight', function () {
    $card = uvsStoredCard();
    uvsRow($card, 'reverse-holofoil', 1, origin: 'tcgcsv');
    uvsRow($card, 'normal', 1, origin: 'tcgcsv');

    expect(uvsSync()->inferred_variants)->toBe(['normal', 'reverse-holofoil']);
});

test('rows from another source or a hand-entered origin do not count', function (string $source, string $origin) {
    $card = uvsStoredCard();
    uvsRow($card, 'reverse-holofoil', 0, source: $source, origin: $origin);

    expect(uvsSync()->inferred_variants)->toBeNull();
})->with([
    'cardmarket' => ['cardmarket', 'tcgdex'],
    'hand-entered tcgplayer' => ['tcgplayer', 'hand'],
]);

test('a TCGplayer row from exactly the stale window ago counts and one a day older does not', function () {
    $card = uvsStoredCard();
    uvsRow($card, 'holofoil', CardPriceResolver::STALE_AFTER_DAYS);
    uvsRow($card, 'reverse-holofoil', CardPriceResolver::STALE_AFTER_DAYS + 1);

    expect(uvsSync()->inferred_variants)->toBe(['holofoil']);
});

test('the card row is written only when the list changes', function () {
    uvsStoredCard();
    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        if (str_starts_with($query->sql, 'update "cards"') && str_contains($query->sql, 'inferred_variants')) {
            $writes++;
        }
    });

    uvsSync(['normal', 'reverse-holofoil']);
    $afterFirst = $writes;
    uvsSync(['normal', 'reverse-holofoil']);

    expect($afterFirst)->toBe(1)
        ->and($writes)->toBe(1);
});

test('the cards of a set with no TCGplayer rows keep the list they have today', function () {
    uvsStoredCard();
    $card = uvsSync();

    expect($card->inferred_variants)->toBeNull();
    expect(Card::where('tcgdex_id', 'me06-001')->sole()->inferred_variants)->toBeNull();
});

/** @return array<string, mixed> */
function uvsRealRaw(): array
{
    return ['id' => 'me06-001', 'variants_detailed' => [
        ['type' => 'normal', 'size' => 'standard', 'variantId' => 'a1b2c3'],
        ['type' => 'reverse', 'size' => 'standard', 'variantId' => 'd4e5f6'],
    ]];
}

/**
 * @param  list<string>  $labels
 * @param  array<string, mixed>  $raw
 * @param  array{normal: int, holo: int, reverse: int, firstEd: int}|null  $counts
 */
function uvsSyncWith(array $labels, array $raw, ?array $counts): Card
{
    $detail = uvsDetail($labels);
    $detail->raw = $raw;
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me06-001')->andReturn($detail);
    $provider->shouldReceive('findSet')->with('me06')->andReturn(new SetSummaryData(
        tcgdexId: 'me06', name: 'Delta Reign', series: null, releasedOn: null, cardCount: null, logoUrl: null,
        variantCounts: $counts,
    ));

    return (new CatalogSyncService($provider))->syncCard('me06-001');
}

test('a card is never given a deduced list outside the unconfirmed gate, whatever its TCGplayer rows say', function (array $raw, ?array $counts) {
    uvsStoredCard($counts ?? ['normal' => 80, 'holo' => 60, 'reverse' => 70, 'firstEd' => 0]);
    if ($counts === null) {
        Set::where('tcgdex_id', 'me06')->update(['variant_counts' => null]);
    }

    $card = uvsSyncWith(['normal', 'holofoil'], $raw, $counts);

    expect($card->inferred_variants)->toBeNull()
        ->and(CardVariants::available($card->variants, $card->raw, $card->inferred_variants))
        ->toBe(CardVariants::available($card->variants, $card->raw));
})->with([
    'a confirmed set, placeholder card' => [fn () => uvsPlaceholderRaw(), ['normal' => 80, 'holo' => 60, 'reverse' => 70, 'firstEd' => 0]],
    'counts never recorded' => [fn () => uvsPlaceholderRaw(), null],
    'zero counts but real print entries' => [fn () => uvsRealRaw(), ['normal' => 0, 'holo' => 0, 'reverse' => 0, 'firstEd' => 0]],
]);

test('a previously inferred card is cleared when tcgdex fills its prints in, and available() already ignores the stale list', function () {
    $card = uvsStoredCard();
    $card->update(['inferred_variants' => ['normal', 'reverse-holofoil']]);

    expect(CardVariants::available(['normal' => true, 'reverse' => true], uvsRealRaw(), ['normal', 'reverse-holofoil', 'holofoil']))
        ->toBe(CardVariants::available(['normal' => true, 'reverse' => true], uvsRealRaw()));

    $synced = uvsSyncWith(['normal', 'reverse-holofoil'], uvsRealRaw(), ['normal' => 0, 'holo' => 0, 'reverse' => 0, 'firstEd' => 0]);

    expect($synced->inferred_variants)->toBeNull();
});

test('a previously inferred card is cleared when its set starts reporting prints', function () {
    $card = uvsStoredCard();
    $card->update(['inferred_variants' => ['normal', 'reverse-holofoil']]);

    $synced = uvsSyncWith(['normal', 'reverse-holofoil'], uvsPlaceholderRaw(), ['normal' => 80, 'holo' => 60, 'reverse' => 70, 'firstEd' => 0]);

    expect($synced->inferred_variants)->toBeNull();
});

test('two nightly syncs give the identical list', function () {
    uvsStoredCard();

    $first = uvsSync(['normal', 'reverse-holofoil'])->inferred_variants;
    $second = uvsSync(['normal', 'reverse-holofoil'])->inferred_variants;

    expect($second)->toBe($first)->toBe(['normal', 'reverse-holofoil']);
});

test('a night tcgdex omits the card\'s prices keeps the list while earlier rows are in the window and clears it beyond', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'UTC'));
    uvsStoredCard();
    uvsSync(['normal', 'reverse-holofoil']);

    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00', 'UTC'));
    $nextNight = uvsSync()->inferred_variants;

    $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'UTC'));
    $atEdge = uvsSync()->inferred_variants;

    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00', 'UTC'));
    $beyond = uvsSync()->inferred_variants;

    expect($nextNight)->toBe(['normal', 'reverse-holofoil'])
        ->and($atEdge)->toBe(['normal', 'reverse-holofoil'])
        ->and($beyond)->toBeNull();
});

test('the deduced list never reaches a price write', function () {
    $card = uvsStoredCard();
    $card->update(['inferred_variants' => ['normal', 'reverse-holofoil']]);

    uvsSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->count())->toBe(0);
});
