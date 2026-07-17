<?php

namespace Backstage\Static\Laravel\Middleware;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use voku\helper\HtmlMin;
use Backstage\Static\Laravel\Facades\StaticCache;

class StaticResponse
{
    protected Repository $config;

    protected array $bypassHeader;

    public function __construct(Repository $config)
    {
        $this->config = $config;

        $this->bypassHeader = $this->config->get('static.build.bypass_header');
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (
            ! $this->config->get('static.options.on_termination') &&
            $this->shouldBeStatic($request, $response)
        ) {
            $response = $this->minifyResponse($response);

            $this->createStaticFile($request, $response);
        }

        return $response;
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     */
    public function terminate(Request $request, $response): void
    {
        if (
            $this->config->get('static.options.on_termination') &&
            $this->shouldBeStatic($request, $response)
        ) {
            $response = $this->minifyResponse($response);

            $this->createStaticFile($request, $response);
        }
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     */
    protected function shouldBeStatic(Request $request, $response): bool
    {
        return
            $this->config->get('static.enabled') === true &&
            $this->hostIsAllowed($request) &&
            $response->getStatusCode() == 200 &&
            (
                $request->isMethod('GET') ||
                // TTFB checkers use HEAD requests,
                // therefore we treat them the same as GET
                $request->isMethod('HEAD')
            );
    }

    /**
     * Determine whether static caches may be created for the request's host.
     * A null whitelist allows every host; otherwise only the configured hosts
     * are cached.
     */
    protected function hostIsAllowed(Request $request): bool
    {
        $hosts = $this->config->get('static.whitelist.hosts');

        if (is_null($hosts)) {
            return true;
        }

        // getHost() is already lowercased by Symfony; lowercase the configured
        // hosts too so the comparison is case-insensitive (hostnames are per RFC).
        // Str::is() supports "*" wildcards, e.g. "*.example.com" matches any
        // subdomain (but not the apex example.com, which must be listed separately).
        $hosts = array_map('strtolower', (array) $hosts);

        return Str::is($hosts, $request->getHost());
    }

    /**
     * Join an array of paths to a string
     */
    public function joinPaths(array $paths): string
    {
        return collect($paths)->map(function ($path) {
            if (is_array($path)) {
                return implode('/', $path);
            }

            return $path;
        })->implode('/');
    }

    /**
     * Minify response.
     */
    public function minifyResponse($response)
    {
        if (! $this->config->get('static.options.minify_html')) {
            return $response;
        }

        if (! str_starts_with($response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $response->setContent(
            (new HtmlMin)
                ->minify($response->getContent())
        );

        return $response;
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     */
    public function createStaticFile(Request $request, $response): void
    {
        $filePath = $this->generateFilepath($request, $response);

        $filePath = $this->joinPaths([
            $request->getHost(),
            $request->method(),
            $filePath,
        ]);

        if ($this->exceedsMaxLength($filePath)) {
            return;
        }

        $disk = StaticCache::disk();

        if (! $disk->exists('.gitignore')) {
            $disk->put('.gitignore', '*' . PHP_EOL . '!.gitignore');
        }

        if ($content = $response->getContent()) {
            $wroteCompressed = $this->writeCompressedVariants($disk, $filePath, $content);

            // Skip the uncompressed copy only when precompression actually
            // produced a file to serve instead — otherwise we'd store nothing
            // (e.g. brotli requested but ext-brotli missing).
            if ($wroteCompressed && ! $this->config->get('static.compression.keep_uncompressed', true)) {
                $disk->delete($filePath);
            } else {
                $disk->put($filePath, $content, true);
            }
        }
    }

    /**
     * Write precompressed siblings (.gz / .br) next to the static file so a web
     * server can serve them directly (e.g. nginx gzip_static / brotli_static)
     * instead of compressing on every request. Brotli is skipped silently when
     * the ext-brotli extension isn't installed. Returns whether at least one
     * compressed file was written.
     */
    protected function writeCompressedVariants($disk, string $filePath, string $content): bool
    {
        $wrote = false;

        if ($this->config->get('static.compression.gzip')) {
            $level = (int) $this->config->get('static.compression.gzip_level', 9);

            $disk->put($filePath . '.gz', gzencode($content, $level), true);

            $wrote = true;
        }

        if ($this->config->get('static.compression.brotli') && function_exists('brotli_compress')) {
            $level = (int) $this->config->get('static.compression.brotli_level', 11);

            $disk->put($filePath . '.br', brotli_compress($content, $level), true);

            $wrote = true;
        }

        return $wrote;
    }

    /**
     * Get URI.
     */
    public function getUri(Request $request): string
    {
        return trim($request->getPathInfo(), '/') ?: '/';
    }

    /**
     * Get domain from request.
     */
    public function getDomain(Request $request): ?string
    {
        return $request->server('HTTP_HOST');
    }

    /**
     * Get base path for generating file path.
     */
    public function basePath(Request $request): string
    {
        $path = $this->getDiskPath();

        if ($this->config->get('static.files.include_domain')) {
            $path .= '/' . $this->getDomain($request);
        }

        return $path;
    }

    public function getDiskPath()
    {
        return rtrim($this->config->get('filesystems.disks.' . $this->config->get('static.files.disk') . '.root'), '/');
    }

    /**
     * Get file extension based on response content type.
     */
    protected function getFileExtension($filename, $response): ?string
    {
        $contentType = $response->headers->get('Content-Type');

        $extension = 'html';

        if (
            $response instanceof JsonResponse ||
            $contentType == 'application/json'
        ) {
            $extension = 'json';
        }

        if (
            str_starts_with($contentType, 'text/xml') ||
            str_starts_with($contentType, 'application/xml')
        ) {
            $extension = 'xml';
        }

        if (str_ends_with($filename, $extension)) {
            return null;
        }

        return '.' . $extension;
    }

    /**
     * Generate static file path based on request following a matching pattern configured in Nginx
     */
    public function generateFilepath(Request $request, $response): string
    {
        $filePath = $request->getPathInfo();

        // Strip a leading "index.php" segment so misconfigured servers (missing URL
        // rewriting) don't pollute the cached file path, e.g. /index.php/about -> /about.
        $filePath = preg_replace('#^/index\.php(/|$)#', '/', $filePath);

        $filePath .= '?';

        if (
            $this->config->get('static.files.include_query_string') &&
            ! blank($request->server('QUERY_STRING'))
        ) {
            $filePath .= $request->server('QUERY_STRING');
        }

        $filePath .= $this->getFileExtension($filePath, $response);

        return $filePath;
    }

    /**
     * Check maximum filepath and filename length.
     */
    public function exceedsMaxLength(string $filepath): bool
    {
        $filenameLength = strlen(basename($filepath));

        if ($filenameLength >= $this->config->get('static.files.filename_max_length')) {
            return true;
        }

        $filepathLength = strlen($filepath);

        if ($filepathLength >= $this->config->get('static.files.filepath_max_length')) {
            return true;
        }

        return false;
    }
}
