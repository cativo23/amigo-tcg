<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Catalog\Support\MarketplaceSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The data behind the collection page's cover: the headline total, the value
 * line over a range, and the line's move.
 *
 * The line prices today's holdings at each past day's prices, so a card that
 * only has a price from some day on adds a step on that day that no market
 * move caused. The coverage guard hides that start: the line is drawn from the
 * first day on which at least PRICED_SHARE_FLOOR of the cards priced today
 * (in the page's currency) also had a price, and the move is the move of what
 * is drawn. The guard only decides where the line starts: a later day that
 * dips below the floor is drawn as it is. It reuses CollectionValueSeries, so
 * the cards, the cap, the cache and today's point are the Activity page's; the
 * series always ends on today, with today's priced-card count from the live
 * cards.
 */
final class CollectionCover
{
    /** What the move is called on the page: the value of today's holdings, never a market move. */
    public const MOVE_LABEL = 'collection value';

    /** The share of today's priced cards a day needs to have a price for its point to be drawn. */
    public const PRICED_SHARE_FLOOR = 0.9;

    public function __construct(private readonly CollectionValueSeries $series) {}

    public function for(PublicCollection $public, ChartRange $range): CoverData
    {
        $result = $this->series->forPublicCollection($public, $range);
        $currency = $result['currency'];

        if ($currency === null) {
            return new CoverData($result['cards'], $result['totals'], null, null, collect(), null, null, null);
        }

        $all = $result['series'];
        $drawn = $this->fromFirstCoveredDay($all);
        $start = $drawn->first();
        $end = $drawn->last();
        $firstDay = $all->first();

        return new CoverData(
            cards: $result['cards'],
            totals: $result['totals'],
            currency: $currency,
            totalMinor: $result['totals'][$currency],
            series: $drawn,
            rangeMoveMinor: $drawn->count() >= 2 && $start !== null && $end !== null ? $end['minor'] - $start['minor'] : null,
            linePricedSince: $start !== null && $firstDay !== null && $start['date']->gt($firstDay['date']) ? $start['date'] : null,
            asOf: MarketplaceSync::latestDay(MarketplaceSync::rows($result['cards']))?->toImmutable(),
        );
    }

    /**
     * The series from the first point whose priced-card count reaches the
     * floor of today's, the series' last point. Empty when there is no point.
     *
     * @param  Collection<int, array{date: CarbonImmutable, minor: int, priced: int}>  $series
     * @return Collection<int, array{date: CarbonImmutable, minor: int, priced: int}>
     */
    private function fromFirstCoveredDay(Collection $series): Collection
    {
        $today = $series->last()['priced'] ?? 0;

        if ($today === 0) {
            return collect();
        }

        // A division, not a product: 9 of 10 is exactly the double 0.9, so a day on the floor is drawn.
        $first = $series->search(fn (array $point) => $point['priced'] / $today >= self::PRICED_SHARE_FLOOR);

        return $first === false ? collect() : $series->slice($first)->values();
    }
}
