<?php

namespace Backstage\Static\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem as Files;
use Illuminate\Filesystem\FilesystemManager as Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class StaticCache
{
    public function __construct(
        protected Repository $config,
        protected Files $files,
        protected Storage $storage,
    ) {}

    public function clear(?array $paths = null): bool
    {
        if (! is_null($paths)) {
            return $this->disk()->delete($this->withCompressedVariants($paths));
        }

        return $this->files->cleanDirectory($this->disk()->getConfig()['root']);
    }

    /**
     * Clear only the cached files that were generated for a request carrying a
     * query string, leaving the plain (query-less) pages untouched.
     *
     * Cached filenames always embed a "?" delimiter before the extension: a
     * query-less page is stored as "page?.html", while a query-string variant is
     * stored as "page?foo=bar.html". A non-empty query string is therefore any
     * file whose "?" is followed by something other than the extension dot —
     * which also matches the ".gz"/".br" siblings without extra work.
     *
     * @return array<int, string> the paths that were deleted
     */
    public function clearQueryStrings(): array
    {
        $disk = $this->disk();

        $paths = collect($disk->allFiles())
            ->filter(fn (string $path): bool => (bool) preg_match('/\?[^.]/', basename($path)))
            ->values()
            ->all();

        if (! empty($paths)) {
            $disk->delete($paths);
        }

        return $paths;
    }

    /**
     * Expand a list of static file paths to also include their precompressed
     * siblings (.gz / .br), so a targeted clear doesn't leave orphaned compressed
     * copies behind. Deleting a path that doesn't exist is a harmless no-op.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    protected function withCompressedVariants(array $paths): array
    {
        return collect($paths)
            ->flatMap(fn (string $path) => [$path, $path.'.gz', $path.'.br'])
            ->all();
    }

    /**
     * Render one or more URLs into the static cache without crawling the whole
     * site. Each URL is dispatched through its own route so its StaticResponse
     * middleware writes a fresh file for just that page — the targeted
     * counterpart to clear(). A URL whose route isn't static-cached simply
     * writes nothing; a route that aborts (e.g. a 404) throws, so callers that
     * refresh pages which may have disappeared should guard or catch.
     *
     * @param  string|array<int, string>  $urls
     */
    public function build(string|array $urls): void
    {
        foreach ((array) $urls as $url) {
            $request = Request::create($url);

            // Match the crawler's fingerprint so the render is identical to a
            // full `static:build` pass.
            $request->headers->set('User-Agent', 'LaravelStatic/1.0');

            Route::dispatchToRoute($request);
        }
    }

    public function disk(?string $override = null)
    {
        return $this->storage->disk($override ?? $this->config->get('static.files.disk'));
    }
}
