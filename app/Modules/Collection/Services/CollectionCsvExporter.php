<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

/**
 * Writes the authenticated user's whole collection as CSV, one row per
 * collection item, with every field the item carries plus its current
 * market price for its own variant — the same price the collection page
 * shows. Scoped through Collection's TenantScope, so it only ever sees
 * the current user's items.
 */
final class CollectionCsvExporter
{
    public const HEADER = [
        'card_name', 'set_name', 'set_id', 'number', 'tcgdex_id', 'variant', 'condition',
        'grade_company', 'grade_value', 'quantity', 'notes', 'market_price', 'currency', 'price_date',
    ];

    public function __construct(private readonly CardPriceResolver $resolver = new CardPriceResolver) {}

    /**
     * @param  resource  $out
     */
    public function write($out): void
    {
        // A BOM so Excel reads the file as UTF-8 instead of mangling
        // accented names; other spreadsheet apps ignore it.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADER, escape: '', eol: "\r\n");

        // Every card the user holds, priced once from the rows that decide
        // a current price (see DecidingPriceRows): up to the collection
        // page's ceiling of distinct cards, the same card set, and so the
        // same cache entry, as that page. The items then
        // stream in batches and take their card from it; a copy of a card
        // added after that read loads its card on its own.
        $loader = app(DecidingPriceRows::class);
        $cards = $loader->loadOnto(Card::query()
            ->with('set')
            ->whereIn('id', CollectionItem::query()->whereIn('collection_id', Collection::query()->select('id'))->select('card_id'))
            ->get())->keyBy('id');

        CollectionItem::query()
            ->whereIn('collection_id', Collection::query()->select('id'))
            ->lazyById(200)
            ->each(function (CollectionItem $item) use ($out, $loader, &$cards): void {
                if (! $cards->has($item->card_id)) {
                    $cards = $cards->union($loader->loadOnto(Card::query()->with('set')->whereKey($item->card_id)->get())->keyBy('id'));
                }
                $item->setRelation('card', $cards->get($item->card_id));
                fputcsv($out, $this->row($item), escape: '', eol: "\r\n");
            });
    }

    /**
     * @return array<int, string>
     */
    private function row(CollectionItem $item): array
    {
        $snapshot = $this->resolver->resolveForVariant($item->card, $item->variant);

        return array_map($this->neutraliseFormula(...), [
            $item->card->name,
            $item->card->set->name,
            $item->card->set->tcgdex_id,
            $item->card->local_id,
            $item->card_tcgdex_id,
            (string) $item->variant,
            $item->condition,
            (string) $item->grade_company,
            (string) $item->grade_value,
            (string) $item->quantity,
            (string) $item->notes,
            $snapshot?->market_minor !== null ? number_format($snapshot->market_minor / 100, 2, '.', '') : '',
            (string) $snapshot?->currency,
            (string) $snapshot?->captured_on?->toDateString(),
        ]);
    }

    /**
     * Spreadsheet apps run a cell starting with = + - @ (or a tab, CR or
     * LF that precedes one) as a formula, so a note like =HYPERLINK(...)
     * would execute on open; some locales treat the full-width forms the
     * same way. A leading apostrophe makes it plain text.
     */
    private function neutraliseFormula(string $value): string
    {
        $first = mb_substr($value, 0, 1);

        return $first !== '' && in_array($first, ['=', '+', '-', '@', "\t", "\r", "\n", '＝', '＋', '－', '＠'], true)
            ? "'".$value
            : $value;
    }
}
