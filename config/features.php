<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Feature flags
|--------------------------------------------------------------------------
|
| Switches for pages that are built behind a flag and shown once they are
| ready. Each is off unless its environment variable says otherwise.
|
| decks: the "Decks" entry in the gallery navigation. It also needs a route
| named `gallery.decks` ({username}/decks) to exist, so the flag can never
| render a link to a page that is not there.
*/

return [
    'decks' => filter_var(env('FEATURE_DECKS', false), FILTER_VALIDATE_BOOLEAN),
];
