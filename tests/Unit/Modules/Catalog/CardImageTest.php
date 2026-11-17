<?php

declare(strict_types=1);

use App\Modules\Catalog\Support\CardImage;

test('a tcgdex high-resolution image is served at low resolution for a tile', function () {
    expect(CardImage::tile('https://assets.tcgdex.net/en/me/me05/116/high.webp'))
        ->toBe('https://assets.tcgdex.net/en/me/me05/116/low.webp');
});

test('an image on another host or in another shape is used exactly as stored', function (string $url) {
    expect(CardImage::tile($url))->toBe($url);
})->with([
    'tcgplayer' => ['https://product-images.tcgplayer.com/fit-in/437x437/123.jpg'],
    'tcgdex without a size file' => ['https://assets.tcgdex.net/en/me/me05/116'],
    'a lookalike host' => ['https://assets.tcgdex.net.evil.test/en/me/me05/116/high.webp'],
]);

test('no image stays no image', function () {
    expect(CardImage::tile(null))->toBeNull()
        ->and(CardImage::tile(''))->toBeNull();
});
