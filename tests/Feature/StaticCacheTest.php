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
