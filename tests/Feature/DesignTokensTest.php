<?php

declare(strict_types=1);

function cssSource(string $file): string
{
    return (string) file_get_contents(resource_path('css/'.$file));
}

it('defines the headline-figure and masthead sizes once, in the global stylesheet', function (): void {
    $revamp = cssSource('revamp.css');

    expect($revamp)->toMatch('/--text-figure:\s*min\(clamp\([^;]+\),\s*[\d.]+svh\);/')
        ->and($revamp)->toMatch('/--text-masthead:\s*clamp\([^;]+\);/');
});

it('retires the page-scoped name sizes everywhere', function (): void {
    $sources = [];
    foreach ([resource_path('css'), resource_path('views')] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.(css|php)$/', $file->getFilename())) {
                $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
    }

    $offenders = array_keys(array_filter($sources, fn (string $s): bool => (bool) preg_match('/--(col|sts|act)-who\b/', $s)));

    expect($offenders)->toBe([]);
});

it('sets every page name at the masthead size, not as the hero', function (string $file, string $selector): void {
    $css = cssSource($file);

    expect($css)->toMatch('/'.preg_quote($selector, '/').'\s*\{[^}]*font:\s*820\s+var\(--text-masthead\)/s');
})->with([
    'collection name' => ['collection.css', '.col-who'],
    'sets heading' => ['sets.css', '.sts-title'],
    'activity heading' => ['activity.css', '.act-who'],
    'set name' => ['set.css', '.set-name'],
]);
