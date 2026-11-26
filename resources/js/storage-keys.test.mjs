import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

test('the theme key is amigo-tcg-theme and the old key is gone from the scripts', () => {
    assert.ok(/THEME_KEY = 'amigo-tcg-theme'/.test(read('./app.js')), 'app.js uses the new theme key');
    assert.ok(!/tcg-vault-theme/.test(read('./app.js')), 'app.js has no old theme key');
});

test('the calibration key is amigo-tcg-cal everywhere it is read or written', () => {
    for (const file of ['./plate.js', './landing-stage.js', './landing-helpers.js']) {
        const source = read(file);
        assert.ok(/amigo-tcg-cal/.test(source), `${file} reads the new key`);
        assert.ok(!/tcg-vault-cal/.test(source), `${file} no longer names the old key`);
    }
});

test('no script reads a stored value under the old name, since storage is per-origin and the old origin only redirects', () => {
    for (const file of ['./app.js', './plate.js', './landing-stage.js', './landing-helpers.js']) {
        assert.ok(!/tcg-vault/.test(read(file)), `${file} has no leftover old name`);
    }
});
