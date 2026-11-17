// Pure parts of scripts/npm-audit.mjs, tested by `npm run test:js`.

const BLOCKING = new Set(['high', 'critical']);

/**
 * The advisories in an `npm audit --json` report that fail the build: high or
 * critical, and not in `excepted` (GHSA ids). A package that only inherits an
 * advisory from a dependency lists that dependency's name in `via`, not the
 * advisory, so reading the advisory objects covers every package once.
 */
export function blockingAdvisories(audit, excepted) {
    const found = new Map();

    for (const entry of Object.values(audit?.vulnerabilities ?? {})) {
        for (const via of entry.via ?? []) {
            if (typeof via !== 'object' || via === null || !BLOCKING.has(via.severity)) {
                continue;
            }
            const id = String(via.url ?? '').split('/').pop();
            if (excepted.has(id) || found.has(id)) {
                continue;
            }
            found.set(id, { id, name: via.name, severity: via.severity, title: via.title, url: via.url });
        }
    }

    return [...found.values()].sort((a, b) => a.id.localeCompare(b.id));
}

/**
 * Whether an exception for a GitHub advisory (the body of
 * GET /advisories/{ghsa_id}) may still hold: only while no affected package
 * line has a patched version. Anything unreadable counts as patched, so a
 * failed lookup never keeps an exception alive.
 */
export function exceptionStillApplies(advisory) {
    const vulnerabilities = advisory?.vulnerabilities;
    if (!Array.isArray(vulnerabilities) || vulnerabilities.length === 0) {
        return false;
    }

    return vulnerabilities.every((v) => v?.first_patched_version === null);
}
