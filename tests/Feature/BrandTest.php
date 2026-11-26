<?php

declare(strict_types=1);

use App\Mail\InviteSent;
use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;

/*
 * The product name lives in one place: config('brand.name'), plus the
 * <x-brand.wordmark> component for the wordmark. These tests set the name
 * to a value that appears nowhere else and look for it on every surface
 * that names the product, so a stray literal fails by name.
 */

const BRAND_PROBE = 'ZZ PROBE';

beforeEach(function () {
    config(['brand.name' => BRAND_PROBE]);
});

test('the landing page names the product from the brand constant', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<title>'.BRAND_PROBE.'</title>', escape: false)
        ->assertDontSee('tcg-vault', escape: false);
});

test('the legal pages name the product from the brand constant', function () {
    $this->get('/privacy')
        ->assertOk()
        ->assertSee(BRAND_PROBE, escape: false)
        ->assertDontSee('tcg-vault', escape: false);
});

test('the error page names the product from the brand constant', function () {
    $this->get('/this-route-does-not-exist')
        ->assertNotFound()
        ->assertSee(BRAND_PROBE, escape: false)
        ->assertDontSee('tcg-vault', escape: false);
});

test('a collector page title ends with the brand constant', function () {
    $user = User::factory()->create(['username' => 'ash']);

    $this->get('/'.$user->username)
        ->assertOk()
        ->assertSee('· '.BRAND_PROBE.'</title>', escape: false)
        ->assertDontSee('tcg-vault', escape: false);
});

test('emails name the product from the brand constant', function () {
    $mail = new InviteSent(Invite::factory()->create());

    $mail->assertSeeInText(BRAND_PROBE);
    $mail->assertDontSeeInText('tcg-vault');
    $mail->assertDontSeeInHtml('TCG-VAULT');
    expect($mail->envelope()->subject)->toContain(BRAND_PROBE)->not->toContain('tcg-vault');
});

test('the wordmark component renders AMIGO strong and TCG light from config', function () {
    config(['brand.wordmark.strong' => 'AMIGO', 'brand.wordmark.light' => 'TCG']);

    $html = Blade::render('<x-brand.wordmark />');

    expect($html)
        ->toMatch('/<[^>]*class="[^"]*wm-strong[^"]*"[^>]*>\s*AMIGO\s*</')
        ->toMatch('/<[^>]*class="[^"]*wm-light[^"]*"[^>]*>\s*TCG\s*</')
        ->and(strpos($html, 'AMIGO'))->toBeLessThan(strpos($html, 'TCG'));
});

test('the brand defaults are AMIGO TCG', function () {
    // beforeEach overrides the name; read the file's own defaults.
    $brand = require config_path('brand.php');

    expect($brand['name'])->toBe('AMIGO TCG')
        ->and($brand['wordmark']['strong'])->toBe('AMIGO')
        ->and($brand['wordmark']['light'])->toBe('TCG');
});

test('the old product name is gone from code, config and deploy files', function () {
    // Lines that must keep the old name on purpose: the host directory and
    // compose project (renaming them would start Postgres on an empty
    // volume), the legacy host that now redirects, the four pins that keep
    // the session cookie, cache prefix, Redis key prefix and Horizon prefix stable, and the
    // `TCGVAULT_*` variable names the server's .env already carries, the project-prefixed
    // volume names, and the demo account's address, which existing rows are matched against.
    $allowedLine = '#(~/deploy/tcg-vault|^\s*name:\s*tcg-vault\b'
        .'|MAIL_FROM_ADDRESS=|SESSION_COOKIE=|CACHE_PREFIX=|REDIS_PREFIX=|HORIZON_PREFIX=|TCGVAULT_|tcg-vault_|demo@tcg-vault\.invalid)#';
    // The old host may be named only where the redirect lives (its router and its regex).
    $legacyHostFiles = ['docker/prod/compose.prod.yml', 'deploy/README.md'];
    $allowedFile = ['deploy/amigo-tcg-cutover.md', 'resources/js/storage-keys.test.mjs'];
    $offenders = [];

    foreach (['app', 'config', 'resources', 'routes', 'database', 'docker', 'deploy', '.github'] as $dir) {
        $root = base_path($dir);
        if (! is_dir($root)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $relative = $dir.'/'.ltrim(substr($file->getPathname(), strlen($root)), '/');

            if (! $file->isFile() || in_array($relative, $allowedFile, true) || ! preg_match('~\.(php|js|mjs|css|md|yml|yaml|json|example|blade\.php|sh|txt)$~', $relative)) {
                continue;
            }

            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                $legacyHost = in_array($relative, $legacyHostFiles, true) && preg_match('~tcgvault(-legacy|\\\\*\.cativo\\\\*\.dev)~', $line);

                if (preg_match('~tcg-vault|tcgvault|tcg vault~i', $line) && ! $legacyHost && ! preg_match($allowedLine, $line)) {
                    $offenders[] = $relative.':'.($n + 1);
                }
            }
        }
    }

    expect($offenders)->toBe([], 'still names the old product: '.implode(', ', $offenders));
});

test('the old host redirects permanently to the new one, keeping path and query', function () {
    $compose = file_get_contents(base_path('docker/prod/compose.prod.yml'));

    expect($compose)
        ->toContain('Host(`amigotcg.cativo.dev`)')
        ->toContain('redirectregex')
        ->toContain('permanent=true')
        ->toContain('https://amigotcg.cativo.dev/$${1}'); // `$$` is compose's escape for a literal `$`
});

test('the compose project name is pinned so volumes keep their names', function () {
    expect(file_get_contents(base_path('docker/prod/compose.prod.yml')))
        ->toMatch('/^name:\s*tcg-vault\s*$/m');
});

test('the production env template pins the session, cache, Redis and Horizon names', function () {
    $env = file_get_contents(base_path('docker/prod/.env.production.example'));

    expect($env)
        ->toContain('SESSION_COOKIE=tcg-vault-session')
        ->toContain('CACHE_PREFIX=tcg-vault-cache-')
        ->toContain('REDIS_PREFIX=tcg-vault-database-')
        ->toContain('HORIZON_PREFIX=tcg_vault_horizon:')
        ->toContain('APP_NAME="AMIGO TCG"')
        ->toContain('APP_URL=https://amigotcg.cativo.dev');
});

test('a revoked invite link still renders the error page under the new name', function () {
    $invite = Invite::factory()->create(['revoked_at' => now()]);
    $url = URL::temporarySignedRoute('invite.accept', $invite->expires_at, [
        'invite' => $invite->id,
        'hash' => sha1($invite->email),
    ]);

    $this->get($url)->assertForbidden()->assertSee(BRAND_PROBE, escape: false);
});
