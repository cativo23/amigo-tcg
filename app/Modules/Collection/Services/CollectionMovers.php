<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CardPriceResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The biggest 7-day price drops and rises across a collection's cards.
 *
 * Runs over cards whose deciding price rows are already loaded, week
 * baseline included (see DecidingPriceRows), so it adds no query: it is
 * Valuation::headlineSnapshot() and CardPriceResolver::weekDeltaFor() in
 * PHP, then a sort. Each card counts once at its single-copy headline price,
 * the price its tile shows, never price times quantity.
 *
 * Percent alone would put cheap cards on top and dollars alone the priciest,
 * so a card is ranked only when its price a week earlier is at least
 * MIN_PRICE_MINOR and it moved at least MIN_MOVE_MINOR; the rank is then by
 * percent. Only cards priced in the page's primary currency are ranked;
 * amounts in two currencies are never compared.
 */
final class CollectionMovers
{
    /** A card priced below this a week earlier is not ranked: a few cents' move is a huge percent. */
    public const MIN_PRICE_MINOR = 100;

    /** A move smaller than this is not ranked, whatever its percent. */
    public const MIN_MOVE_MINOR = 25;

    /** Rows per block. */
    public const LIMIT = 4;

    public function __construct(
        private readonly Valuation $valuation,
        private readonly CardPriceResolver $resolver,
    ) {}

    /**
     * @param  EloquentCollection<int, Card>  $cards  owned cards with copies and deciding price rows loaded
     * @param  ?string  $currency  the page's primary currency, null when nothing is priced
     * @return array{drops: list<Mover>, rises: list<Mover>, omitted: int, currency: ?string} omitted counts the cards priced in another currency
     */
    public function rank(EloquentCollection $cards, ?string $currency): array
    {
        if ($currency === null) {
            return ['drops' => [], 'rises' => [], 'omitted' => 0, 'currency' => null];
        }

        $drops = [];
        $rises = [];
        $omitted = 0;

        foreach ($cards as $card) {
            $snapshot = $this->valuation->headlineSnapshot($card);

            if ($snapshot === null || $snapshot->market_minor === null) {
                continue;
            }

            if ($snapshot->currency !== $currency) {
                $omitted++;

                continue;
            }

            // The earlier price comes from the same print and the same currency as the current one (the week baseline is keyed by both).
            $delta = $this->resolver->weekDeltaFor($card, $snapshot);
            $previous = (int) ($delta?->previous->market_minor ?? 0);

            if ($delta === null || $previous < self::MIN_PRICE_MINOR || abs($delta->deltaMinor) < self::MIN_MOVE_MINOR) {
                continue;
            }

            $mover = new Mover($card, $snapshot->market_minor, $previous, $delta->deltaMinor, $delta->deltaMinor / $previous * 100, $currency);

            if ($mover->deltaMinor < 0) {
                $drops[] = $mover;
            } else {
                $rises[] = $mover;
            }
        }

        // Equal percents: the bigger dollar move first, then the lower card id, so the order never changes between renders.
        usort($drops, fn (Mover $a, Mover $b) => [$a->percent, $a->deltaMinor, $a->card->id] <=> [$b->percent, $b->deltaMinor, $b->card->id]);
        usort($rises, fn (Mover $a, Mover $b) => [$b->percent, $b->deltaMinor, $a->card->id] <=> [$a->percent, $a->deltaMinor, $b->card->id]);

        return [
            'drops' => array_slice($drops, 0, self::LIMIT),
            'rises' => array_slice($rises, 0, self::LIMIT),
            'omitted' => $omitted,
            'currency' => $currency,
        ];
    }
}
