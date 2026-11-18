import test from 'node:test';
import assert from 'node:assert/strict';
import { dayLabel, figureSource, matCard } from './landing-helpers.js';

test('a day this year reads "4 Nov"; another year carries its year, as everywhere else on the site', () => {
    assert.equal(dayLabel('2026-11-04', '2026-11-17'), '4 Nov');
    assert.equal(dayLabel('2026-10-12', '2026-11-17'), '12 Oct');
    assert.equal(dayLabel('2026-11-04', '2027-01-02'), '4 Nov 2026');
});

test('the landing figures name their source, say they are approximate and give the day they were taken, never "today"', () => {
    assert.equal(figureSource('2026-11-04', '2026-11-17'), 'TCGplayer · approx. · 4 Nov');
    assert.equal(figureSource('2026-11-04', '2027-03-01'), 'TCGplayer · approx. · 4 Nov 2026');
    assert.doesNotMatch(figureSource('2026-11-04', '2026-11-04'), /today/);
});

test('the mat draws the card at 63 × 88 mm and says it is approximate when the visitor never matched a real card', () => {
    for (const raw of [null, '', 'abc', '0.4', '2']) {
        const card = matCard(raw);
        assert.equal(card.matched, false, `raw ${raw}`);
        assert.equal(card.w.toFixed(2), (63 * 96 / 25.4).toFixed(2));
        assert.equal(card.h.toFixed(2), (88 * 96 / 25.4).toFixed(2));
        assert.equal(card.caption, 'Official art · approx. until matched to a real card');
    }
});

test('the mat uses the calibration a card page stored, and says so', () => {
    const card = matCard('1.25');
    assert.equal(card.matched, true);
    assert.equal(card.cal, 1.25);
    assert.equal(card.w.toFixed(2), (63 * 96 / 25.4 * 1.25).toFixed(2));
    assert.equal(card.caption, 'Official art · matched to your card');
    assert.equal(matCard('1').matched, true);
});
