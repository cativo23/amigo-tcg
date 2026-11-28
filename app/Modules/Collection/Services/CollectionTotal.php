<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Services\PriceDataVersion;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

/**
 * The public collection's total by currency (the cover's headline), cached so a page that does not
 * load the whole collection (the set page, the card page) can still say what share it is, without
 * reading a single price row on a warm read.
 *
 * The key carries what can change the total: the day, the price-data version (see PriceDataVersion,
 * bumped on every price write through the app) and a fingerprint of the public copies taken with one
 * aggregate query. The version is read BEFORE the rows it guards, so an entry is only ever stored
 * under a version that was current when its rows were read.
 *
 * The total covers the first PublicCollection::MAX_CARDS cards in id order, the same cards the
 * cover and the series price.
 */
final class CollectionTotal
{
    /** Cards whose price rows are held in memory at once while summing. */
    public const CHUNK = 40;

    /** Past the day in the key; an entry for a finished day is never read again. */
    private const TTL_SECONDS = 2 * 86400;

    public function __construct(private readonly Valuation $valuation = new Valuation) {}

    /** @return array<string, int> currency → minor units, USD first; empty when nothing is public or priced */
    public function for(PublicCollection $public): array
    {
        $key = $this->key($public);

        if ($key === null) {
            return [];
        }

        $cached = Cache::get($key['key']);

        return is_array($cached) ? $cached : $this->build($public, $key['key']);
    }

    /**
     * Sums the total afresh and stores it, for the jobs that warm the caches. A job that already holds the
     * collection's capped cards in id order passes them, so they are not loaded a second time.
     *
     * @param  EloquentCollection<int, Card>|null  $cards  `cardsInIdOrder()` capped at MAX_CARDS, price rows not loaded
     * @return array<string, int>
     */
    public function refresh(PublicCollection $public, ?EloquentCollection $cards = null): array
    {
        $key = $this->key($public);

        return $key === null ? [] : $this->build($public, $key['key'], $cards);
    }

    /**
     * @param  EloquentCollection<int, Card>|null  $cards
     * @return array<string, int>
     */
    private function build(PublicCollection $public, string $key, ?EloquentCollection $cards = null): array
    {
        $cards ??= $public->cardsInIdOrder()->take(PublicCollection::MAX_CARDS)->get();
        $loader = app(DecidingPriceRows::class);
        $totals = [];

        foreach ($cards->chunk(self::CHUNK) as $chunk) {
            $batch = $loader->loadOnto(new EloquentCollection($chunk->all()));

            foreach ($this->valuation->totalsByCurrency($batch) as $currency => $minor) {
                $totals[$currency] = ($totals[$currency] ?? 0) + $minor;
            }

            $batch->each(fn (Card $card) => $card->unsetRelation('priceSnapshots'));
        }

        $totals = Valuation::order($totals);
        Cache::put($key, $totals, self::TTL_SECONDS);

        return $totals;
    }

    /** @return array{key: string}|null null when the user has no public collection */
    private function key(PublicCollection $public): ?array
    {
        if ($public->isEmpty()) {
            return null;
        }

        // Read first: see the class docblock.
        $version = app(PriceDataVersion::class)->current();

        $copies = $public->scopeItems(CollectionItem::query())
            ->selectRaw('count(*) as copies, coalesce(sum(quantity), 0) as quantity, coalesce(max(id), 0) as newest, max(updated_at) as touched')
            ->toBase()
            ->first();
        $fingerprint = sha1(json_encode([$public->collectionIds()->sort()->values()->all(), $copies], JSON_THROW_ON_ERROR));

        return ['key' => "collection-total:v1:{$public->user->id}:".today()->toDateString().":{$version}:{$fingerprint}"];
    }
}
