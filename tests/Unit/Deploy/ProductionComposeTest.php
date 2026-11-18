<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * The production services built from the app image, read from the compose
 * file. Not base_path(): unit tests don't boot the application on their own.
 *
 * @return array<string, array{string}>
 */
function appImageServices(): array
{
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/prod/compose.prod.yml');
    $services = [];

    foreach ($compose['services'] as $name => $service) {
        if (str_contains($service['image'] ?? '', '/tcg-vault:')) {
            $services[$name] = [$name];
        }
    }

    return $services;
}

test('the production compose file has app image services to check', function () {
    expect(array_keys(appImageServices()))->toContain('app', 'horizon', 'scheduler');
});

test('Telescope is switched off in every production service that runs the app', function (string $service) {
    // Telescope's job watcher queues an update for every job even though the
    // production filter drops the entry, and each of those updates queues
    // itself again. The compose environment wins over the .env file, so this
    // holds whatever the real .env says.
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/prod/compose.prod.yml');

    expect($compose['services'][$service]['environment']['TELESCOPE_ENABLED'])->toBe('false');
})->with(fn () => appImageServices());

/**
 * A compose duration ("660s", "11m", "1m30s") in seconds.
 */
function composeDurationSeconds(string $duration): int
{
    preg_match_all('/(\d+)(h|m|s)/', $duration, $parts, PREG_SET_ORDER);
    expect(implode('', array_column($parts, 0)))->toBe($duration, "unparsed duration {$duration}");

    return array_sum(array_map(
        fn (array $part): int => (int) $part[1] * ['h' => 3600, 'm' => 60, 's' => 1][$part[2]],
        $parts,
    ));
}

/**
 * The longest job timeout any production Horizon supervisor allows: how long
 * Horizon waits for its workers once it is told to stop.
 */
function longestProductionSupervisorTimeout(): int
{
    $horizon = require dirname(__DIR__, 3).'/config/horizon.php';
    $supervisors = array_replace_recursive($horizon['defaults'], $horizon['environments']['production']);

    $timeouts = array_column($supervisors, 'timeout');
    expect($timeouts)->not->toBeEmpty();

    return max([0, ...$timeouts]);
}

test('horizon is stopped with SIGTERM, not the image\'s SIGQUIT', function () {
    // The base image's STOPSIGNAL is SIGQUIT (for its nginx/s6 default
    // command). `php artisan horizon` runs as PID 1 and installs no SIGQUIT
    // handler, so the kernel drops that signal and Docker kills the running
    // job when the grace period ends.
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/prod/compose.prod.yml');

    expect($compose['services']['horizon']['stop_signal'] ?? null)->toBe('SIGTERM');
});

test('horizon gets longer than its longest job timeout to stop', function () {
    // On SIGTERM Horizon lets each worker finish its job and waits up to the
    // longest supervisor timeout. A shorter grace period kills the job, which
    // then sits reserved until retry_after and fails with its one try spent.
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/prod/compose.prod.yml');
    $grace = composeDurationSeconds((string) ($compose['services']['horizon']['stop_grace_period'] ?? '10s'));

    expect($grace)->toBeGreaterThan(longestProductionSupervisorTimeout());
});

test('no job sets a timeout past the longest supervisor timeout', function () {
    // Horizon's master waits for its workers only up to the longest
    // supervisor timeout, then exits and takes the container with it, while
    // a worker's alarm follows the job's own timeout. A longer job timeout
    // would be killed on a stop despite the grace period.
    $timeouts = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Jobs/*.php') ?: [] as $file) {
        $name = 'App\\Jobs\\'.basename($file, '.php');
        expect(class_exists($name))->toBeTrue("{$name} does not autoload");
        /** @var class-string $name */
        $class = new ReflectionClass($name);

        if ($class->hasProperty('timeout') && $class->getProperty('timeout')->hasDefaultValue()) {
            $timeouts[$class->getName()] = (int) $class->getProperty('timeout')->getDefaultValue();
        }
    }

    expect($timeouts)->not->toBeEmpty()
        ->and(max([0, ...array_values($timeouts)]))->toBeLessThanOrEqual(longestProductionSupervisorTimeout());
});

test('the deploy script has time for horizon to drain', function () {
    // appleboy/ssh-action cuts the session at command_timeout (10m when
    // unset), and `up -d` waits out horizon's grace period. The rest of the
    // script keeps the 10 minutes it ran in before.
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/prod/compose.prod.yml');
    $workflow = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/deploy.yml');
    $deploy = array_values(array_filter(
        $workflow['jobs']['deploy']['steps'],
        fn (array $step): bool => ($step['name'] ?? null) === 'Deploy to server',
    ))[0];

    $grace = composeDurationSeconds((string) ($compose['services']['horizon']['stop_grace_period'] ?? '10s'));
    $commandTimeout = composeDurationSeconds((string) ($deploy['with']['command_timeout'] ?? '10m'));

    expect($commandTimeout)->toBeGreaterThanOrEqual($grace + 600);
});

test('horizon keeps its own internet egress', function () {
    // Queued jobs call tcgdex and tcgcsv from this container; without a
    // network Docker routes out of, every call fails on DNS with no
    // user-facing error.
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/prod/compose.prod.yml');

    expect($compose['services']['horizon']['networks'])->toContain('tcgvault-egress')
        ->and($compose['networks']['tcgvault-egress']['internal'] ?? false)->toBeFalse();
});

test('postgres preloads pg_stat_statements, times I/O and logs temp files', function () {
    // Which statements spill to disk and which wait on reads decides any
    // work_mem change, so production records both before anyone tunes it.
    // shared_preload_libraries only takes effect on a server start, which is
    // why all three ride the same restart.
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/prod/compose.prod.yml');
    $command = array_values($compose['services']['postgres']['command']);

    foreach (['shared_preload_libraries=pg_stat_statements', 'track_io_timing=on', 'log_temp_files=10MB'] as $setting) {
        $at = array_search($setting, $command, true);
        expect($at)->toBeInt("postgres command lacks {$setting}");

        expect($command[(int) $at - 1] ?? null)->toBe('-c', "{$setting} is not passed with -c");
    }
});

test('app recycles each FPM worker after a bounded number of requests', function () {
    // The image's pool reads PHP_FPM_PM_MAX_REQUESTS (0, never, by default).
    // A worker that slowly grows is replaced instead of carrying its memory
    // until the pool reaps it idle.
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/prod/compose.prod.yml');

    expect($compose['services']['app']['environment']['PHP_FPM_PM_MAX_REQUESTS'] ?? null)->toBe('500');
});
