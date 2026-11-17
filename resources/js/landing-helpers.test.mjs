import test from 'node:test';
import assert from 'node:assert/strict';
import { dayLabel, figureSource } from './landing-helpers.js';

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
