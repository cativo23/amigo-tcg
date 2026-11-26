<?php

declare(strict_types=1);

// The drop token is the only colour a falling price gets. Contrast and hue distance are
// asserted in resources/js/contrast.test.mjs; this pins that it exists in every theme block
// and never borrows the destructive red.

function revampCss(): string
{
    return (string) file_get_contents(resource_path('css/revamp.css'));
}

it('defines the drop token in the light, OS-dark and stored-dark blocks', function (): void {
    expect(preg_match_all('/--color-drop:\s*oklch\([^)]+\);/', revampCss()))->toBe(3);
});

it('keeps the drop token distinct from the destructive red', function (): void {
    preg_match_all('/--color-drop:\s*([^;]+);/', revampCss(), $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $value) {
        expect(strtolower($value))->not->toContain('#c23b2e')
            ->and(strtolower($value))->not->toContain('#e2564a')
            ->and($value)->not->toContain('var(--danger)');
    }
});

it('does not reuse the drop token as a fill, frame or background', function (): void {
    $css = '';
    foreach (glob(resource_path('css/*.css')) ?: [] as $file) {
        $css .= file_get_contents($file);
    }

    // The one exception is a chart's end marker: a 9px dot at the end of the line, drawn as a background,
    // which follows the line's colour. Everything else (tiles, panels, tags, rows) never takes the token.
    // Anchored to a rule start and to a body that is only that one declaration, so a selector list or an extra
    // declaration in the same rule is still caught.
    $css = (string) preg_replace('/(?:^|\})\s*\.(?:act|crd)-end\.chart--down\s*\{\s*background:\s*var\(--color-drop\);?\s*\}/', '}', $css);

    expect(preg_match('/(background|border|outline|box-shadow)[a-z-]*:[^;{}]*var\(--color-drop\)/', $css))->toBe(0);
});
