# Laravel Static

[![Total Downloads](https://img.shields.io/packagist/dt/backstage/laravel-static.svg?style=flat-square)](https://packagist.org/packages/backstage/laravel-static)
[![Tests](https://github.com/backstagephp/laravel-static/actions/workflows/run-tests.yml/badge.svg?branch=main)](https://github.com/backstagephp/laravel-static/actions/workflows/run-tests.yml)
[![PHPStan](https://github.com/backstagephp/laravel-static/actions/workflows/phpstan.yml/badge.svg?branch=main)](https://github.com/backstagephp/laravel-static/actions/workflows/phpstan.yml)
![GitHub release (latest by date)](https://img.shields.io/github/v/release/backstagephp/laravel-static)
![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/backstage/laravel-static)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/backstage/laravel-static.svg?style=flat-square)](https://packagist.org/packages/backstage/laravel-static)

**Supercharge your Laravel application with static file caching.** Laravel Static converts your dynamic Laravel responses into static HTML files, dramatically improving performance and reducing server load.

## Why Laravel Static?

Traditional Laravel applications generate HTML on every request, hitting your database and executing PHP code repeatedly. Laravel Static solves this by:

- **Converting dynamic responses to static HTML files** — Serve pre-generated HTML instead of executing PHP on every request
- **Reducing server load** — Let your web server (Nginx, Apache) serve static files directly
- **Improving response times** — Static files are served in milliseconds, not hundreds of milliseconds
- **Supporting multiple caching strategies** — Choose between route-based caching or automatic web crawling
- **Handling complex scenarios** — Multi-domain support, query string handling, and HTML minification

## Requirements

- PHP 8.1 or higher
- Laravel 11.0 or higher

## Installation

Install the package via Composer:

```bash
composer require backstage/laravel-static
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="laravel-static-config"
```

Optionally, publish the migrations if you need database-backed features:

```bash
php artisan vendor:publish --tag="laravel-static-migrations"
php artisan migrate
```

## Quick Start

### 1. Enable Static Caching

Add the `STATIC_ENABLED=true` environment variable to your `.env` file:

```env
STATIC_ENABLED=true
```

### 2. Add Middleware to Routes

Apply the `StaticResponse` middleware to routes you want to cache:

```php
use Backstage\LaravelStatic\Middleware\StaticResponse;

Route::get('/', function () {
    return view('welcome');
})->middleware(StaticResponse::class);

// Or apply to route groups
Route::middleware([StaticResponse::class])->group(function () {
    Route::get('/about', [PageController::class, 'about']);
    Route::get('/contact', [PageController::class, 'contact']);
    Route::get('/blog', [BlogController::class, 'index']);
});
```

### 3. Build the Static Cache

Generate your static files:

```bash
php artisan static:build
```

That's it! Your routes are now served as static HTML files.

## Configuration

The configuration file is located at `config/static.php`. Here's a breakdown of all available options:

### Caching Driver

```php
'driver' => 'crawler', // Options: 'crawler' or 'routes'
```

| Driver | Description |
|--------|-------------|
| `crawler` | Uses Spatie Crawler to automatically discover and cache all internal URLs starting from your homepage. Best for sites with many interconnected pages. |
| `routes` | Only caches routes that have the `StaticResponse` middleware explicitly applied. Best for selective caching. |

### Enable/Disable

```php
'enabled' => env('STATIC_ENABLED', true),
```

Toggle static caching on or off. Useful for disabling in development while keeping it enabled in production.

### Build Settings

```php
'build' => [
    'clear_before_start' => true,    // Clear existing cache before rebuilding
    'concurrency' => 5,               // Number of concurrent HTTP requests
    'accept_no_follow' => true,       // Follow nofollow links when crawling
    'default_scheme' => 'https',      // URL scheme for crawler requests
    'crawl_observer' => \Backstage\LaravelStatic\Crawler\StaticCrawlObserver::class,
    'crawl_profile' => \Spatie\Crawler\CrawlProfiles\CrawlInternalUrls::class,
    'bypass_header' => [
        'name' => 'X-Laravel-Static',
        'value' => 'off',
    ],
],
```

### File Storage

```php
'files' => [
    'disk' => env('STATIC_DISK', 'public'),  // Laravel filesystem disk
    'include_domain' => true,                 // Create separate caches per domain
    'include_query_string' => true,           // Include query strings in cache keys
    'filepath_max_length' => 4096,            // Maximum file path length
    'filename_max_length' => 255,             // Maximum filename length
],
```

### Additional Options

```php
'options' => [
    'on_termination' => false,  // Save cache after response sent (async)
    'minify_html' => false,     // Minify HTML before caching
],
```

## Commands

### Build Static Cache

Generate static files for all configured routes:

```bash
php artisan static:build
```

When using the `routes` driver, only routes with the `StaticResponse` middleware are cached. When using the `crawler` driver, the crawler starts from your homepage and discovers all internal links.

### Clear Static Cache

Clear all cached static files:

```bash
php artisan static:clear
```

Clear specific URIs:

```bash
php artisan static:clear --uri=/about --uri=/contact
```

Clear by route names:

```bash
php artisan static:clear --routes=home --routes=about --routes=blog.index
```

Clear by domain (useful for multi-tenant applications):

```bash
php artisan static:clear --domain=example.com
php artisan static:clear --domain=subdomain.example.com
```

## Advanced Usage

### Multi-Domain Support

Laravel Static supports multi-domain setups out of the box. When `include_domain` is enabled (default), each domain gets its own cache directory:

```
storage/app/public/
├── example.com/
│   ├── GET/
│   │   ├── index.html
│   │   └── about.html
├── subdomain.example.com/
│   ├── GET/
│   │   └── index.html
```

### Query String Handling

When `include_query_string` is enabled, different query strings create separate cache files:

```
/products?page=1  → products/page=1.html
/products?page=2  → products/page=2.html
/search?q=laravel → search/q=laravel.html
```

### HTML Minification

Enable HTML minification to reduce file sizes:

```php
// config/static.php
'options' => [
    'minify_html' => true,
],
```

This removes unnecessary whitespace, comments, and optimizes the HTML output using the [voku/html-min](https://github.com/voku/HtmlMin) library.

### Bypass Header

During development or testing, you may want to bypass the static cache. The package includes a bypass header mechanism:

```bash
curl -H "X-Laravel-Static: off" https://example.com/
```

This header tells the middleware to skip the static cache and generate a fresh response.

### Programmatic Cache Clearing

Use the `StaticCache` facade to clear cache programmatically:

```php
use Backstage\LaravelStatic\Facades\StaticCache;

// Clear all cache
StaticCache::clear();

// Clear specific paths
StaticCache::clear(['/about', '/contact']);
```

### Custom Crawl Observer

Create a custom crawl observer to customize the crawling behavior:

```php
namespace App\Crawlers;

use Backstage\LaravelStatic\Crawler\StaticCrawlObserver;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\ResponseInterface;

class CustomCrawlObserver extends StaticCrawlObserver
{
    public function crawled(UriInterface $url, ResponseInterface $response, ?UriInterface $foundOnUrl = null): void
    {
        // Add custom logic before caching
        logger()->info("Caching: {$url}");

        parent::crawled($url, $response, $foundOnUrl);
    }
}
```

Update your configuration:

```php
'build' => [
    'crawl_observer' => \App\Crawlers\CustomCrawlObserver::class,
],
```

### Custom Crawl Profile

Control which URLs get crawled by creating a custom crawl profile:

```php
namespace App\Crawlers;

use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlProfiles\CrawlProfile;

class CustomCrawlProfile extends CrawlProfile
{
    public function shouldCrawl(UriInterface $url): bool
    {
        $path = $url->getPath();

        // Skip admin routes
        if (str_starts_with($path, '/admin')) {
            return false;
        }

        // Skip API routes
        if (str_starts_with($path, '/api')) {
            return false;
        }

        return true;
    }
}
```

### Excluding Routes from Caching

Routes with parameters cannot be automatically cached (they require specific values). You can also explicitly exclude routes by not applying the middleware:

```php
// These routes will be cached
Route::middleware([StaticResponse::class])->group(function () {
    Route::get('/', [HomeController::class, 'index']);
    Route::get('/about', [PageController::class, 'about']);
});

// These routes will NOT be cached (no middleware)
Route::get('/dashboard', [DashboardController::class, 'index']);
Route::get('/user/{id}', [UserController::class, 'show']); // Has parameters
```

### Async Cache Generation

Enable `on_termination` to generate cache files after the response is sent to the user:

```php
'options' => [
    'on_termination' => true,
],
```

This improves perceived performance as users don't wait for the cache file to be written.

## Web Server Configuration

For optimal performance, configure your web server to serve static files directly without hitting PHP.

### Nginx

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/html/public;

    # Try static cache first, then Laravel
    location / {
        # Check for static cache file
        set $cache_path /storage/example.com/GET$uri;

        # Handle index files
        if (-f $document_root$cache_path/index.html) {
            rewrite ^ $cache_path/index.html last;
        }

        # Handle direct files
        if (-f $document_root$cache_path.html) {
            rewrite ^ $cache_path.html last;
        }

        # Fall back to Laravel
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Apache

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Check for static cache
    RewriteCond %{DOCUMENT_ROOT}/storage/%{HTTP_HOST}/GET%{REQUEST_URI}.html -f
    RewriteRule ^(.*)$ /storage/%{HTTP_HOST}/GET/$1.html [L]

    RewriteCond %{DOCUMENT_ROOT}/storage/%{HTTP_HOST}/GET%{REQUEST_URI}/index.html -f
    RewriteRule ^(.*)$ /storage/%{HTTP_HOST}/GET/$1/index.html [L]

    # Laravel fallback
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [L]
</IfModule>
```

## Cache Invalidation Strategies

### Event-Based Invalidation

Clear cache when content changes using model events:

```php
use Backstage\LaravelStatic\Facades\StaticCache;

class Post extends Model
{
    protected static function booted()
    {
        static::saved(function (Post $post) {
            StaticCache::clear([
                "/blog/{$post->slug}",
                '/blog',
            ]);
        });

        static::deleted(function (Post $post) {
            StaticCache::clear([
                "/blog/{$post->slug}",
                '/blog',
            ]);
        });
    }
}
```

### Scheduled Rebuilds

Add a scheduled task to rebuild your cache periodically:

```php
// app/Console/Kernel.php or bootstrap/app.php (Laravel 11+)
Schedule::command('static:build')->daily();
```

### Deploy Hook

Clear and rebuild cache during deployments:

```bash
#!/bin/bash
# deploy.sh

php artisan static:clear
php artisan static:build
```

## Comparison: Routes vs Crawler Driver

| Feature | Routes Driver | Crawler Driver |
|---------|--------------|----------------|
| Setup complexity | Manual (add middleware to each route) | Automatic (discovers all pages) |
| Control | Fine-grained | Less control |
| Speed | Faster (only caches specified routes) | Slower (crawls entire site) |
| Discovery | Manual | Automatic |
| Best for | Selective caching, large apps | Content sites, blogs |

## How It Works

1. **Request Interception**: The `StaticResponse` middleware intercepts outgoing responses
2. **Eligibility Check**: Only `GET`/`HEAD` requests with `200 OK` status are cached
3. **File Generation**: HTML content is saved to the configured storage disk
4. **Optional Minification**: If enabled, HTML is minified before saving
5. **Directory Structure**: Files are organized by domain, HTTP method, and URI path

The `PreventStaticResponseMiddleware` (automatically registered) handles bypass headers and ensures proper behavior during cache building.

## Troubleshooting

### Cache Not Being Generated

1. Ensure `STATIC_ENABLED=true` is set in your `.env`
2. Verify the `StaticResponse` middleware is applied to your routes
3. Check that the storage disk is writable
4. Routes with parameters cannot be cached automatically
5. Only `200 OK` responses are cached

### Cache Not Being Served

1. Verify static files exist in your storage directory
2. Check web server configuration
3. Ensure the bypass header is not being sent accidentally

### Crawler Not Finding Pages

1. Check if pages are linked from the homepage
2. Verify `accept_no_follow` setting if using `rel="nofollow"` links
3. Review your crawl profile configuration
4. Note: JavaScript-rendered content is not supported

### File Path Too Long

If you encounter file path length errors:

1. Check the `filepath_max_length` and `filename_max_length` settings
2. Consider using shorter URLs or disabling query string caching
3. The package will skip files that exceed the configured limits

## Testing

Run the test suite:

```bash
composer test
```

Run tests with coverage:

```bash
composer test-coverage
```

Run static analysis:

```bash
composer analyse
```

Format code:

```bash
composer format
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Mark van Eijk](https://github.com/backstagephp)
- [All Contributors](../../contributors)

Built with [Spatie Crawler](https://github.com/spatie/crawler) and [voku/HtmlMin](https://github.com/voku/HtmlMin).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
