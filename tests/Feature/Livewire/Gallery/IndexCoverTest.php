<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\WeekBaselines;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\PublicCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\RevampHtml;

/**
 * carlos's public collection, one owned copy per spec, priced today and a week ago.
 *
 * @param  list<array{0: string, 1: int, 2: int, 3?: string}>  $cards  name, price now, price a week ago, currency
 */
function coverPage(array $cards): void
{
    Cache::flush();
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $now = now();

    foreach ($cards as $i => [$name, $current, $previous]) {
        $currency = $cards[$i][3] ?? 'USD';
        $card = Card::create(['tcgdex_id' => 'me05-'.($i + 1), 'set_id' => $set->id, 'local_id' => (string) ($i + 1), 'name' => $name]);
        DB::table('collection_items')->insert([
            'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id,
            'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ([0 => $current, 7 => $previous] as $daysAgo => $minor) {
            DB::table('card_price_snapshots')->insert([
                'card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal',
                'captured_on' => today()->subDays($daysAgo)->toDateString(), 'currency' => $currency,
                'market_minor' => $minor, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    app(WeekBaselines::class)->refreshAll();
}

/** Eight USD cards, one falling 20 %, one rising 25 %, the rest flat; plus a EUR card that is the priciest of all. */
function movingCollection(): void
{
    coverPage([
        ['Alpha', 20000, 25000], ['Bravo', 10000, 8000], ['Charlie', 9000, 9000], ['Delta', 8000, 8000],
        ['Echo', 7000, 7000], ['Foxtrot', 6000, 6000], ['Golf', 5000, 5000], ['Hotel', 4000, 4000],
        ['Euro', 99999, 90000, 'EUR'],
    ]);
}

test('the total is the headline figure and the collector name stays a heading above it', function () {
    movingCollection();

    $html = $this->get('/carlos')->assertOk()->getContent();

    expect(RevampHtml::text('col-figure', $html))->toBe('$690.00')
        ->and($html)->toContain('<h1 id="col-who" class="col-who">carlos</h1>')
        ->and(strpos($html, 'class="col-who"'))->toBeLessThan(strpos($html, 'class="col-figure"'));
});

test('the move is labelled collection value and carries a glyph and a sign', function () {
    movingCollection();

    $html = $this->get('/carlos')->getContent();

    expect(RevampHtml::text('col-move price-move price-move--down', $html).RevampHtml::text('col-move price-move price-move--up', $html))
        ->toContain('collection value')
        ->toMatch('/[▲▼] [+−]\$/u');
});

test('the approximate-prices note appears once on the page', function () {
    movingCollection();

    expect(substr_count($this->get('/carlos')->getContent(), 'Approx. TCGplayer market prices.'))->toBe(1);
});

test('the value line is drawn from the series', function () {
    movingCollection();

    $html = $this->get('/carlos')->getContent();

    expect($html)->toMatch('/class="col-line( chart--down)?"/')->and($html)->toMatch('/class="col-area( chart--down)?"/');
});

test('this week shows the one biggest drop and the one biggest rise with a link to activity', function () {
    movingCollection();

    $html = $this->get('/carlos')->getContent();
    preg_match('#<section class="col-strip".*?</section>#s', $html, $strip);
    $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($strip[0] ?? '')));

    expect($strip)->not->toBeEmpty()
        ->and($text)->toContain('Alpha')->toContain('▼ −20%')
        ->and($text)->toContain('Bravo')->toContain('▲ +25%')
        ->and($text)->not->toContain('Charlie')
        ->and($strip[0])->toContain('href="'.url('/carlos/activity').'"')
        ->and($text)->toContain('See all movers');
});

test('the top shelf shows the six priciest cards in the page currency as ordinary tiles', function () {
    movingCollection();

    $html = $this->get('/carlos')->getContent();
    preg_match('#<section class="col-shelf".*?</section>#s', $html, $shelf);

    expect($shelf)->not->toBeEmpty()
        ->and(substr_count($shelf[0], '<li wire:key="shelf-'))->toBe(6)
        ->and($shelf[0])->toContain('Alpha')->toContain('Foxtrot')
        ->and($shelf[0])->not->toContain('Golf')->not->toContain('Hotel')
        // the EUR card is the priciest number but not in the page's currency
        ->and($shelf[0])->not->toContain('Euro')
        // the same tile as the grid's: one `col-card col-tier-*` link per card, no variant class
        ->and(substr_count($shelf[0], 'class="col-card col-tier-'))->toBe(6)
        ->and($shelf[0])->not->toMatch('/col-card--|col-shelf-big/');
});

test('the shelf tiles never share a wire key with the grid tiles', function () {
    movingCollection();

    $html = $this->get('/carlos')->getContent();
    preg_match_all('/wire:key="([^"]+)"/', $html, $keys);
    $tileKeys = array_filter($keys[1], fn (string $k) => str_contains($k, 'tile-') || str_starts_with($k, 'shelf-'));

    expect($tileKeys)->toHaveCount(6 + 9)
        ->and(array_unique($tileKeys))->toHaveCount(6 + 9);
});

test('the shelf is left out when the collection has no more cards than it would show', function () {
    coverPage([['Alpha', 20000, 25000], ['Bravo', 10000, 8000], ['Charlie', 9000, 9000]]);

    expect($this->get('/carlos')->getContent())->not->toContain('class="col-shelf"');
});

test('each mover row shows percent and dollars with glyph and minus sign, and links to the card', function () {
    movingCollection();

    $html = $this->get('/carlos')->getContent();
    preg_match('#<section class="col-movers".*?</section>#s', $html, $movers);
    $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($movers[0] ?? '')));
    $alpha = Card::where('name', 'Alpha')->first();

    expect($movers)->not->toBeEmpty()
        ->and($text)->toContain('Biggest drops')->toContain('Biggest rises')->toContain('7 days')
        ->and($text)->toContain('▼ −20% −$50.00')->toContain('$200.00')
        ->and($text)->toContain('▲ +25% +$20.00')
        ->and($movers[0])->toContain('href="'.route('gallery.card', ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => $alpha->local_id]).'"');
});

test('a note says cards in another currency were left out of the shelf and the movers', function () {
    movingCollection();

    $note = RevampHtml::note($this->get('/carlos')->getContent());

    expect($note)->toContain('1 card priced in another currency is left out of Top cards and the movers.');
});

test('with no card that moved enough, the strip and the movers blocks are not rendered', function () {
    coverPage(array_map(fn (int $i) => ['Flat '.$i, 1000 * $i, 1000 * $i], range(1, 8)));

    $html = $this->get('/carlos')->getContent();

    expect($html)->not->toContain('class="col-strip"')->not->toContain('class="col-movers"')
        ->and($html)->toContain('class="col-figure"');
});

test('a collection with one priced card shows its total but no strip, shelf or movers, and never $0.00', function () {
    coverPage([['Solo', 5000, 5000]]);

    $html = $this->get('/carlos')->getContent();

    expect(RevampHtml::text('col-figure', $html))->toBe('$50.00')
        ->and($html)->not->toContain('class="col-strip"')->not->toContain('class="col-shelf"')
        ->and($html)->not->toContain('class="col-movers"')
        ->and($html)->not->toContain('$0.00');
});

// The phone layout contract (390 and 360 wide) is checked in a browser; these pin the rules it rests on.
function collectionCss(): string
{
    return (string) file_get_contents(resource_path('css/collection.css'));
}

/** The rules of every media block that opens with $query. */
function mediaBlock(string $query): string
{
    preg_match_all('/@media \('.preg_quote($query, '/').'\) \{(.*?)\n\}/s', collectionCss(), $m);

    return implode("\n", $m[1]);
}

test('the figure shrinks to its column instead of relying on the token floor', function () {
    $css = collectionCss();

    expect($css)->toMatch('/\.col-cover-main \{[^}]*container-type: inline-size/')
        ->and($css)->toMatch('/\.col-figure \{[^}]*min\(var\(--text-figure\), \d+cqi\)/');
});

test('on a phone the value line goes under the figure and the drops stack above the rises', function () {
    $tablet = mediaBlock('max-width: 899px');

    expect($tablet)->toMatch('/\.col-cover \{[^}]*grid-template-columns: minmax\(0, 1fr\)/')
        ->and($tablet)->toMatch('/\.col-line-box \{[^}]*height: 96px/')
        ->and($tablet)->toMatch('/\.col-mv-grid \{[^}]*grid-template-columns: minmax\(0, 1fr\)/');
});

test('on a phone the week chips and the shelf scroll sideways and snap, and Copy link keeps a 44px target', function () {
    $phone = mediaBlock('max-width: 679px');

    expect($phone)->toMatch('/\.col-strip-row \{[^}]*scroll-snap-type: x mandatory/')
        ->and($phone)->toMatch('/\.col-shelf-row \{[^}]*grid-auto-columns: calc\(.*24px\)/')
        ->and($phone)->toMatch('/\.col-copy \{[^}]*width: 44px/')
        ->and($phone)->toMatch('/\.col-who \{[^}]*line-clamp: 2/');
});

test('a mover row keeps percent and dollars in one element and its price in a right-aligned column', function () {
    $css = collectionCss();

    expect($css)->toMatch('/\.col-mv-move \{[^}]*flex-direction: column/')
        ->and($css)->toMatch('/\.col-mv-now \{[^}]*text-align: right/');
});

test('the page loads the cap\'s cards once: the cover, the shelf, the movers and the grid share them', function () {
    movingCollection();
    $loads = 0;
    DB::listen(function ($query) use (&$loads) {
        // The cap's load is the one full-row cards query bounded by the cap (the grid's id pick selects only the id).
        if (str_starts_with($query->sql, 'select * from "cards"') && str_ends_with($query->sql, 'limit '.PublicCollection::MAX_CARDS)) {
            $loads++;
        }
    });

    $this->get('/carlos')->assertOk();

    expect($loads)->toBe(1);
});
