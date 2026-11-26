<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The product's name
|--------------------------------------------------------------------------
|
| The one place the name is written. Views, emails, alerts and the legal
| pages read config('brand.name'); the wordmark (<x-brand.wordmark>) is
| that name split at its first space: the first word at full weight, the
| rest lighter. Changing the product's name is this one line.
|
| It is not APP_NAME: the session cookie, the cache prefix and the Horizon
| prefix are derived from APP_NAME, so the name shown to people must not be
| tied to it.
*/

$name = 'AMIGO TCG';
[$strong, $light] = array_pad(explode(' ', $name, 2), 2, '');

return [
    'name' => $name,
    'wordmark' => ['strong' => $strong, 'light' => $light],
];
