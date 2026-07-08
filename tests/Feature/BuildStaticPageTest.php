<?php

use Backstage\Static\Laravel\Facades\StaticCache;
use Backstage\Static\Laravel\Jobs\BuildStaticPage;
use Backstage\Static\Laravel\Middleware\StaticResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['static.files.disk' => 'local']);

    // Isolate each test on its own disk so leftover files never bleed across.
    Storage::fake('local');
});

it('builds a single URL into the static cache', function () {
    Route::get('foo', fn () => 'foo body')
        ->middleware(StaticResponse::class);

    StaticCache::build('http://localhost/foo');

    $disk = StaticCache::disk();

    $disk->assertExists('localhost/GET/foo?.html');
    expect($disk->get('localhost/GET/foo?.html'))->toBe('foo body');
});

it('builds several URLs at once', function () {
    Route::get('one', fn () => 'one')->middleware(StaticResponse::class);
    Route::get('two', fn () => 'two')->middleware(StaticResponse::class);

    StaticCache::build(['http://localhost/one', 'http://localhost/two']);

    $disk = StaticCache::disk();

    $disk->assertExists('localhost/GET/one?.html');
    $disk->assertExists('localhost/GET/two?.html');
});

it('writes nothing for a route that is not static-cached', function () {
    Route::get('plain', fn () => 'plain');

    StaticCache::build('http://localhost/plain');

    expect(StaticCache::disk()->allFiles())->toBeEmpty();
});

it('re-renders a page so the cache reflects fresh content', function () {
    $body = 'first';

    Route::get('page', function () use (&$body) {
        return $body;
    })->middleware(StaticResponse::class);

    StaticCache::build('http://localhost/page');
    expect(StaticCache::disk()->get('localhost/GET/page?.html'))->toBe('first');

    $body = 'second';

    StaticCache::build('http://localhost/page');
    expect(StaticCache::disk()->get('localhost/GET/page?.html'))->toBe('second');
});

it('builds a page from the queued job', function () {
    Route::get('job', fn () => 'from job')
        ->middleware(StaticResponse::class);

    (new BuildStaticPage('http://localhost/job'))->handle();

    StaticCache::disk()->assertExists('localhost/GET/job?.html');
});

it('does nothing from the job when static caching is disabled', function () {
    config(['static.enabled' => false]);

    Route::get('job', fn () => 'from job')
        ->middleware(StaticResponse::class);

    (new BuildStaticPage('http://localhost/job'))->handle();

    expect(StaticCache::disk()->allFiles())->toBeEmpty();
});

it('builds only the given URL via the static:build command', function () {
    Route::get('cli', fn () => 'cli body')
        ->middleware(StaticResponse::class);

    $this->artisan('static:build', ['url' => 'http://localhost/cli'])
        ->assertSuccessful();

    StaticCache::disk()->assertExists('localhost/GET/cli?.html');
});
