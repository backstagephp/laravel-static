<?php

use Illuminate\Support\Facades\Route;
use voku\helper\HtmlMin;
use Backstage\Static\Laravel\Facades\StaticCache;
use Backstage\Static\Laravel\Middleware\StaticResponse;

it('can cache a page response', function ($route) {
    config([
        'static.files.disk' => 'local',
    ]);

    $disk = StaticCache::disk();

    Route::get($route, fn () => $route)
        ->middleware(StaticResponse::class);

    $this->get($route);

    $path = "localhost/GET/{$route}?.html";

    $disk->assertExists($path);

    $content = $disk->get($path);

    expect($content)
        ->toBeString()
        ->toBe($route);
})->with(['hello', '1289bwa jk912UIwa', '*!@)(!', '123=']);

it('strips a leading index.php segment from the cached path', function () {
    config([
        'static.files.disk' => 'local',
    ]);

    $disk = StaticCache::disk();

    Route::get('index.php/about', fn () => 'about')
        ->middleware(StaticResponse::class);

    $this->get('index.php/about');

    $disk->assertExists('localhost/GET/about?.html');
    $disk->assertMissing('localhost/GET/index.php/about?.html');

    expect($disk->get('localhost/GET/about?.html'))->toBe('about');
});

it('does not cache when the request host is not whitelisted', function () {
    config([
        'static.files.disk' => 'local',
        'static.whitelist.hosts' => ['allowed.test'],
    ]);

    $disk = StaticCache::disk();

    Route::get('blocked', fn () => 'blocked')
        ->middleware(StaticResponse::class);

    $this->get('http://other.test/blocked');

    $disk->assertMissing('other.test/GET/blocked?.html');
});

it('caches subdomains matching a wildcard host', function () {
    config([
        'static.files.disk' => 'local',
        'static.whitelist.hosts' => ['*.example.com'],
    ]);

    $disk = StaticCache::disk();

    Route::get('sub', fn () => 'sub')
        ->middleware(StaticResponse::class);

    $this->get('http://blog.example.com/sub');
    $this->get('http://example.com/sub');
    $this->get('http://other.test/sub');

    $disk->assertExists('blog.example.com/GET/sub?.html');
    $disk->assertMissing('example.com/GET/sub?.html');
    $disk->assertMissing('other.test/GET/sub?.html');
});

it('caches for any host when the whitelist is null', function () {
    config([
        'static.files.disk' => 'local',
        'static.whitelist.hosts' => null,
    ]);

    $disk = StaticCache::disk();

    Route::get('anywhere', fn () => 'anywhere')
        ->middleware(StaticResponse::class);

    $this->get('http://random.test/anywhere');

    $disk->assertExists('random.test/GET/anywhere?.html');
});

it('writes a gzip sibling next to the static file', function () {
    config([
        'static.files.disk' => 'local',
        'static.compression.gzip' => true,
    ]);

    $disk = StaticCache::disk();

    Route::get('gz', fn () => 'gzipped content')
        ->middleware(StaticResponse::class);

    $this->get('gz');

    $disk->assertExists('localhost/GET/gz?.html');
    $disk->assertExists('localhost/GET/gz?.html.gz');

    expect(gzdecode($disk->get('localhost/GET/gz?.html.gz')))
        ->toBe('gzipped content');
});

it('does not write a gzip sibling when gzip is disabled', function () {
    config([
        'static.files.disk' => 'local',
        'static.compression.gzip' => false,
    ]);

    $disk = StaticCache::disk();

    Route::get('nogz', fn () => 'plain')
        ->middleware(StaticResponse::class);

    $this->get('nogz');

    $disk->assertExists('localhost/GET/nogz?.html');
    $disk->assertMissing('localhost/GET/nogz?.html.gz');
});

it('stores only the compressed file when keep_uncompressed is disabled', function () {
    config([
        'static.files.disk' => 'local',
        'static.compression.gzip' => true,
        'static.compression.keep_uncompressed' => false,
    ]);

    $disk = StaticCache::disk();

    Route::get('only-gz', fn () => 'compressed only')
        ->middleware(StaticResponse::class);

    $this->get('only-gz');

    $disk->assertMissing('localhost/GET/only-gz?.html');
    $disk->assertExists('localhost/GET/only-gz?.html.gz');

    expect(gzdecode($disk->get('localhost/GET/only-gz?.html.gz')))
        ->toBe('compressed only');
});

it('keeps the uncompressed file when nothing could be compressed', function () {
    // brotli requested but ext-brotli may be unavailable, and gzip is off, so no
    // compressed file is produced — the plain copy must survive regardless.
    config([
        'static.files.disk' => 'local',
        'static.compression.gzip' => false,
        'static.compression.brotli' => false,
        'static.compression.keep_uncompressed' => false,
    ]);

    $disk = StaticCache::disk();

    Route::get('fallback', fn () => 'still here')
        ->middleware(StaticResponse::class);

    $this->get('fallback');

    $disk->assertExists('localhost/GET/fallback?.html');
    expect($disk->get('localhost/GET/fallback?.html'))->toBe('still here');
});

it('removes compressed siblings on a targeted clear', function () {
    config([
        'static.files.disk' => 'local',
        'static.compression.gzip' => true,
    ]);

    $disk = StaticCache::disk();

    Route::get('drop', fn () => 'drop me')
        ->middleware(StaticResponse::class);

    $this->get('drop');

    $disk->assertExists('localhost/GET/drop?.html.gz');

    StaticCache::clear(['localhost/GET/drop?.html']);

    $disk->assertMissing('localhost/GET/drop?.html');
    $disk->assertMissing('localhost/GET/drop?.html.gz');
});

it('minifies HTML', function () {
    config([
        'static.files.disk' => 'local',
        'static.options.minify_html' => true,
    ]);

    $disk = StaticCache::disk();

    $html = <<<'HTML'
<h1>Hello!</h1>
<h2>Hello</h2>
HTML;

    $minified = (new HtmlMin)->minify($html);

    Route::get('/', fn () => $html)
        ->middleware(StaticResponse::class);

    $this->get('/');

    $actual = $disk->get('localhost/GET/?.html');

    expect($actual)
        ->toBeString()
        ->toBe($minified);
});
