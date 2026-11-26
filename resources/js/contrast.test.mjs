import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { contrastRatio, hueOf, parseColor } from './contrast.js';

const css = readFileSync(new URL('../css/revamp.css', import.meta.url), 'utf8');

// Pull one token out of a CSS block that starts at `opener` (first match) and ends at its closing brace.
function tokenIn(opener, name) {
    const start = css.indexOf(opener);
    assert.notEqual(start, -1, `${opener} block exists`);
    const end = css.indexOf('}', start);
    const match = css.slice(start, end).match(new RegExp(`${name}:\\s*([^;]+);`));
    assert.ok(match, `${name} is defined in ${opener}`);
    return match[1].trim();
}

const themes = {
    light: { block: ':root {', danger: '#c23b2e' },
    dark: { block: ":root[data-theme='dark'] {", danger: '#e2564a' },
};

test('the contrast helper agrees with known values', () => {
    assert.ok(Math.abs(contrastRatio('#000000', '#ffffff') - 21) < 0.01);
    assert.ok(Math.abs(contrastRatio('#777777', '#ffffff') - 4.48) < 0.05);
    assert.equal(contrastRatio('oklch(50% 0.1 90)', 'oklch(50% 0.1 90)'), 1);
});

for (const [theme, { block }] of Object.entries(themes)) {
    test(`--color-drop meets WCAG AA 4.5:1 at 11px on --color-stock in the ${theme} theme`, () => {
        const ratio = contrastRatio(tokenIn(block, '--color-drop'), tokenIn(block, '--color-stock'));
        assert.ok(ratio >= 4.5, `${theme}: ${ratio.toFixed(2)}:1 is below 4.5:1`);
    });

    test(`--color-drop is at least 12 degrees of hue away from the destructive red in the ${theme} theme`, () => {
        const drop = hueOf(tokenIn(block, '--color-drop'));
        const danger = hueOf(themes[theme].danger);
        const gap = Math.min(Math.abs(drop - danger), 360 - Math.abs(drop - danger));
        assert.ok(gap >= 12, `${theme}: drop ${drop.toFixed(1)}° vs danger ${danger.toFixed(1)}° is only ${gap.toFixed(1)}° apart`);
    });
}

test('parseColor reads hex and oklch', () => {
    assert.deepEqual(parseColor('#ffffff').map((v) => Math.round(v * 255)), [255, 255, 255]);
    assert.equal(parseColor('oklch(100% 0 0)').length, 3);
});
