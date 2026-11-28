<?php

declare(strict_types=1);

use App\Livewire\Gallery\Show;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Services\WeekBaselines;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionTotal;
use App\Modules\Collection\Services\PublicCollection;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\PriceRowReads;
use Tests\Support\RevampHtml;

test('shows only owned cards by default, computes stats from the whole set', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $owned = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $notOwned = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $owned->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    CardPriceSnapshot::create(['card_id' => $owned->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5000]);
    CardPriceSnapshot::create(['card_id' => $notOwned->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);

    $response = $this->get('/carlos/me05');

    $response->assertOk();
    $response->assertSee('Mega Darkrai ex'); // owned, shown
    $response->assertDontSee('Fomantis'); // not owned — hidden by default (missing cards are opt-in)
    expect(RevampHtml::summary($response->getContent()))->toBe('ME05 · 1 of 2 cards · $50.00'); // the whole set, unfiltered
    // Swapping the result count with no announcement told assistive tech
    // nothing happened when the search box actually changed the grid.
    $response->assertSee('role="status"', false);
});

test('owning even one card out of a huge set never rounds the progress bar down to 0%', function () {
    // Same rounding bug as Sets.php, on the set-detail page's own progress
    // bar/percentage text.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me02.5', 'name' => 'Ascended Heroes', 'card_count' => 217]);
    $card = Card::create(['tcgdex_id' => 'me02.5-123', 'set_id' => $set->id, 'local_id' => '123', 'name' => 'Gastly']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me02.5-123', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/me02.5');

    $response->assertOk();
    $html = $response->getContent();
    expect(RevampHtml::summary($html))->toBe('ME02.5 · 1 of 217 cards')
        ->and($html)->toContain('<span class="set-strip-pct" aria-hidden="true">1%</span>')
        ->and($html)->toContain('aria-label="1% of the set collected"')
        ->and(RevampHtml::stripSlots($html))->toHaveCount(217);
});

test('toggling "show missing" reveals cards the collector does not own', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $owned = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $owned->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->assertDontSee('Fomantis')
        ->call('toggleMissing')
        ->assertSee('Fomantis')
        ->call('toggleMissing')
        ->assertDontSee('Fomantis');
});

test('a private (non-public) collection contributes nothing to the set-detail screen', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'private']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'secret.jpg']);

    $response = $this->get('/carlos/me05');

    // The set only exists in this user's gallery via a public collection
    // — with none, the same existence-gate that hides an untouched set
    // must also hide a set touched only through a private one, and the
    // private item's photo must never leak into the response either way.
    $response->assertNotFound();
    $response->assertDontSee('secret.jpg', false);
});

test('the set-detail route requires no authentication', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    // Explicitly NOT calling $this->actingAs(...) — a guest must be able to load this.
    $response = $this->get('/carlos/me05');

    $response->assertOk();
});

test('a set that exists but the user has never touched 404s', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'untouched', 'name' => 'Never Added']);

    $response = $this->get('/carlos/untouched');

    $response->assertNotFound();
});

test('shows the official art on the tile even when the copy is photographed', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'official_image_url' => 'https://official.example/card.webp']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'my-photo.jpg']);

    $response = $this->get('/carlos/me05');

    $response->assertSee('https://official.example/card.webp', false);
    $response->assertDontSee(Storage::disk('collection-photos')->url('my-photo.jpg'), false);
});

test('search filters the card grid by name', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card1 = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $card2 = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    // mount()'s existence-gate (borrowed from Task 4) only shows a set the
    // user has touched — without this, the set 404s before search is ever
    // exercised.
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card1->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->set('search', 'Darkrai')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Fomantis');
});

test('search is case-insensitive', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-003', 'condition' => 'NM', 'quantity' => 1]);

    // Postgres' LIKE is case-sensitive by default — a lowercase search
    // must still find a card whose stored name is capitalized.
    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->set('search', 'fomantis')
        ->assertSee('Fomantis');
});

test('rarity filter only shows cards of the selected rarity', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card1 = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'rarity' => 'SIR']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis', 'rarity' => 'Common']);

    // Same existence-gate note as the search test above.
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card1->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->set('rarityFilter', 'SIR')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Fomantis');
});

test('tcgdex\'s literal "None" rarity never becomes a selectable filter option', function () {
    // Regression: whereNotNull('rarity') only excludes a real NULL —
    // tcgdex's literal string "None" (promos/unrated prints) survived
    // as a selectable-but-blank-labeled option (Rarity::label('None')
    // is already ''), and picking it matched only cards literally rated
    // "None", hiding every properly-rated card in the set.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 3]);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'rarity' => 'Special illustration rare']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis', 'rarity' => 'Common']);
    Card::create(['tcgdex_id' => 'me05-200', 'set_id' => $set->id, 'local_id' => '200', 'name' => 'Pikachu on the Ball', 'rarity' => 'None']);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/me05');

    $response->assertOk();
    $response->assertSee('value="Special illustration rare"', false);
    $response->assertSee('value="Common"', false);
    $response->assertDontSee('value="None"', false);
    $response->assertDontSee('value="none"', false);
});

test('the set page falls back to the local card count when tcgdex reports zero', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 0]);
    $owned = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $owned->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get('/carlos/me05')->assertOk()->getContent();

    // 'of 0 cards' is what a tcgdex total of 0 would print: it must never reach the copy.
    expect(RevampHtml::summary($html))->toBe('ME05 · 1 of 2 cards')
        ->and(RevampHtml::summary($html))->not->toContain('of 0 cards')
        ->and($html)->toContain('<span class="set-strip-pct" aria-hidden="true">50%</span>');
});

test('the owner viewing their own public set page gets a link into the set grid', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $this->actingAs($user)->get('/carlos/me05')
        ->assertOk()
        ->assertSee('Edit in my collection')
        ->assertSee(route('admin.sets.show', ['setTcgdexId' => 'me05']), false);
});

test('a visitor, signed in or not, does not get the edit link', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $this->get('/carlos/me05')->assertOk()->assertDontSee('Edit in my collection');

    $this->actingAs(User::factory()->create(['username' => 'ash']))
        ->get('/carlos/me05')->assertOk()->assertDontSee('Edit in my collection');

    // Neither visitor gets the owner's bar link either (the layout is tested alone; this is the page).
    $this->get('/carlos/me05')->assertOk()->assertDontSee('Manage collection');
    $this->actingAs(User::factory()->create(['username' => 'bea']))->get('/carlos/me05')->assertOk()->assertDontSee('Manage collection');
});

test('an owner without the use-collection permission gets no link into the set grid', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create(['username' => 'carlos']);
    $user->syncRoles([]);
    $user->syncPermissions([]);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $this->actingAs($user)->get('/carlos/me05')->assertOk()->assertDontSee('Edit in my collection');
});

/**
 * The demo's 151 in miniature: five cards, three of them owned (001, 003,
 * 005), USD prices on 003 ($24.99), 005 ($1.50) and the missing 002 ($0.75).
 *
 * @param  array<string, mixed>  $setAttributes
 * @return array{user: User, collection: Collection, set: Set, cards: array<string, Card>}
 */
function seedSetPage(array $setAttributes = []): array
{
    $user = User::factory()->create(['username' => 'demo', 'name' => 'Demo']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create([
        'tcgdex_id' => 'sv03.5', 'name' => '151', 'series' => 'Scarlet & Violet', 'abbreviation' => 'MEW',
        'card_count' => 5, 'released_on' => '2023-09-22', 'logo_url' => 'https://assets.tcgdex.net/en/sv/sv03.5/logo.png',
        ...$setAttributes,
    ]);

    $cards = [];
    foreach ([['001', 'Bulbasaur', 'Common'], ['002', 'Ivysaur', 'Uncommon'], ['003', 'Venusaur ex', 'Double rare'], ['004', 'Charmander', 'Common'], ['005', 'Charmeleon', 'Uncommon']] as [$number, $name, $rarity]) {
        $cards[$number] = Card::create([
            'tcgdex_id' => "sv03.5-$number", 'set_id' => $set->id, 'local_id' => $number, 'name' => $name, 'rarity' => $rarity,
            'official_image_url' => "https://assets.tcgdex.net/en/sv/sv03.5/$number/high.webp",
        ]);
    }

    foreach (['001', '003', '005'] as $number) {
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $cards[$number]->id, 'card_tcgdex_id' => "sv03.5-$number", 'condition' => 'NM', 'quantity' => 1]);
    }

    foreach (['003' => 2499, '005' => 150, '002' => 75] as $number => $minor) {
        CardPriceSnapshot::create(['card_id' => $cards[$number]->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => $minor]);
    }

    return compact('user', 'collection', 'set', 'cards');
}

test('the strip, the counts and the missing number come from the whole set, whatever the grid shows', function () {
    seedSetPage();

    $page = Livewire::withQueryParams(['search' => 'Bulba'])->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5']);

    expect($page->viewData('entries'))->toHaveCount(1)
        ->and($page->viewData('ownedCount'))->toBe(3)
        ->and($page->viewData('totalCount'))->toBe(5)
        ->and($page->viewData('missingCount'))->toBe(2)
        ->and($page->viewData('percent'))->toBe(60)
        ->and(array_column($page->viewData('strip'), 'state'))->toBe(['owned', 'missing', 'owned', 'missing', 'owned'])
        ->and(array_column($page->viewData('strip'), 'number'))->toBe(['001', '002', '003', '004', '005'])
        ->and($page->viewData('ticks'))->toBe([['label' => 1, 'left' => '0.00'], ['label' => 5, 'left' => '100.00']])
        ->and($page->viewData('ownedTotals'))->toBe(['USD' => 2649])
        ->and($page->viewData('isFiltered'))->toBeTrue();
});

test('a set the catalogue holds only in part counts its missing cards from what is imported, and the rest is absent from the strip', function () {
    // 5 of 7 cards imported, 3 owned: the toggle can reveal 2 pockets (imported - owned, not 7 - 3), the other 2 are grey slots.
    seedSetPage(['card_count' => 7]);

    $page = Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5']);

    expect($page->viewData('totalCount'))->toBe(7)
        ->and($page->viewData('missingCount'))->toBe(2)
        ->and(array_column($page->viewData('strip'), 'state'))->toBe(['owned', 'missing', 'owned', 'missing', 'owned', 'absent', 'absent'])
        ->and(array_column($page->viewData('strip'), 'number'))->toBe(['001', '002', '003', '004', '005', null, null]);
});

test('one imported card, owned, out of a huge set leaves nothing for the toggle to reveal', function () {
    $user = User::factory()->create(['username' => 'demo', 'name' => 'Demo']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'swsh11', 'name' => 'Lost Origin', 'card_count' => 217]);
    $card = Card::create(['tcgdex_id' => 'swsh11-123', 'set_id' => $set->id, 'local_id' => '123', 'name' => 'Gastly']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'swsh11-123', 'condition' => 'NM', 'quantity' => 1]);

    $page = Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'swsh11']);
    $states = array_count_values(array_column($page->viewData('strip'), 'state'));

    expect($page->viewData('ownedCount'))->toBe(1)
        ->and($page->viewData('totalCount'))->toBe(217)
        ->and($page->viewData('missingCount'))->toBe(0)
        ->and($states)->toBe(['absent' => 216, 'owned' => 1]);
});

test('the set code is the abbreviation, else the uppercase tcgdex id', function () {
    $seed = seedSetPage();

    expect(Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])->viewData('setCode'))->toBe('MEW');

    $seed['set']->update(['abbreviation' => null]);

    expect(Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])->viewData('setCode'))->toBe('SV03.5');
});

test('each owned card carries its 7-day move; a missing card carries none', function () {
    $this->freezeTime();
    $seed = seedSetPage();
    CardPriceSnapshot::create(['card_id' => $seed['cards']['003']->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(7), 'currency' => 'USD', 'market_minor' => 2399]);
    CardPriceSnapshot::create(['card_id' => $seed['cards']['002']->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(7), 'currency' => 'USD', 'market_minor' => 50]);
    app(WeekBaselines::class)->refreshAll();

    $entries = Livewire::withQueryParams(['missing' => true])->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])
        ->viewData('entries')->keyBy(fn (array $e) => $e['card']->local_id);

    expect($entries['003']['weekDelta']?->deltaMinor)->toBe(100)
        ->and($entries['002']['weekDelta'])->toBeNull()
        ->and($entries['001']['weekDelta'])->toBeNull();
});

test('Clear drops the search and the rarity, and keeps the order and the missing toggle', function () {
    seedSetPage();

    Livewire::withQueryParams(['search' => 'saur', 'rarity' => 'Common', 'sort' => 'name', 'missing' => true])
        ->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('rarityFilter', '')
        ->assertSet('sort', 'name')
        ->assertSet('showMissing', true);
});

test('the rarity filter lists the set\'s rarities from commonest to rarest', function () {
    seedSetPage();

    $page = Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5']);
    $html = $page->html();

    expect($page->viewData('rarities')->all())->toBe(['Common', 'Uncommon', 'Double rare'])
        ->and(strpos($html, '<option value="Common">'))->toBeLessThan(strpos($html, '<option value="Uncommon">'))
        ->and(strpos($html, '<option value="Uncommon">'))->toBeLessThan(strpos($html, '<option value="Double rare">'));
});

test('the set page costs the same number of queries for five cards as for thirty, searched or not, with or without pockets', function (array $query, int $fewShown, int $manyShown) {
    $user = User::factory()->create(['username' => 'demo']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'sv03.5', 'name' => '151', 'card_count' => 30]);
    $add = function (int $from, int $to) use ($collection, $set) {
        foreach (range($from, $to) as $i) {
            $number = sprintf('%03d', $i);
            $card = Card::create(['tcgdex_id' => "sv03.5-$number", 'set_id' => $set->id, 'local_id' => $number, 'name' => "Card $number"]);
            // Every fifth card stays unowned, so the pocket path runs when the missing cards are shown.
            if ($i % 5 !== 0) {
                CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
            }
            // Ten daily rows rising one cent a day: every card's 7-day move is +7.
            foreach (range(0, 9) as $d) {
                CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays($d), 'currency' => 'USD', 'market_minor' => 1000 + $i - $d]);
            }
        }
    };
    // Measured cold both times, so a cache hit on one side cannot hide a per-card read on the other.
    $queriesFor = function () use ($query): array {
        Cache::flush();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $entries = Livewire::withQueryParams($query)->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])->viewData('entries');

        return [$count, $entries];
    };

    // Card 5 is unowned in the small set too: were every card owned, the collection total would share the
    // set's own price-row cache entry and save a query only there, which is not a per-card read.
    $add(1, 5);
    app(WeekBaselines::class)->refreshAll();
    [$few, $fewEntries] = $queriesFor();
    $add(6, 30);
    app(WeekBaselines::class)->refreshAll();
    [$many, $manyEntries] = $queriesFor();

    expect($fewEntries)->toHaveCount($fewShown)
        ->and($manyEntries)->toHaveCount($manyShown)
        ->and($manyEntries->filter(fn (array $e) => $e['owned'])->every(fn (array $e) => $e['weekDelta']?->deltaMinor === 7))->toBeTrue()
        ->and($many)->toBe($few);
})->with([
    'unfiltered' => [[], 4, 24],
    'searched' => [['search' => 'Card'], 4, 24],
    'missing shown' => [['missing' => true], 5, 30],
]);

test('the set page sits on the revamp shell: Sets is current, its own stylesheets only, indexable', function () {
    seedSetPage();

    $html = $this->get('/demo/sv03.5')->assertOk()->getContent();
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    expect(RevampHtml::currentNav($html))->toBe('Sets')
        ->and($html)->toContain('<nav class="bar-nav" aria-label="Gallery">')
        ->and($html)->toContain('class="col-page set-page"')
        ->and($html)->toContain($manifest['resources/css/revamp.css']['file'])
        ->and($html)->toContain($manifest['resources/css/collection.css']['file'])
        ->and($html)->toContain($manifest['resources/css/set.css']['file'])
        ->and($html)->not->toContain($manifest['resources/css/app.css']['file'])
        ->and($html)->not->toContain('noindex');
});

test('the breadcrumb leads back to the sets, names the series, and marks the set as the current page', function () {
    $seed = seedSetPage();

    $html = $this->get('/demo/sv03.5')->getContent();
    expect($html)->toContain('<nav class="set-crumbs" aria-label="Breadcrumb">')
        ->and($html)->toContain('<li><a href="'.route('gallery.sets', ['username' => 'demo']).'">Sets</a></li>')
        ->and($html)->toContain('<li>Scarlet &amp; Violet</li>')
        ->and($html)->toContain('<li aria-current="page">151</li>');

    $seed['set']->update(['series' => null]);
    expect($this->get('/demo/sv03.5')->getContent())->not->toContain('<li>Scarlet');
});

test('the head is the set\'s name and logo over one summary line that starts with its code, with no stat boxes', function () {
    seedSetPage();

    $html = $this->get('/demo/sv03.5')->getContent();

    expect($html)->toContain('<h1 id="set-name" class="set-name">151</h1>')
        ->and($html)->toContain('<img class="set-logo" src="https://assets.tcgdex.net/en/sv/sv03.5/logo.png" alt="" decoding="async" data-optional>')
        ->and($html)->toContain('<p class="col-summary"><span class="set-code">MEW</span>')
        ->and(RevampHtml::summary($html))->toBe('MEW · 3 of 5 cards · $26.49 · released Sep 2023')
        ->and(RevampHtml::note($html))->toBe('Approx. TCGplayer market prices.')
        ->and($html)->not->toContain('Most valuable owned')
        ->and($html)->not->toContain('nw-stats');
});

test('the code in the summary is the abbreviation, else the uppercase tcgdex id', function () {
    $seed = seedSetPage();
    $seed['set']->update(['abbreviation' => null]);

    expect(RevampHtml::summary($this->get('/demo/sv03.5')->getContent()))->toStartWith('SV03.5 · 3 of 5 cards');
});

test('the summary drops the value without a price and the release without a date', function (Closure $change, string $summary) {
    $seed = seedSetPage();
    $change($seed);

    expect(RevampHtml::summary($this->get('/demo/sv03.5')->getContent()))->toBe($summary);
})->with([
    'no price' => [fn (array $seed) => CardPriceSnapshot::query()->delete(), 'MEW · 3 of 5 cards · released Sep 2023'],
    'no release date' => [fn (array $seed) => $seed['set']->update(['released_on' => null]), 'MEW · 3 of 5 cards · $26.49'],
    'neither' => [function (array $seed) {
        CardPriceSnapshot::query()->delete();
        $seed['set']->update(['released_on' => null]);
    }, 'MEW · 3 of 5 cards'],
]);

test('another currency is never added in: it moves to a note under the summary', function () {
    $seed = seedSetPage();
    CardPriceSnapshot::create(['card_id' => $seed['cards']['001']->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1000]);

    $html = $this->get('/demo/sv03.5')->getContent();

    expect(RevampHtml::summary($html))->toBe('MEW · 3 of 5 cards · $26.49 · released Sep 2023')
        ->and(RevampHtml::note($html))->toBe('Approx. TCGplayer market prices. Also €10.00 priced in EUR, not added to the total.');
});

test('the completion bar is a printed strip: one slot per card in number order, the missing ones hollow', function () {
    seedSetPage();

    $html = $this->get('/demo/sv03.5')->getContent();

    expect(RevampHtml::stripSlots($html))->toBe(['owned', 'missing', 'owned', 'missing', 'owned'])
        ->and($html)->toContain('<div class="set-strip-bar" role="progressbar" aria-valuemin="0" aria-valuemax="5" aria-valuenow="3" aria-label="60% of the set collected">')
        ->and($html)->toContain('<span class="set-strip-miss" title="#002 missing"></span>')
        ->and($html)->toContain('<span class="set-strip-pct" aria-hidden="true">60%</span>')
        ->and($html)->toContain('<b style="left: 0.00%">1</b>')
        ->and($html)->toContain('<b style="left: 100.00%">5</b>');
});

test('a set the catalogue holds only in part still spans the whole set: grey, unnumbered slots for the cards it has not imported', function () {
    seedSetPage(['card_count' => 7]);

    $html = $this->get('/demo/sv03.5')->getContent();

    expect(RevampHtml::stripSlots($html))->toBe(['owned', 'missing', 'owned', 'missing', 'owned', 'absent', 'absent'])
        ->and($html)->toContain('<span class="set-strip-off" title="not in the catalogue yet"></span>')
        ->and($html)->toContain('<span class="set-strip-miss" title="#004 missing"></span>')
        ->and(RevampHtml::summary($html))->toStartWith('MEW · 3 of 7 cards');
});

test('the owner gets Edit in my collection as a plain link into the set grid, and Manage collection in the bar', function () {
    $this->seed(PermissionSeeder::class);
    $seed = seedSetPage();

    $html = $this->actingAs($seed['user'])->get('/demo/sv03.5')->assertOk()->getContent();

    expect($html)->toContain('<a class="set-edit" href="'.route('admin.sets.show', ['setTcgdexId' => 'sv03.5']).'">Edit in my collection</a>')
        ->and($html)->toContain('Manage collection');
});

test('title, description and share image stay as they were', function () {
    seedSetPage();

    $this->get('/demo/sv03.5')
        ->assertSee("151 · Demo's collection · AMIGO TCG")
        ->assertSee('content="3 of 5 cards from 151 (Scarlet &amp; Violet) in Demo&#039;s Pokémon TCG collection."', false)
        ->assertSee('content="https://assets.tcgdex.net/en/sv/sv03.5/003/high.webp"', false);
});

test('the missing cards stay hidden by default behind a toggle that says how many there are', function () {
    seedSetPage();

    $page = Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5']);
    expect($page->html())->toContain('aria-pressed="false"><span class="set-mt-box" aria-hidden="true"></span>Show the 2 missing</button>')
        ->and($page->html())->not->toContain('Ivysaur');

    $page->call('toggleMissing');
    expect($page->html())->toContain('aria-pressed="true"><span class="set-mt-box" aria-hidden="true"></span>Hide the 2 missing</button>')
        ->and($page->html())->toContain('Ivysaur');
});

test('a complete set has no missing toggle', function () {
    $seed = seedSetPage();

    // Positive control: with two cards missing the toggle is there (this half is what is red before the task).
    expect($this->get('/demo/sv03.5')->getContent())->toContain('class="set-miss-toggle"');

    foreach (['002', '004'] as $number) {
        CollectionItem::create(['collection_id' => $seed['collection']->id, 'card_id' => $seed['cards'][$number]->id, 'card_tcgdex_id' => "sv03.5-$number", 'condition' => 'NM', 'quantity' => 1]);
    }

    expect($this->get('/demo/sv03.5')->getContent())->not->toContain('set-miss-toggle');
});

test('on a set the catalogue holds only in part, the toggle counts the imported gaps and ignores the cards not imported', function () {
    // card_count 217, 5 imported, 3 owned: "Show the 2 missing" (not 214), and the other 212 positions are grey slots.
    seedSetPage(['card_count' => 217]);

    $html = $this->get('/demo/sv03.5')->getContent();

    expect($html)->toContain('Show the 2 missing</button>')
        ->and(array_count_values(RevampHtml::stripSlots($html)))->toBe(['owned' => 3, 'missing' => 2, 'absent' => 212])
        ->and(RevampHtml::summary($html))->toStartWith('MEW · 3 of 217 cards');
});

test('one imported card, owned, out of a huge set has no toggle until another imported card is missing', function () {
    $user = User::factory()->create(['username' => 'demo', 'name' => 'Demo']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'swsh11', 'name' => 'Lost Origin', 'card_count' => 217]);
    $card = Card::create(['tcgdex_id' => 'swsh11-123', 'set_id' => $set->id, 'local_id' => '123', 'name' => 'Gastly']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'swsh11-123', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get('/demo/swsh11')->getContent();

    expect(RevampHtml::summary($html))->toBe('SWSH11 · 1 of 217 cards')
        ->and(array_count_values(RevampHtml::stripSlots($html)))->toBe(['absent' => 216, 'owned' => 1])
        ->and($html)->not->toContain('set-miss-toggle');

    // Positive control: one more imported card the collector does not own, and the toggle appears.
    Card::create(['tcgdex_id' => 'swsh11-124', 'set_id' => $set->id, 'local_id' => '124', 'name' => 'Haunter']);

    expect($this->get('/demo/swsh11')->getContent())->toContain('Show the 1 missing</button>');
});

test('shown, the missing cards sit in number order as empty pockets with their number pencilled in', function () {
    $seed = seedSetPage();

    $html = Livewire::withQueryParams(['missing' => true])->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])->html();
    preg_match_all('#<li wire:key="(?:tile|miss)-(\d+)"#', $html, $keys);

    expect(array_map('intval', $keys[1]))->toBe(collect($seed['cards'])->map(fn (Card $card) => $card->id)->values()->all())
        ->and(substr_count($html, 'class="col-slot set-miss col-enter"'))->toBe(2)
        // A pocket links to the card page, as the old ghost tile did.
        ->and($html)->toContain('<a href="'.route('gallery.card', ['username' => 'demo', 'setTcgdexId' => 'sv03.5', 'localId' => '002']).'" class="set-pocket"')
        ->and($html)->toContain('<span class="set-pencil" aria-hidden="true">002</span>')
        ->and($html)->toContain('<span class="set-gprice" aria-hidden="true">$0.75</span>');
});

test('owned cards are the collection\'s tiles, numbered within the set, with the 7-day move', function () {
    $this->freezeTime();
    $seed = seedSetPage();
    CardPriceSnapshot::create(['card_id' => $seed['cards']['003']->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(7), 'currency' => 'USD', 'market_minor' => 2399]);
    app(WeekBaselines::class)->refreshAll();

    $html = $this->get('/demo/sv03.5')->getContent();

    expect($html)->toContain('<span class="col-mono">#003</span>')
        ->and($html)->toContain("<span class=\"col-delta price-move price-move--up\">\u{25B2} +\$1.00 · 7d</span>")
        ->and($html)->toContain('aria-label="Venusaur ex, #003, $24.99, up $1.00 in 7 days"')
        ->and($html)->toContain('<a href="'.route('gallery.card', ['username' => 'demo', 'setTcgdexId' => 'sv03.5', 'localId' => '003']).'" class="col-card col-tier-silver"');
});

test('every owned card takes a single pocket in any order, the priciest included', function (array $query) {
    seedSetPage();

    $html = Livewire::withQueryParams($query)->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])->html();

    expect($html)->not->toContain('col-lead')
        ->and($html)->not->toContain('col-string')
        ->and($html)->toContain('src="https://assets.tcgdex.net/en/sv/sv03.5/003/low.webp"');
})->with([
    'value order' => [['sort' => 'value']],
    'value order, missing shown' => [['sort' => 'value', 'missing' => true]],
    'number order' => [[]],
]);

test('Value order still puts the priciest owned card first', function () {
    seedSetPage();

    $value = Livewire::withQueryParams(['sort' => 'value'])->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])->html();

    expect(strpos($value, 'Venusaur ex'))->toBeLessThan(strpos($value, 'Charmeleon'));
});

test('Clear shows only while a search or rarity narrows the grid', function () {
    seedSetPage();

    $page = Livewire::withQueryParams(['search' => 'saur'])->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5']);
    expect($page->html())->toContain('<button type="button" wire:click="clearFilters" class="col-clear">Clear</button>');

    $page->call('clearFilters');
    expect($page->html())->not->toContain('wire:click="clearFilters"');
});

test('a search matching nothing shows an empty binder page with the approved copy', function () {
    seedSetPage();

    $html = Livewire::withQueryParams(['search' => 'Mewtwo'])->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])->html();

    expect($html)->toContain('<div class="col-pockets" aria-hidden="true"><i></i><i></i><i></i></div>')
        ->and($html)->toContain('<h2 class="col-empty-t">No cards match</h2>')
        ->and($html)->toContain('<p>Nothing in 151 matches that search.</p>');
});

test('the binder is the revamp\'s own: no old classes, no client-side navigation, the collection script for the tiles', function () {
    $this->seed(PermissionSeeder::class);
    $seed = seedSetPage();

    $html = $this->actingAs($seed['user'])->get('/demo/sv03.5?missing=1')->assertOk()->getContent();
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    expect($html)->not->toContain('class="nw-')
        ->and($html)->not->toContain('wire:navigate')
        ->and($html)->toContain('<span class="set-holes" aria-hidden="true"><i></i><i></i><i></i></span>')
        ->and($html)->toContain($manifest['resources/js/collection.js']['file']);
});

test('a set with no price owned shows no note', function () {
    seedSetPage();
    CardPriceSnapshot::query()->delete();

    expect(RevampHtml::note($this->get('/demo/sv03.5')->getContent()))->toBe('');
});

test('under value, an owned print with no price of its own sorts after the priced owned tiles and before the missing pockets', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 3]);

    $gengar = Card::create(['tcgdex_id' => 'me05-056', 'set_id' => $set->id, 'local_id' => '056', 'name' => 'Mega Gengar ex']);
    $inkay = Card::create(['tcgdex_id' => 'me05-051', 'set_id' => $set->id, 'local_id' => '051', 'name' => 'Inkay']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $gengar->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 177]);
    CardPriceSnapshot::create(['card_id' => $inkay->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 11]);
    CardPriceSnapshot::create(['card_id' => $darkrai->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5000]);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $gengar->id, 'card_tcgdex_id' => 'me05-056', 'variant' => 'holofoil:masterball', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $inkay->id, 'card_tcgdex_id' => 'me05-051', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::withQueryParams(['sort' => 'value', 'missing' => true])
        ->test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->assertViewHas('entries', fn ($entries) => $entries->pluck('card.name')->all() === ['Inkay', 'Mega Gengar ex', 'Mega Darkrai ex']
            && $entries[1]['snapshot'] === null);
});

/** The missing cards' total line under the summary as visible text; '' when absent. */
function restOfSet(string $html): string
{
    return RevampHtml::text('col-summary set-rest', $html);
}

test('the missing cards\' total is the sum of their pockets\' prices, the same with the pockets hidden or a search on', function () {
    $seed = seedSetPage();
    CardPriceSnapshot::create(['card_id' => $seed['cards']['004']->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 300]);
    $page = fn (array $query) => Livewire::withQueryParams($query)->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5']);

    $shown = $page(['missing' => true]);
    $pockets = $shown->viewData('entries')->reject(fn (array $e) => $e['owned'])->sum(fn (array $e) => $e['snapshot']->market_minor);

    expect($pockets)->toBe(375)
        ->and($shown->viewData('missingTotals'))->toBe(['totals' => ['USD' => 375], 'priced' => ['USD' => 2]])
        ->and($shown->viewData('missingSlots'))->toBe(2)
        ->and($page([])->viewData('missingTotals'))->toBe($shown->viewData('missingTotals'))
        ->and($page(['search' => 'Bulba', 'missing' => true])->viewData('missingTotals'))->toBe($shown->viewData('missingTotals'))
        ->and(restOfSet($this->get('/demo/sv03.5')->getContent()))->toBe('The rest of the set: $3.75 for the 2 missing cards, one of each.');
});

test('the missing cards\' total names how many of them have a price when some do not', function () {
    seedSetPage(); // #002 is priced, #004 is not

    $html = $this->get('/demo/sv03.5')->getContent();

    expect(restOfSet($html))->toBe('The rest of the set: $0.75 for one of each missing card. 1 of 2 missing cards priced.')
        ->and(RevampHtml::note($html))->toBe('Approx. TCGplayer market prices.');
});

test('one missing card reads as one card', function () {
    $seed = seedSetPage();
    CollectionItem::create(['collection_id' => $seed['collection']->id, 'card_id' => $seed['cards']['004']->id, 'card_tcgdex_id' => 'sv03.5-004', 'condition' => 'NM', 'quantity' => 1]);

    expect(restOfSet($this->get('/demo/sv03.5')->getContent()))->toBe('The rest of the set: $0.75 for the 1 missing card.');
});

test('there is no missing cards\' total when nothing is missing or no missing card has a price', function (Closure $change) {
    $seed = seedSetPage();
    $change($seed);

    expect(restOfSet($this->get('/demo/sv03.5')->getContent()))->toBe('');
})->with([
    'nothing missing' => [function (array $seed) {
        foreach (['002', '004'] as $number) {
            CollectionItem::create(['collection_id' => $seed['collection']->id, 'card_id' => $seed['cards'][$number]->id, 'card_tcgdex_id' => "sv03.5-$number", 'condition' => 'NM', 'quantity' => 1]);
        }
    }],
    'no missing card priced' => [fn (array $seed) => CardPriceSnapshot::where('card_id', $seed['cards']['002']->id)->delete()],
]);

test('on a set the catalogue holds only in part, the cards not imported count as missing and unpriced', function () {
    // 5 of 8 imported, 3 owned: 5 strip slots are not owned, 2 of them imported and priced.
    $seed = seedSetPage(['card_count' => 8]);
    CardPriceSnapshot::create(['card_id' => $seed['cards']['004']->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 300]);

    $page = Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5']);

    expect($page->viewData('missingSlots'))->toBe(5)
        ->and($page->viewData('missingTotals'))->toBe(['totals' => ['USD' => 375], 'priced' => ['USD' => 2]])
        ->and(restOfSet($this->get('/demo/sv03.5')->getContent()))->toBe('The rest of the set: $3.75 for one of each missing card. 2 of 5 missing cards priced.');
});

test('with nothing owned priced, the missing cards\' total still carries the page\'s price note', function () {
    $seed = seedSetPage();
    CardPriceSnapshot::whereIn('card_id', [$seed['cards']['003']->id, $seed['cards']['005']->id])->delete();

    $html = $this->get('/demo/sv03.5')->getContent();

    expect(RevampHtml::summary($html))->toBe('MEW · 3 of 5 cards · released Sep 2023')
        ->and(restOfSet($html))->toBe('The rest of the set: $0.75 for one of each missing card. 1 of 2 missing cards priced.')
        ->and(RevampHtml::note($html))->toBe('Approx. TCGplayer market prices.');
});

test('a missing card priced in another currency is a separate footnote, never added to the total or counted in its cards', function () {
    $seed = seedSetPage();
    CardPriceSnapshot::create(['card_id' => $seed['cards']['004']->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $seed['cards']['001']->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 400]);

    $html = $this->get('/demo/sv03.5')->getContent();

    // The dollar figure is #002 alone: #004 is priced only in EUR, so it is not one of the cards the figure covers.
    expect(restOfSet($html))->toBe('The rest of the set: $0.75 for one of each missing card. 1 of 2 missing cards priced.')
        ->and(RevampHtml::note($html))->toBe('Approx. TCGplayer market prices. Also €4.00 priced in EUR, not added to the total. Also €10.00 for missing cards priced in EUR, not added to the total.');
});

/**
 * One set, one owned USD-priced card per entry of $dollars, plus a missing card priced at $900 so the owned value
 * is never confused with the set's whole price.
 *
 * @param  list<int>  $dollars
 */
function seedOwnedSetWorth(array $dollars): void
{
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 50]);

    foreach ($dollars as $i => $dollar) {
        $card = Card::create(['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) ($i + 1), 'name' => "Owned {$i}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$i}", 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => $dollar * 100]);
    }

    $missing = Card::create(['tcgdex_id' => 'me05-x', 'set_id' => $set->id, 'local_id' => '49', 'name' => 'Missing Chase']);
    CardPriceSnapshot::create(['card_id' => $missing->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 90000]);
}

test('shows the owned value of the set as the headline figure, never the missing cards', function () {
    seedOwnedSetWorth([50, 10]);

    expect(RevampHtml::text('set-figure', $this->get('/carlos/me05')->getContent()))->toBe('$60.00');
});

test('shows no headline figure when nothing in the set is priced', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 5]);
    $card = Card::create(['tcgdex_id' => 'me05-1', 'set_id' => $set->id, 'local_id' => '1', 'name' => 'Unpriced']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-1', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    expect($this->get('/carlos/me05')->getContent())->not->toContain('set-figure');
});

test('states one fact when a single card is over half of the set value', function () {
    seedOwnedSetWorth([50, 10]);

    expect(RevampHtml::text('set-callout', $this->get('/carlos/me05')->getContent()))->toBe('Owned 0 is 83% of the set value.');
});

test('states no fact when no card is over half, or when only one card is priced', function () {
    seedOwnedSetWorth([10, 10, 10]);
    expect($this->get('/carlos/me05')->getContent())->not->toContain('set-callout');
});

test('states no fact for a set with a single priced card', function () {
    seedOwnedSetWorth([10]);
    expect($this->get('/carlos/me05')->getContent())->not->toContain('set-callout');
});

test('shows a shelf of the priciest owned cards when the set holds more than the shelf', function () {
    seedOwnedSetWorth([1, 2, 3, 4, 5, 6, 7]);

    $html = $this->get('/carlos/me05')->getContent();

    expect(RevampHtml::text('col-sec-t', $html))->toBe('Top cards by value')
        ->and($html)->toContain('col-shelf-row');
    preg_match('#col-shelf-row.*?</ol>#s', $html, $m);
    preg_match_all('/Owned \d/', strip_tags($m[0]), $names);
    expect(array_values(array_unique($names[0])))->toBe(['Owned 6', 'Owned 5', 'Owned 4', 'Owned 3']);
});

test('shows no shelf when the grid already shows every owned card the shelf would', function () {
    seedOwnedSetWorth([1, 2, 3, 4]);

    expect($this->get('/carlos/me05')->getContent())->not->toContain('col-shelf-row');
});

test('the set page reads the set\'s price rows once, and the collection total from its cache', function () {
    seedOwnedSetWorth([1, 2, 3, 4, 5, 6, 7]);
    $public = PublicCollection::for(User::where('username', 'carlos')->first());
    app(CollectionTotal::class)->refresh($public);
    // The price rows' own cache would hide a rebuild of the total; only the stored total can answer without a query.
    Cache::forget(DecidingPriceRows::cacheKey(Card::pluck('id')->all()));

    $reads = PriceRowReads::during(fn () => $this->get('/carlos/me05')->assertOk());

    expect($reads->deciding)->toHaveCount(1)->and($reads->stray)->toBe([]);
});

test('a cold collection total costs the set page one more deciding read, once', function () {
    seedOwnedSetWorth([1, 2, 3, 4, 5, 6, 7]);
    Cache::flush();

    $cold = PriceRowReads::during(fn () => $this->get('/carlos/me05')->assertOk());

    expect($cold->deciding)->toHaveCount(2)->and($cold->stray)->toBe([]);
});

/** A second set in the same collection, one owned card at $dollars, in $currency. */
function ownInAnotherSet(int $dollars, string $currency = 'USD'): void
{
    $set = Set::create(['tcgdex_id' => 'other', 'name' => 'Other Set', 'card_count' => 10]);
    $card = Card::create(['tcgdex_id' => 'other-1', 'set_id' => $set->id, 'local_id' => '1', 'name' => 'Elsewhere']);
    CollectionItem::create(['collection_id' => CollectionItem::query()->value('collection_id'), 'card_id' => $card->id, 'card_tcgdex_id' => 'other-1', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => $currency === 'USD' ? 'tcgplayer' : 'cardmarket', 'variant' => 'normal', 'captured_on' => today(), 'currency' => $currency, 'market_minor' => $dollars * 100]);
}

test('states the set share of the collection value, in the same currency only', function () {
    seedOwnedSetWorth([50, 10]);
    ownInAnotherSet(40);

    expect(RevampHtml::text('set-share', $this->get('/carlos/me05')->getContent()))->toBe('60% of the collection');
});

test('states no share when the set is the whole collection', function () {
    seedOwnedSetWorth([50, 10]);

    expect($this->get('/carlos/me05')->getContent())->not->toContain('set-share');
});

test('states no share when the set is priced in a currency other than the collection\'s', function () {
    seedOwnedSetWorth([50, 10]);
    // The set's value is USD here, so move the collection's primary currency by making the set EUR-only instead.
    CardPriceSnapshot::query()->where('market_minor', '<', 90000)->update(['currency' => 'EUR', 'source' => 'cardmarket']);
    ownInAnotherSet(40);

    expect($this->get('/carlos/me05')->getContent())->not->toContain('set-share');
});
