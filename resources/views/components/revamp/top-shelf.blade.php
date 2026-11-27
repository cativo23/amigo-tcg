@props([
    'entries',  // the priciest cards: card, snapshot, weekDelta, items
    'username',
])

<section class="col-shelf" aria-labelledby="col-shelf-h">
    <h2 class="col-sec-t" id="col-shelf-h">Top cards by value</h2>
    <ol class="col-shelf-row">
        @foreach ($entries as $entry)
            <x-revamp.card-tile
                key-prefix="shelf"
                :card="$entry['card']"
                :snapshot="$entry['snapshot']"
                :week-delta="$entry['weekDelta']"
                :items="$entry['items']"
                :index="$loop->index"
                :eager="$loop->index < 3"
                :priority="$loop->first"
                :href="route('gallery.card', ['username' => $username, 'setTcgdexId' => $entry['card']->set->tcgdex_id, 'localId' => $entry['card']->local_id])"
            />
        @endforeach
    </ol>
</section>
