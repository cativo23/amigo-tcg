import { test } from 'node:test';
import assert from 'node:assert/strict';
import { auditReport, blockingAdvisories, exceptionStillApplies } from './npm-audit-helpers.mjs';

const advisory = (id, severity, name = 'pkg') => ({
    source: 1, name, dependency: name, title: `${name} advisory`, severity,
    url: `https://github.com/advisories/${id}`, range: '<=1.0.0',
});

// The shape `npm audit --json` writes: one entry per affected package, whose
// `via` holds advisory objects (the root) or the names of packages it inherits from.
const report = (...entries) => ({ auditReportVersion: 2, vulnerabilities: Object.fromEntries(entries.map((e) => [e.name, e])) });

test('a high or critical advisory blocks', () => {
    const audit = report(
        { name: 'a', severity: 'high', via: [advisory('GHSA-aaaa-aaaa-aaaa', 'high', 'a')] },
        { name: 'b', severity: 'critical', via: [advisory('GHSA-bbbb-bbbb-bbbb', 'critical', 'b')] },
    );

    assert.deepEqual(blockingAdvisories(audit, new Set()).map((a) => a.id), ['GHSA-aaaa-aaaa-aaaa', 'GHSA-bbbb-bbbb-bbbb']);
});

test('a low or moderate advisory does not block', () => {
    const audit = report(
        { name: 'a', severity: 'moderate', via: [advisory('GHSA-aaaa-aaaa-aaaa', 'moderate', 'a')] },
        { name: 'b', severity: 'low', via: [advisory('GHSA-bbbb-bbbb-bbbb', 'low', 'b')] },
    );

    assert.deepEqual(blockingAdvisories(audit, new Set()), []);
});

test('an excepted advisory does not block, and neither do the packages that only inherit it', () => {
    const audit = report(
        { name: 'picomatch', severity: 'high', via: [advisory('GHSA-fc5q-39xc-vjpf', 'high', 'picomatch')] },
        { name: 'vite', severity: 'high', via: ['picomatch'] },
    );

    assert.deepEqual(blockingAdvisories(audit, new Set(['GHSA-fc5q-39xc-vjpf'])), []);
});

test('an exception covers its own advisory only, never another one in the same package', () => {
    const audit = report(
        { name: 'picomatch', severity: 'high', via: [advisory('GHSA-fc5q-39xc-vjpf', 'high', 'picomatch'), advisory('GHSA-zzzz-zzzz-zzzz', 'high', 'picomatch')] },
    );

    assert.deepEqual(blockingAdvisories(audit, new Set(['GHSA-fc5q-39xc-vjpf'])).map((a) => a.id), ['GHSA-zzzz-zzzz-zzzz']);
});

test('the same advisory reached through two packages is reported once', () => {
    const shared = advisory('GHSA-aaaa-aaaa-aaaa', 'high', 'a');
    const audit = report({ name: 'a', severity: 'high', via: [shared] }, { name: 'b', severity: 'high', via: [shared, 'a'] });

    assert.equal(blockingAdvisories(audit, new Set()).length, 1);
});

test('an exception applies while the advisory has no patched version anywhere', () => {
    assert.equal(exceptionStillApplies({ vulnerabilities: [{ first_patched_version: null }] }), true);
});

test('an exception lapses once any affected line has a patched version', () => {
    assert.equal(exceptionStillApplies({ vulnerabilities: [{ first_patched_version: null }, { first_patched_version: '4.0.8' }] }), false);
});

test('an advisory the lookup could not read keeps no exception', () => {
    for (const bad of [null, undefined, {}, { vulnerabilities: [] }, { message: 'Not Found' }]) {
        assert.equal(exceptionStillApplies(bad), false);
    }
});

test('an advisory with no url still blocks, under an empty id no exception can match', () => {
    const audit = report({ name: 'a', severity: 'high', via: [{ name: 'a', severity: 'high', title: 'no url' }] });

    assert.deepEqual(blockingAdvisories(audit, new Set(['GHSA-fc5q-39xc-vjpf'])).map((a) => a.id), ['']);
});

test('a readable npm audit report is returned as is', () => {
    const audit = report({ name: 'a', severity: 'high', via: [advisory('GHSA-aaaa-aaaa-aaaa', 'high', 'a')] });

    assert.deepEqual(auditReport(JSON.stringify(audit)), audit);
    assert.deepEqual(auditReport('{"auditReportVersion":2,"vulnerabilities":{}}').vulnerabilities, {});
});

test('anything that is not an npm audit report is an error, never an empty report', () => {
    for (const stdout of ['', '<html>502</html>', '{"error":{"code":"ENOAUDIT"}}', '{"error":{},"vulnerabilities":{}}', '{"message":"500","statusCode":500}',
        '{"vulnerabilities":null}', '{"vulnerabilities":[]}', '{"advisories":{}}', 'null']) {
        assert.throws(() => auditReport(stdout), /npm audit/, stdout);
    }
});
