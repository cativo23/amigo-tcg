<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionCsvExporter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\PriceRowReads;

function collector(array $attributes = []): User
{
    Role::findOrCreate('user')->givePermissionTo(Permission::findOrCreate('use-collection'));
    $user = User::factory()->create($attributes);
    $user->assignRole('user');

    return $user;
}

/**
 * @return array<int, array<int, string>>
 */
function exportRows(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $lines = preg_split('/\r\n|\n/', trim($csv));

    return array_map(fn (string $line) => str_getcsv($line, escape: ''), $lines);
}

beforeEach(function () {
    $this->set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $this->card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $this->set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
});

test('a guest is sent to log in', function () {
    $this->get(route('admin.collection.export'))->assertRedirect(route('login'));
});

test('a user without the use-collection permission cannot export', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.collection.export'))
        ->assertForbidden();
});

test('the export downloads as a dated CSV attachment', function () {
    Carbon::setTestNow('2026-09-25 12:00:00');

    $response = $this->actingAs(collector(['username' => 'ash']))
        ->get(route('admin.collection.export'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('amigo-tcg-ash-2026-09-25.csv');
});

test('the export starts with a UTF-8 BOM so spreadsheet apps read accented names correctly', function () {
    $csv = $this->actingAs(collector())->get(route('admin.collection.export'))->streamedContent();

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();
});

test('each item exports every field it has, priced for its own variant', function () {
    $user = collector();
    $collection = Collection::factory()->for($user)->create();
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $this->card->id, 'card_tcgdex_id' => 'me05-116',
        'variant' => 'holofoil', 'condition' => 'NM', 'grade_company' => 'PSA', 'grade_value' => '10',
        'quantity' => 2, 'notes' => 'Pulled at launch',
    ]);
    CardPriceSnapshot::create(['card_id' => $this->card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->toDateString(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $this->card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today()->toDateString(), 'currency' => 'USD', 'market_minor' => 4250]);

    $rows = exportRows($this->actingAs($user)->get(route('admin.collection.export'))->streamedContent());

    expect($rows[0])->toBe([
        'card_name', 'set_name', 'set_id', 'number', 'tcgdex_id', 'variant', 'condition',
        'grade_company', 'grade_value', 'quantity', 'notes', 'market_price', 'currency', 'price_date',
    ])->and($rows[1])->toBe([
        'Mega Darkrai ex', 'Pitch Black', 'me05', '116', 'me05-116', 'holofoil', 'NM',
        'PSA', '10', '2', 'Pulled at launch', '42.50', 'USD', today()->toDateString(),
    ]);
});

test('an item with no price leaves the price columns empty', function () {
    $user = collector();
    CollectionItem::create([
        'collection_id' => Collection::factory()->for($user)->create()->id, 'card_id' => $this->card->id,
        'card_tcgdex_id' => 'me05-116', 'condition' => 'LP', 'quantity' => 1,
    ]);

    $rows = exportRows($this->actingAs($user)->get(route('admin.collection.export'))->streamedContent());

    expect(array_slice($rows[1], -3))->toBe(['', '', '']);
});

test('the export includes every item, past the 1,000-row cap the collection page lists', function () {
    $user = collector();
    $collectionId = Collection::factory()->for($user)->create()->id;
    $now = now();

    $cards = collect(range(1, 1005))->map(fn (int $i) => [
        'tcgdex_id' => "me05-x{$i}", 'set_id' => $this->set->id, 'local_id' => "x{$i}", 'name' => "Card {$i}",
        'created_at' => $now, 'updated_at' => $now,
    ]);
    Card::insert($cards->all());
    $cardIds = Card::where('tcgdex_id', 'like', 'me05-x%')->pluck('id', 'tcgdex_id');

    CollectionItem::insert($cardIds->map(fn (int $id, string $tcgdexId) => [
        'collection_id' => $collectionId, 'card_id' => $id, 'card_tcgdex_id' => $tcgdexId,
        'condition' => 'NM', 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
    ])->values()->all());

    $rows = exportRows($this->actingAs($user)->get(route('admin.collection.export'))->streamedContent());

    expect($rows)->toHaveCount(1006);
});

test('the export includes the users own items and never another users', function () {
    $user = collector();
    foreach ([[$user, 'mine'], [User::factory()->create(), 'theirs']] as [$owner, $note]) {
        CollectionItem::create([
            'collection_id' => Collection::factory()->for($owner)->create()->id,
            'card_id' => $this->card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1,
            'notes' => $note,
        ]);
    }

    $rows = exportRows($this->actingAs($user)->get(route('admin.collection.export'))->streamedContent());

    expect($rows)->toHaveCount(2)
        ->and($rows[1][10])->toBe('mine');
});

test('a formula hidden behind a leading line feed or a full-width sign is neutralised too', function (string $note) {
    $user = collector();
    CollectionItem::create([
        'collection_id' => Collection::factory()->for($user)->create()->id, 'card_id' => $this->card->id,
        'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'notes' => $note,
    ]);

    $csv = $this->actingAs($user)->get(route('admin.collection.export'))->streamedContent();
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
    rewind($handle);
    fgetcsv($handle, escape: '');
    $row = fgetcsv($handle, escape: '');

    expect($row[10])->toBe("'".$note);
})->with([
    'line feed' => ["\n=HYPERLINK(\"http://example.test\")"],
    'full-width equals' => ['＝1+1'],
]);

test('rows end with CRLF, as RFC 4180 specifies', function () {
    $csv = $this->actingAs(collector())->get(route('admin.collection.export'))->streamedContent();

    expect($csv)->toEndWith("\r\n");
});

test('cells that a spreadsheet would run as a formula are neutralised', function () {
    $user = collector();
    CollectionItem::create([
        'collection_id' => Collection::factory()->for($user)->create()->id, 'card_id' => $this->card->id,
        'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1,
        'notes' => '=HYPERLINK("http://example.test","x")',
    ]);

    $rows = exportRows($this->actingAs($user)->get(route('admin.collection.export'))->streamedContent());

    expect($rows[1][10])->toBe('\'=HYPERLINK("http://example.test","x")');
});

test('the collection page links to the export', function () {
    $this->actingAs(collector())
        ->get(route('admin.collection.index'))
        ->assertSee(route('admin.collection.export'));
});

test('the delete account form points to the export once, outside the confirm modal', function () {
    $this->actingAs(collector());

    $html = Livewire::test('profile.delete-user-form')->html();
    $modal = substr($html, strpos($html, 'confirm-user-deletion'));

    // The modal focuses its first link or input on open; a link there
    // would take focus from the password field.
    expect(substr_count($html, route('admin.collection.export')))->toBe(1)
        ->and($modal)->not->toContain(route('admin.collection.export'));
});

test('the export reads price rows only through the deciding query, once for the whole collection, and not at all when cached', function () {
    $user = collector();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['slug' => 'main']);
    $now = now();
    $rows = [];
    foreach (range(1, 3) as $n) {
        $card = Card::create(['tcgdex_id' => "me05-{$n}", 'set_id' => $this->set->id, 'local_id' => (string) $n, 'name' => "Card {$n}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
        foreach (range(0, 39) as $day) {
            $rows[] = ['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal', 'captured_on' => today()->subDays($day)->toDateString(), 'currency' => 'USD', 'market_minor' => 100 * $n + $day, 'raw' => '{}', 'created_at' => $now, 'updated_at' => $now];
        }
    }
    CardPriceSnapshot::insert($rows);
    $export = function (): string {
        $out = fopen('php://memory', 'w+');
        (new CollectionCsvExporter)->write($out);
        rewind($out);

        return (string) stream_get_contents($out);
    };

    $csv = null;
    $miss = PriceRowReads::during(function () use ($export, &$csv) {
        $csv = $export();
    });
    $hit = PriceRowReads::during($export);

    expect($miss->stray)->toBe([])
        ->and($miss->deciding)->toHaveCount(1)
        ->and($miss->decidingIds(0))->toBe($collection->items()->orderBy('card_id')->pluck('card_id')->all())
        ->and($hit->stray)->toBe([])
        ->and($hit->deciding)->toBe([])
        ->and(array_column(array_slice(exportRows($csv), 1), 11))->toBe(['1.00', '2.00', '3.00']);
});

test('a copy of a new card added while the export runs is still written, priced', function () {
    $user = collector();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $this->card->id, 'card_tcgdex_id' => $this->card->tcgdex_id, 'condition' => 'NM', 'quantity' => 1]);
    $late = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $this->set->id, 'local_id' => '001', 'name' => 'Fomantis']);
    CardPriceSnapshot::create(['card_id' => $late->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 75]);
    // Adds the copy right after the export has read which cards to price.
    app()->instance(DecidingPriceRows::class, new class($collection, $late) extends DecidingPriceRows
    {
        public function __construct(private ?Collection $collection, private Card $late) {}

        public function loadOnto(EloquentCollection $cards): EloquentCollection
        {
            if ($this->collection !== null) {
                CollectionItem::create(['collection_id' => $this->collection->id, 'card_id' => $this->late->id, 'card_tcgdex_id' => $this->late->tcgdex_id, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
                $this->collection = null;
            }

            return parent::loadOnto($cards);
        }
    });

    $out = fopen('php://memory', 'w+');
    (new CollectionCsvExporter)->write($out);
    rewind($out);
    $rows = array_slice(exportRows((string) stream_get_contents($out)), 1);

    expect(array_column($rows, 0))->toBe(['Mega Darkrai ex', 'Fomantis'])
        ->and($rows[1][11])->toBe('0.75');
});
