<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Services\WeekBaselines;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\PublicCollection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Builds a public collection whose cards carry a current price and one a
 * week earlier, then loads them the way the pages do (owned copies and the
 * deciding price rows with the week baseline joined on). Not used by the app.
 */
final class MoverFixtures
{
    private Collection $collection;

    private Set $set;

    private int $n = 0;

    public function __construct()
    {
        $user = User::factory()->create();
        $this->collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
        $this->set = Set::create(['tcgdex_id' => 'mv01', 'name' => 'Movers']);
    }

    /**
     * One owned card.
     *
     * @param  ?int  $previous  the price a week earlier, null for none
     * @return Card the created card (not yet loaded with prices)
     */
    public function card(?int $current, ?int $previous, string $currency = 'USD', int $quantity = 1, ?string $name = null): Card
    {
        $this->n++;
        $card = Card::create(['tcgdex_id' => "mv01-{$this->n}", 'set_id' => $this->set->id, 'local_id' => (string) $this->n, 'name' => $name ?? "Mover {$this->n}"]);
        $now = now();

        DB::table('collection_items')->insert([
            'collection_id' => $this->collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id,
            'variant' => 'normal', 'condition' => 'NM', 'quantity' => $quantity, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ([0 => $current, 7 => $previous] as $daysAgo => $minor) {
            if ($minor === null) {
                continue;
            }

            DB::table('card_price_snapshots')->insert([
                'card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal',
                'captured_on' => today()->subDays($daysAgo)->toDateString(), 'currency' => $currency, 'market_minor' => $minor,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $card;
    }

    /** @return EloquentCollection<int, Card> every owned card with its copies and deciding price rows */
    public function load(): EloquentCollection
    {
        app(WeekBaselines::class)->refreshAll();

        return app(DecidingPriceRows::class)->loadOnto(
            PublicCollection::for($this->collection->user)->cardsInIdOrder()->take(PublicCollection::MAX_CARDS)->get(),
        );
    }
}
