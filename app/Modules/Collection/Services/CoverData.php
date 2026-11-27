<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Models\Card;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What the collection page's cover shows, all in one currency. Everything but
 * `cards`, `totals` and `series` is null when nothing is priced.
 */
final readonly class CoverData
{
    /**
     * @param  EloquentCollection<int, Card>  $cards  the cap's cards with their copies and deciding price rows
     * @param  array<string, int>  $totals  every currency's total, the primary currency first; empty when nothing is priced
     * @param  Collection<int, array{date: CarbonImmutable, minor: int, priced: int}>  $series  the value line, from the first day the coverage guard accepts
     * @param  ?int  $rangeMoveMinor  last point minus first of the drawn line, null for fewer than two points
     * @param  ?CarbonImmutable  $linePricedSince  set only when the guard cut the line's start
     * @param  ?CarbonImmutable  $asOf  the newest marketplace price day
     */
    public function __construct(
        public EloquentCollection $cards,
        public array $totals,
        public ?string $currency,
        public ?int $totalMinor,
        public Collection $series,
        public ?int $rangeMoveMinor,
        public ?CarbonImmutable $linePricedSince,
        public ?CarbonImmutable $asOf,
    ) {}
}
