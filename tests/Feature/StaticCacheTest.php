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

it('does not cache a response rendered under a base URL', function () {
    config([
        'static.files.disk' => 'local',
    ]);

    $disk = StaticCache::disk();

    $disk->delete('localhost/GET/prefixed?.html');

    Route::get('prefixed', fn () => 'prefixed')
        ->middleware(StaticResponse::class);

    // How nginx hands a request to PHP-FPM when the front controller is part of
    // the path: "%2e" is a dot, so this routes to /prefixed, but Symfony keeps
    // the escaped spelling as the base URL and prefixes every link generated on
    // the page with it.
    $this->call('GET', 'index%2ephp/prefixed', [], [], [], [
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => '/index.php',
    ])->assertOk();

    // Storing it would have replaced the real page with a prefixed copy.
    $disk->assertMissing('localhost/GET/prefixed?.html');
});

it('caches a response rendered under a base URL when the root url is forced', function () {
    config([
        'static.files.disk' => 'local',
        'static.build.force_root_url' => true,
    ]);

    $disk = StaticCache::disk();

    $disk->delete('localhost/GET/forced?.html');

    Route::get('forced', fn () => 'forced')
        ->middleware(StaticResponse::class);

    $this->call('GET', 'index%2ephp/forced', [], [], [], [
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => '/index.php',
    ])->assertOk();

    // Links are generated from app.url, so the render doesn't depend on how the
    // request came in and is safe to store.
    $disk->assertExists('localhost/GET/forced?.html');
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
