import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../css/revamp.css', import.meta.url), 'utf8');

// "$1,234,567.89" set in Anybody at weight 820, wdth 62, letter-spacing -.02em measures 4.5 em wide
// (headless Chrome, the shipped latin woff2 file). That is the widest amount the page must hold on a phone.
const SEVEN_DIGIT_EM = 4.5;
const PHONE_WIDTH = 360;
const GUTTER = 16; // --gutter is clamp(16px, 4vw, 56px): 16px at 360

function token(name) {
    const match = css.match(new RegExp(`${name}:\\s*([^;]+);`));
    assert.ok(match, `${name} is defined in revamp.css`);
    return match[1].trim();
}

/** The smallest size the token can take, in px, read from its clamp()'s first argument. */
function minimumPx(value) {
    const clamp = value.match(/clamp\(\s*([\d.]+)(rem|px)/);
    assert.ok(clamp, `${value} has a clamp() minimum`);
    return Number(clamp[1]) * (clamp[2] === 'rem' ? 16 : 1);
}

test('the headline figure at its smallest still holds a 7-digit amount inside a 360px phone', () => {
    const size = minimumPx(token('--text-figure'));
    const width = size * SEVEN_DIGIT_EM;
    const room = PHONE_WIDTH - 2 * GUTTER;

    assert.ok(width <= room, `${size}px x ${SEVEN_DIGIT_EM}em = ${width}px does not fit ${room}px`);
});

test('the figure is never smaller than 72px at its minimum and the masthead is always smaller than the figure', () => {
    assert.ok(minimumPx(token('--text-figure')) >= 72, 'the figure keeps the spec floor of 72px');
    assert.ok(minimumPx(token('--text-masthead')) < minimumPx(token('--text-figure')), 'a masthead label is below the figure');
});
