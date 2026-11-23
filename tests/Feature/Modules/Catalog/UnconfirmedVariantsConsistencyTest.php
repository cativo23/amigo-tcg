<?php

declare(strict_types=1);

use App\Livewire\Admin\AddCollectionItem;
use App\Livewire\Admin\CardEditor;
use App\Livewire\Admin\SetGrid;
use App\Livewire\Gallery\CardShow;
use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceRow;
use App\Modules\Catalog\Tcgcsv\TcgplayerIdentityMatcher;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Support\VariantChips;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\LaravelData\DataCollection;

/**
 * Queries the set grid and the card page ran for this fixture before they
 * read the stored list. Reading it adds a column, never a query.
 */
const UVC_SET_GRID_QUERIES = 12;
const UVC_CARD_PAGE_QUERIES = 11;

/** @return array<string, mixed> */
function uvcPlaceholderRaw(): array
{
    return ['variants_detailed' => [['type' => 'normal', 'size' => 'standard', 'variantId' => 'generated']]];
}

function uvcFixture(): Card
{
    test()->seed(PermissionSeeder::class);
    $user = User::factory()->create(['username' => 'carlos']);
    test()->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me06', 'name' => 'Delta Reign', 'card_count' => 141, 'variant_counts' => ['normal' => 0, 'holo' => 0, 'reverse' => 0, 'firstEd' => 0]]);
    DB::table('set_tcgplayer_groups')->insertOrIgnore(['set_id' => $set->id, 'group_id' => 24451]);
    $card = Card::create([
        'tcgdex_id' => 'me06-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Mega Rayquaza Cap',
        'variants' => ['normal' => true, 'holo' => false, 'reverse' => false],
        'raw' => uvcPlaceholderRaw(), 'inferred_variants' => ['normal', 'reverse-holofoil'],
    ]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me06-001', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    return $card;
}

/** @return list<string> */
function uvcQueriesOf(Closure $load): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $load();
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $queries;
}

test('the five places and the identity check give Mega Rayquaza Cap one identical list', function () {
    $card = uvcFixture();
    $expected = ['normal', 'reverse-holofoil'];

    $grid = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me06']);
    $gridList = $grid->viewData('entries')->first(fn (array $e) => $e['card']->id === $card->id)['chips'];
    $pageList = Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me06', 'localId' => '001'])->viewData('pills')->pluck('variant')->all();
    $chipsList = VariantChips::for($card->fresh(), $card->collectionItems()->get());
    $editorList = Livewire::test(CardEditor::class)->call('openCardEditor', $card->id)->get('editingAvailableVariants');

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me06-001')->andReturn(new CardDetailData(
        tcgdexId: 'me06-001', setTcgdexId: 'me06', localId: '001', name: 'Mega Rayquaza Cap', rarity: null,
        variants: ['normal' => true, 'holo' => false, 'reverse' => false], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: uvcPlaceholderRaw(),
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);
    $addList = Livewire::test(AddCollectionItem::class)->call('selectCard', 'me06-001')->get('availableVariants');

    $prices = [24451 => [900001 => ['Normal' => new TcgcsvPriceRow(24451, 900001, 'Normal', 100, 50), 'Reverse Holofoil' => new TcgcsvPriceRow(24451, 900001, 'Reverse Holofoil', 100, 50)]]];
    $identities = [24451 => [900001 => ['name' => 'Mega Rayquaza Cap -  001', 'number' => '001']]];
    $judged = array_map(fn ($o) => $o->variant, iterator_to_array(app(TcgplayerIdentityMatcher::class)->evaluate($card->fresh(), $prices, $identities), false));

    expect(array_column($gridList, 'variant'))->toBe($expected)
        ->and($pageList)->toBe($expected)
        ->and(array_column($chipsList, 'variant'))->toBe($expected)
        ->and($editorList)->toBe($expected)
        ->and($addList)->toBe($expected)
        ->and($judged)->toBe($expected);
});

test('reading the stored list adds no query to the set grid or the card page', function () {
    uvcFixture();

    $grid = uvcQueriesOf(fn () => Livewire::test(SetGrid::class, ['setTcgdexId' => 'me06']));
    $page = uvcQueriesOf(fn () => Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me06', 'localId' => '001']));

    expect(count($grid))->toBe(UVC_SET_GRID_QUERIES)
        ->and(count($page))->toBe(UVC_CARD_PAGE_QUERIES);
});
