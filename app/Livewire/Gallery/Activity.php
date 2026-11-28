<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Services\PriceDelta;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Catalog\Support\MarketplaceSync;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\ActivityFeedGrouper;
use App\Modules\Collection\Services\CollectionCover;
use App\Modules\Collection\Services\CollectionValueSeries;
use App\Modules\Collection\Services\PublicCollection;
use App\Modules\Collection\Services\Valuation;
use App\Modules\Collection\Support\ValueChart;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What changed: price moves since the previous snapshot and cards added,
 * as one chronological feed, under the collection's value over time.
 */
#[Layout('layouts.revamp')]
final class Activity extends Component
{
    use ResolvesPublicCollection;

    /** How many rows the feed lists: a group of small additions is one row. */
    private const MAX_FEED = 40;

    /**
     * How many of the newest copies the feed looks at, so that a bulk import is
     * counted whole and the few notable cards in it are found. Only the newest
     * MAX_FEED may bring a card the headline did not load; the rest are read
     * from the cards it already priced.
     */
    private const MAX_ITEMS = PublicCollection::MAX_CARDS;

    /**
     * The groups of small additions the visitor opened, as `{day}|{currency}`
     * keys. Locked: only toggleGroup() changes it, and it only ever holds a
     * key it was given in that shape.
     *
     * @var list<string>
     */
    #[Locked]
    public array $openGroups = [];

    /**
     * The smallest price move the feed lists, in minor units of the move's
     * own currency ($1.00, €1.00), per card as the row shows it, never
     * multiplied by the copies held. Smaller moves are day-to-day noise
     * that would crowd out the rest; additions are always listed.
     */
    public const MIN_MOVE_MINOR = 100;

    /**
     * The chart's range, from `?range=`. Untrusted like CardShow's, hence
     * the union: ChartRange::fromInput() turns anything unknown into one
     * month.
     *
     * @var string|array<array-key, mixed>|null
     */
    #[Url(as: 'range', except: '1m', history: true)]
    public string|array|null $range = '1m';

    public function selectRange(mixed $key): void
    {
        if (is_string($key)) {
            $this->range = $key;
        }
    }

    public function toggleGroup(mixed $key): void
    {
        if (! is_string($key) || preg_match('/^\d{4}-\d{2}-\d{2}\|[A-Z]{3}$/', $key) !== 1) {
            return;
        }

        $this->openGroups = in_array($key, $this->openGroups, true)
            ? array_values(array_diff($this->openGroups, [$key]))
            : [...$this->openGroups, $key];
    }

    public function mount(string $username): void
    {
        $this->resolveTargetUser($username);
    }

    public function render(): View
    {
        $public = $this->publicCollection();
        $resolver = new CardPriceResolver;
        $valuation = new Valuation($resolver);

        $seriesService = new CollectionValueSeries($valuation);
        $chartRange = ChartRange::fromInput($this->range);

        // The collection page's cover draws its line through the same guard, so both pages start
        // the line, and measure its move, on the first day most of the cards had a price.
        $cover = (new CollectionCover($seriesService))->for($public, $chartRange);
        $cards = $cover->cards;
        $totals = $cover->totals;
        $primaryCurrency = $cover->currency;
        $series = $cover->series;
        $earliest = $primaryCurrency === null ? null : $seriesService->earliestDay($cards);

        $moves = $cards
            ->map(function (Card $card) use ($resolver, $valuation) {
                // deltaFor() against the owned headline variant, not
                // resolveDelta(): the latter runs the card-level chain,
                // so a collector who owns only the reverse-holofoil
                // print would be shown the normal print's movement — a
                // trend for a card they do not have.
                $delta = $resolver->deltaFor($card, $valuation->headlineSnapshot($card));

                // Under MIN_MOVE_MINOR is not listed (the page says so); zero never was.
                if ($delta === null || abs($delta->deltaMinor) < self::MIN_MOVE_MINOR) {
                    return null;
                }

                return [
                    'kind' => 'move',
                    'card' => $card,
                    'delta' => $delta,
                    'at' => $delta->latest->captured_on,
                ];
            })
            ->filter();

        // Copies added in one bulk action share a created_at, so the id breaks the tie: the window keeps the newest of them, not whichever the database hands back first.
        $items = $public->itemsQuery()->orderByDesc('id')->take(self::MAX_ITEMS)->get();

        // An added copy's card is almost always one the headline already
        // loaded, price rows included: reuse it, so its rows are neither
        // loaded nor resolved twice. Only a card past the cap, and only for
        // the newest MAX_FEED copies, is loaded here, in one batch through
        // the same deciding-rows query; an older copy of such a card is left
        // out, so the window never costs more price reads than the feed did.
        $loaded = $cards->keyBy('id');
        $missing = $items->take(self::MAX_FEED)->pluck('card_id')->unique()->diff($loaded->keys())->values();

        if ($missing->isNotEmpty()) {
            $loaded = $loaded->union(app(DecidingPriceRows::class)->loadOnto(
                Card::query()->with('set')->whereKey($missing)->get(),
            )->keyBy('id'));
        }

        $items = $items->filter(fn (CollectionItem $item) => $loaded->has($item->card_id))->values();
        $items->each(fn (CollectionItem $item) => $item->setRelation('card', $loaded->get($item->card_id)));

        $additions = $items
            ->map(fn (CollectionItem $item) => [
                'kind' => 'added',
                'card' => $item->card,
                'item' => $item,
                // resolveForVariant, not resolve(): the row next to this
                // price names the variant the copy actually is, and
                // resolve()'s card-level chain only ever considers
                // tcgplayer normal/holofoil — so a "Reverse Holofoil"
                // entry would carry the normal print's price, which is a
                // different card's value.
                //
                // No fallback when the print has no price of its own: the
                // tile, the totals and the CSV show none for it either, and
                // the card-wide average would put another print's value
                // under this row's label.
                'snapshot' => $resolver->resolveForVariant($item->card, $item->variant),
                'at' => $item->created_at,
            ]);

        // Newest first. Every move of a day carries that day's midnight, so
        // moves of the same day go biggest first, then the newest copy, then
        // the oldest card, so the database's order never decides what the cap
        // keeps. Small moves were dropped above, before this cap, so they
        // never take one of its slots.
        /** @var Collection<int, array<string, mixed>> $sorted */
        $sorted = $moves->concat($additions)
            ->sort(fn (array $a, array $b) => [$b['at']->timestamp ?? 0, self::size($b), self::copyId($b), $a['card']->id] <=> [$a['at']->timestamp ?? 0, self::size($a), self::copyId($a), $b['card']->id])
            ->values();

        // Small additions of a day become one row, so a bulk import neither
        // buries the notable cards in it nor takes the cap's slots; the cap
        // counts rows. Their threshold is the one for moves: under $1.00.
        $feed = ActivityFeedGrouper::group($sorted, self::MIN_MOVE_MINOR)->take(self::MAX_FEED)->values();

        // Display only: the same rows in the same order, cut into one
        // group per calendar day (a move's day is its latest price's day,
        // an addition's the day it was added). An entry without a time is
        // only possible in the types (captured_on is NOT NULL, every copy
        // gets created_at); it groups at the epoch, last, where the sort put it.
        $days = $feed
            ->groupBy(fn (array $entry): string => (string) ($entry['at'] ?? CarbonImmutable::createFromTimestamp(0))->toDateString())
            ->map(fn (Collection $entries, string|int $day) => ['date' => CarbonImmutable::parse((string) $day), 'entries' => $entries->values()])
            ->values();

        // The latest marketplace sync, which dates the summary as the
        // collection page dates its own.
        $updatedAt = MarketplaceSync::latestDay(MarketplaceSync::rows($cards));

        // At most one start note: where the guard cut the line, else where the history itself begins.
        $startsAfterRange = $earliest !== null && $earliest->toDateString() > $chartRange->start(today())->toDateString();
        $historySince = $cover->linePricedSince === null && $series->isNotEmpty() && $startsAfterRange ? $series->first()['date'] : null;

        $name = $this->collectorName();

        return view('livewire.gallery.activity', [
            'feed' => $feed,
            // Deliberately the feed's own count, not $moves->count() — an
            // item is always in $feed as an "added" entry regardless of
            // whether its price has ever moved, so a header counting only
            // moves could read "0 price moves" directly above a feed
            // showing real "Added <card>" entries. This is what's
            // actually listed below.
            'feedCount' => (int) $feed->sum(fn (array $row) => $row['type'] === 'group' ? $row['count'] : 1),
            'openGroups' => $this->openGroups,
            'days' => $days,
            'updatedAt' => $updatedAt,
            'totals' => $totals,
            'series' => $series,
            'primaryCurrency' => $primaryCurrency,
            'chartRange' => $chartRange,
            'historySince' => $historySince,
            'linePricedSince' => $cover->linePricedSince,
            'chart' => $primaryCurrency === null ? null : ValueChart::of($series, $primaryCurrency),
        ])->layoutData([
            'title' => "Activity · {$name}'s collection",
            'description' => "Price movements and recent additions in {$name}'s Pokémon TCG collection.",
            'isOwner' => $this->isOwnerViewing(),
        ]);
    }

    /**
     * How big a feed entry's move is, for ordering a day's moves; an
     * addition has none.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function size(array $entry): int
    {
        $delta = $entry['delta'] ?? null;

        return $delta instanceof PriceDelta ? abs($delta->deltaMinor) : 0;
    }

    /**
     * The id of the copy a feed entry adds, to put the newest of copies added
     * together first; a price move has none.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function copyId(array $entry): int
    {
        $item = $entry['item'] ?? null;

        return $item instanceof CollectionItem ? (int) $item->id : 0;
    }
}
