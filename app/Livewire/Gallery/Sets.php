<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Support\Completion;
use App\Modules\Catalog\Support\PrintedStrip;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\PublicCollection;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Sets are collections within the collection: completion, a mini printed strip and owned value per set. */
#[Layout('layouts.revamp')]
final class Sets extends Component
{
    use ResolvesPublicCollection;

    public const SORTS = ['value' => 'by value', 'newest' => 'newest first', 'completion' => 'most complete'];

    /** Value cards cover this share of the value, within the bounds below; the rest is a compact table. */
    private const CARD_COVERAGE = 0.75;

    private const MIN_CARDS = 3;

    private const MAX_CARDS = 6;

    #[Url(except: 'value')]
    public string $sort = 'value';

    public function mount(string $username): void
    {
        $this->resolveTargetUser($username);
    }

    public function render(): View
    {
        $public = $this->publicCollection();
        $valuation = new Valuation;

        // Each set's imported card numbers and the ones the collector owns
        // publicly ride in this same query as json subselects, so the strip
        // costs no query of its own however many sets there are and owns
        // every card however large the collection. Sets released the same
        // day fall back to name order.
        $sets = $public->setsQuery()
            ->addSelect([
                'imported_numbers' => Card::query()
                    ->selectRaw("coalesce(json_agg(cards.local_id), '[]'::json)")
                    ->whereColumn('cards.set_id', 'sets.id'),
                'owned_numbers' => $public->scopeItems(CollectionItem::query()
                    ->join('cards', 'cards.id', '=', 'collection_items.card_id')
                    ->selectRaw("coalesce(json_agg(distinct cards.local_id), '[]'::json)")
                    ->whereColumn('cards.set_id', 'sets.id')),
            ])
            ->orderByDesc('released_on')
            ->orderBy('name')
            ->get();

        // One query for the owned cards (bounded by the cap) and one for the
        // price rows that decide their current prices, grouped per set in PHP,
        // so the per-set value never becomes a query per set.
        $ownedBySet = app(DecidingPriceRows::class)
            ->loadOnto($public->cardsInIdOrder()->take(PublicCollection::MAX_CARDS)->get())
            ->groupBy('set_id');

        $setValues = $sets->mapWithKeys(fn ($set) => [
            $set->id => $valuation->totalsByCurrency($ownedBySet->get($set->id, collect())),
        ]);

        $sort = array_key_exists($this->sort, self::SORTS) ? $this->sort : 'value';
        $primary = array_key_first(Valuation::order(array_reduce(
            $setValues->all(),
            function (array $sum, array $totals): array {
                foreach ($totals as $currency => $minor) {
                    $sum[$currency] = ($sum[$currency] ?? 0) + $minor;
                }

                return $sum;
            },
            [],
        )));

        // What tcgdex reports for the set, else what the catalogue holds. The
        // strip and the printed "x / y" both read this one figure.
        $totals = $sets->mapWithKeys(fn (Set $set) => [
            $set->id => (int) ($set->card_count ?: $set->getAttribute('real_card_count')),
        ]);

        // The query's order is newest first (name among equals); the other sorts keep it as the tie-break
        // because sortBy is stable.
        $sets = match ($sort) {
            'value' => $sets->sortByDesc(fn (Set $set) => $primary === null ? 0 : ($setValues[$set->id][$primary] ?? 0)),
            'completion' => $sets->sortByDesc(fn (Set $set) => Completion::percent((int) $set->getAttribute('owned_card_count'), $totals->get($set->id, 0))),
            default => $sets,
        };
        $sets = $sets->values();

        // Shares are arithmetic on the totals above: no price row is read for them. A set priced only in
        // another currency has no share, since amounts in two currencies are never compared.
        $collectionValue = $primary === null ? 0 : $setValues->sum(fn (array $totals) => $totals[$primary] ?? 0);
        $shares = $collectionValue > 0
            ? $sets->mapWithKeys(function (Set $set) use ($setValues, $primary, $collectionValue) {
                $minor = $setValues[$set->id][$primary] ?? 0;

                return [$set->id => $minor > 0 ? max(1, (int) round($minor / $collectionValue * 100)) : null];
            })
            : collect();
        // A share of one priced set is 100% of nothing to compare: it says nothing, so it is left out.
        if ($shares->filter()->count() < 2) {
            $shares = collect();
        }
        $cardSets = $sort === 'value' ? $sets->take($this->cardCount($sets, $setValues, $primary, $collectionValue)) : $sets;
        $tailSets = $sets->slice($cardSets->count())->values();

        // One fact, and only when there is a split to speak of: the leading set's share of the value.
        $top = $sets->sortByDesc(fn (Set $set) => $setValues[$set->id][$primary ?? ''] ?? 0)->first();
        $callout = $top !== null && $shares->get($top->id) !== null
            ? "{$top->name} holds {$shares->get($top->id)}% of the value."
            : null;

        // The same slot rule as the set page's strip, compressed into runs.
        $strips = $sets->mapWithKeys(fn (Set $set) => [
            $set->id => PrintedStrip::runs(PrintedStrip::slots($this->importedCards($set), $totals->get($set->id, 0))),
        ]);

        $name = $this->collectorName();

        return view('livewire.gallery.sets', [
            'sets' => $sets,
            'cardSets' => $cardSets,
            'tailSets' => $tailSets,
            'shares' => $shares,
            'callout' => $callout,
            'primaryCurrency' => $primary,
            'setValues' => $setValues,
            'totals' => $totals,
            'strips' => $strips,
            'activeSort' => $sort,
        ])->layoutData([
            'title' => "Sets · {$name}'s collection",
            'description' => "The {$sets->count()} Pokémon TCG sets in {$name}'s collection, with completion and owned value per set.",
            'isOwner' => $this->isOwnerViewing(),
        ]);
    }

    /**
     * How many of the value-ordered sets get a card: enough to cover CARD_COVERAGE of the value, never fewer
     * than MIN_CARDS nor more than MAX_CARDS. Without a priced set every set is a card.
     *
     * @param  EloquentCollection<int, Set>  $sets
     * @param  SupportCollection<int, array<string, int>>  $setValues
     */
    private function cardCount(EloquentCollection|SupportCollection $sets, SupportCollection $setValues, ?string $primary, int $collectionValue): int
    {
        if ($primary === null || $collectionValue <= 0) {
            return $sets->count();
        }

        $covered = 0;
        $count = 0;
        foreach ($sets as $set) {
            $count++;
            $covered += $setValues[$set->id][$primary] ?? 0;
            if ($covered >= $collectionValue * self::CARD_COVERAGE) {
                break;
            }
        }

        return min(max($count, self::MIN_CARDS), self::MAX_CARDS);
    }

    /**
     * The set's imported cards as the strip rule wants them: number plus
     * whether the collector owns it. Both lists come from the sets query, so
     * ownership covers every public card, not just the ones priced for the
     * owned value.
     *
     * @return list<array{number: string, owned: bool}>
     */
    private function importedCards(Set $set): array
    {
        $ownedNumbers = array_flip(array_map('strval', $this->numbers($set, 'owned_numbers')));

        return array_map(
            fn (string $number) => ['number' => $number, 'owned' => isset($ownedNumbers[$number])],
            array_map('strval', $this->numbers($set, 'imported_numbers')),
        );
    }

    /** @return list<mixed> */
    private function numbers(Set $set, string $attribute): array
    {
        $numbers = json_decode((string) $set->getAttribute($attribute), true);

        return is_array($numbers) ? array_values($numbers) : [];
    }
}
