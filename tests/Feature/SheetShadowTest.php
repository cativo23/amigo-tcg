<?php

declare(strict_types=1);

/**
 * Every --shadow-sheet declaration (light, dark media query, forced dark), as its comma-separated layers.
 *
 * @return list<list<string>>
 */
function sheetShadowDeclarations(): array
{
    $css = (string) file_get_contents(resource_path('css/revamp.css'));
    preg_match_all('/--shadow-sheet:\s*([^;]+);/', $css, $matches);

    // Split on top-level commas only: oklch() and var() carry none, but keep it honest.
    return array_map(
        fn (string $value) => array_map('trim', preg_split('/,(?![^(]*\))/', $value) ?: []),
        $matches[1],
    );
}

test('the sheet shadow is declared for light, dark and forced dark', function () {
    expect(sheetShadowDeclarations())->toHaveCount(3);
});

test('a stock sheet draws no line along its top edge, so a selected divider tab runs straight into it', function () {
    // design.md, Stock sheet: an inset highlight or a shadow reaching above the sheet painted a seam under the selected tab.
    foreach (sheetShadowDeclarations() as $layers) {
        foreach ($layers as $layer) {
            expect($layer)->not->toContain('inset');

            preg_match('/^(-?\d+(?:\.\d+)?)(?:px)?\s+(-?\d+(?:\.\d+)?)(?:px)?\s+(\d+(?:\.\d+)?)(?:px)?(?:\s+(-?\d+(?:\.\d+)?)px)?/', $layer, $m);
            expect($m)->not->toBeEmpty();

            [$y, $blur, $spread] = [(float) $m[2], (float) $m[3], (float) ($m[4] ?? 0)];
            // The layer's top edge sits at y - spread; its blur reaches that far again above it.
            expect($y - $spread - $blur)->toBeGreaterThanOrEqual(0.0, "{$layer} reaches above the sheet's top edge");
        }
    }
});
