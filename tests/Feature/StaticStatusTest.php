<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['static.files.disk' => 'local']);

    // Isolate each test on its own disk so leftover files never bleed across.
    Storage::fake('local');
});

/**
 * A cache mirroring the real layout: one page cached under several query
 * strings, a precompressed sibling, a nested URL and a non-HTML response.
 */
function seedStaticCache(): void
{
    $disk = Storage::disk('local');

    $disk->put('example.com/GET/?.html', str_repeat('a', 100));
    $disk->put('example.com/GET/baby?.html', str_repeat('a', 200));
    $disk->put('example.com/GET/baby?.html.gz', str_repeat('a', 50));
    $disk->put('example.com/GET/baby?utm_source=newsletter.html', str_repeat('a', 200));
    $disk->put('example.com/GET/baby?utm_source=facebook&utm_medium=social.html', str_repeat('a', 200));
    $disk->put('example.com/GET/sitemap?.xml', str_repeat('a', 300));
    $disk->put('example.com/GET/toys/lego?.html', str_repeat('a', 400));
}

function statusReport(): array
{
    Artisan::call('static:status', ['--json' => true]);

    return json_decode(Artisan::output(), associative: true);
}

it('counts compressed siblings towards size but not towards pages', function () {
    seedStaticCache();

    $report = statusReport();

    expect($report['files'])->toBe(7);
    expect($report['bytes'])->toBe(1450);

    // The .gz is the same page stored twice, so it must not inflate the page
    // count — otherwise enabling compression would look like cache growth.
    expect($report['pages'])->toBe(6);
});

it('splits storage between plain and query string urls', function () {
    seedStaticCache();

    $report = statusReport();

    // The two utm_* variants of /baby, and nothing else.
    expect($report['shape']['query']['pages'])->toBe(2);
    expect($report['shape']['query']['files'])->toBe(2);
    expect($report['shape']['query']['bytes'])->toBe(400);

    // Everything else, including the .gz sibling that carries no query string.
    expect($report['shape']['plain']['pages'])->toBe(4);
    expect($report['shape']['plain']['files'])->toBe(5);
    expect($report['shape']['plain']['bytes'])->toBe(1050);
});

it('reports cumulative directory sizes and skips pass-through levels', function () {
    seedStaticCache();

    $report = statusReport();

    // Every file sits under these two, so they hold the whole cache and are
    // dropped as uninformative.
    expect($report['directories'])->not->toHaveKey('example.com');
    expect($report['directories'])->not->toHaveKey('example.com/GET');

    // The one directory holding a real subset of the cache survives.
    expect($report['directories']['example.com/GET/toys']['bytes'])->toBe(400);
    expect($report['directories']['example.com/GET/toys']['files'])->toBe(1);
});

it('groups query string variants under their url', function () {
    seedStaticCache();

    $report = statusReport();

    // /, /baby, /sitemap and /toys/lego — the four URLs behind the six pages.
    expect($report['uris'])->toBe(4);
    expect($report['variants']['example.com/baby']['variants'])->toBe(3);
    expect($report['variants']['example.com/']['variants'])->toBe(1);
});

it('counts query parameters by name', function () {
    seedStaticCache();

    $report = statusReport();

    expect($report['params']['utm_source'])->toBe(2);
    expect($report['params']['utm_medium'])->toBe(1);
});

it('reports each compression format as its own file type', function () {
    seedStaticCache();

    $report = statusReport();

    expect($report['types']['html']['files'])->toBe(5);
    expect($report['types']['html.gz']['files'])->toBe(1);
    expect($report['types']['xml']['files'])->toBe(1);
});

it('reports an empty cache as empty', function () {
    $this->artisan('static:status')
        ->expectsOutputToContain('Static cache is empty')
        ->assertSuccessful();
});

it('refuses a disk that is not local', function () {
    config(['filesystems.disks.local.driver' => 's3']);

    $this->artisan('static:status')
        ->expectsOutputToContain('only local disks can be inspected')
        ->assertFailed();
});
