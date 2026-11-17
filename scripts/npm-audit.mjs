// CI's npm audit: fails on any high or critical advisory, except the ones
// listed below, and each of those only while GitHub reports no patched
// version for it. Once a fix ships the exception lapses on its own and the
// build fails until the dependency is upgraded. Any error (npm, network, the
// GitHub lookup) fails the build: nothing here can widen the gate.
//
// Run with GH_TOKEN set (CI passes the job's GITHUB_TOKEN).

import { execFileSync } from 'node:child_process';
import { blockingAdvisories, exceptionStillApplies } from './npm-audit-helpers.mjs';

const EXCEPTIONS = {
    // picomatch <= 4.0.7 ReDoS on repeated extglobs. Only build tooling uses
    // it (vite, laravel-vite-plugin), on globs from our own config, never on
    // visitor input. No patched release existed when this was added.
    'GHSA-fc5q-39xc-vjpf': 'picomatch ReDoS, build-time only, no patch upstream',
};

function run(command, args) {
    try {
        return execFileSync(command, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 });
    } catch (error) {
        // npm audit exits non-zero whenever it finds anything; its report is still on stdout.
        if (command === 'npm' && typeof error.stdout === 'string' && error.stdout.trim() !== '') {
            return error.stdout;
        }
        throw new Error(`${command} ${args.join(' ')} failed: ${String(error.stderr ?? error.message).trim()}`);
    }
}

function activeExceptions() {
    const active = new Set();
    for (const [id, reason] of Object.entries(EXCEPTIONS)) {
        let advisory = null;
        try {
            advisory = JSON.parse(run('gh', ['api', `/advisories/${id}`]));
        } catch (error) {
            console.log(`Exception ${id} not applied: ${error.message}`);
            continue;
        }
        if (exceptionStillApplies(advisory)) {
            active.add(id);
            console.log(`Excepted ${id} (${reason}): still no patched version upstream.`);
        } else {
            console.log(`Exception ${id} lapsed: a patched version exists. Upgrade, then remove it from scripts/npm-audit.mjs.`);
        }
    }

    return active;
}

try {
    const audit = JSON.parse(run('npm', ['audit', '--json']));
    if (audit.error || typeof audit.vulnerabilities !== 'object') {
        throw new Error(`npm audit returned no report: ${JSON.stringify(audit.error ?? audit).slice(0, 500)}`);
    }

    const blocking = blockingAdvisories(audit, activeExceptions());
    for (const a of blocking) {
        console.log(`${a.severity.toUpperCase()} ${a.id} ${a.name}: ${a.title} ${a.url}`);
    }
    if (blocking.length > 0) {
        console.log(`${blocking.length} high or critical npm ${blocking.length === 1 ? 'advisory' : 'advisories'}.`);
        process.exit(1);
    }
    console.log('npm audit: no high or critical advisories outside the exceptions.');
} catch (error) {
    console.log(error.message);
    process.exit(1);
}
