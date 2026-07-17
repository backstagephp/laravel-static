<?php

use Backstage\Static\Laravel\Crawler\StaticCrawlObserver;
use Spatie\Crawler\CrawlProfiles\CrawlInternalUrls;

return [
    /**
     * The driver that will be used to cache your pages.
     * This can be either 'crawler' or 'routes'.
     */
    'driver' => 'crawler',

    /**
     * Enable or disable static caching (to quickly disable the creation of the static cache without detaching the middleware).
     * Don't forget to clear the static cache if needed, this does does not happen using this setting.
     */
    'enabled' => env('STATIC_ENABLED', true),

    /**
     * Restrict which hostnames static caches may be created for.
     */
    'whitelist' => [
        /**
         * Whitelist of hostnames for which static caches may be created. Matched
         * against the request host and supports "*" wildcards, e.g. "*.example.com"
         * matches any subdomain (but not the apex example.com, which must be listed
         * separately). When null, caches are created for every hostname. Provide an
         * array of hostnames to only cache those hosts (e.g. when the app is reachable
         * through multiple domains). To restrict caching to the app's own hostname
         * (and its www variant), use:
         *
         * 'hosts' => [
         *     $host = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
         *     'www.' . $host,
         * ],
         */
        'hosts' => null,
    ],

    'build' => [
        /**
         * Clear static files before building static cache.
         * When disabled, the cache is warmed up rather by updating and overwriting files instead of starting without an existing cache.
         */
        'clear_before_start' => true,

        /**
         * Number of concurrent http requests to build static cache.
         */
        'concurrency' => 5,

        /**
         * Whether to follow links on pages.
         */
        'accept_no_follow' => true,

        /**
         * The default scheme the crawler will use.
         */
        'default_scheme' => 'https',

        /**
         * Force the root URL used when generating links to config('app.url').
         * Enable this when your server serves the app through index.php (e.g. missing
         * URL rewriting), which would otherwise leak "index.php" into generated links.
         * Note: this only affects the 'routes' driver, since the 'crawler' driver
         * renders pages in a separate HTTP request. It also overrides root URL
         * generation for the duration of the build process.
         */
        'force_root_url' => env('STATIC_FORCE_ROOT_URL', false),

        /**
         * The crawl observer that will be used to handle crawl related events.
         */
        'crawl_observer' => StaticCrawlObserver::class,

        /**
         * The crawl profile that will be used by the crawler.
         */
        'crawl_profile' => CrawlInternalUrls::class,

        /**
         * HTTP header that can be used to bypass the cache. Useful for updating the cache without needing to clear it first,
         * or to monitor the performance of your application.
         */
        'bypass_header' => [
            'X-Laravel-Static' => 'off',
        ],
    ],

    'files' => [
        /**
         * The filesystem disk that will be used to cache your pages.
         */
        'disk' => env('STATIC_DISK', 'public'),

        /**
         * Different caches per domain.
         */
        'include_domain' => true,

        /**
         * When query string is included, every unique query string combination creates a new static file.
         * When disabled, the URL is marked as identical regardless of the query string.
         */
        'include_query_string' => true,

        /**
         * Set file path maximum length (determined by operating system config)
         */
        'filepath_max_length' => 4096,

        /**
         * Set filename maximum length (determined by operating system config)
         */
        'filename_max_length' => 255,
    ],

    'options' => [
        /**
         * Define if you want to save the static cache after response has been sent to browser.
         */
        'on_termination' => false,

        /**
         * Minify HTML before saving static file.
         */
        'minify_html' => false,
    ],

    /**
     * Write precompressed copies of each static file alongside the original, so a
     * web server can serve them directly without compressing on the fly. nginx does
     * this with `gzip_static on;` (built in) and `brotli_static on;` (requires the
     * third-party ngx_brotli module); both look for a `<file>.gz` / `<file>.br`
     * sibling matching the requested URI.
     */
    'compression' => [
        /**
         * Write a `.gz` copy of each static file. gzip is built into PHP, so this
         * has no extra dependencies.
         */
        'gzip' => env('STATIC_COMPRESS_GZIP', true),

        /**
         * Compression level for the `.gz` copy (0-9). Higher is smaller but slower.
         * Compression happens once at cache-write time, so a high level is usually
         * worth it.
         */
        'gzip_level' => 9,

        /**
         * Write a `.br` (brotli) copy of each static file. Requires the ext-brotli
         * PHP extension; when it isn't installed this is silently skipped.
         */
        'brotli' => env('STATIC_COMPRESS_BROTLI', false),

        /**
         * Compression quality for the `.br` copy (0-11). 11 is smallest but slow.
         */
        'brotli_level' => 11,

        /**
         * Also keep the uncompressed copy alongside the compressed one(s). Keeping
         * it lets the web server fall back to plain output for the rare client that
         * doesn't accept gzip/brotli. Disable it to store only the compressed file
         * and halve disk usage — this requires serving the compressed file to every
         * client (e.g. nginx `gzip_static always;` with `gunzip on;`). Ignored when
         * no compression format actually produced a file, so a page is never left
         * with nothing to serve.
         */
        'keep_uncompressed' => true,
    ],
];
