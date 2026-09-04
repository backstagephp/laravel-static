<?php

namespace Backstage\Static\Laravel\Commands;

use Backstage\Static\Laravel\Middleware\StaticResponse;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reports what the static HTML cache actually holds, so a cache that is filling
 * up a webserver can be diagnosed before limits get imposed on it.
 *
 * One file is written per request URI *including its query string* (see
 * {@see StaticResponse::generateFilepath()})
 * and nothing is ever removed except by a full `static:clear`. The growth is
 * therefore rarely the pages themselves but their variants: every ?utm_source=…
 * a bot or a newsletter hands out becomes its own file, forever. The two tables
 * that answer "why is this 40 GB" are "top URLs by variants" and "most common
 * query parameters" — the rest is context.
 */
class StaticStatusCommand extends Command
{
    public $signature = 'static:status
                         {--d|disk= : Filesystem disk to inspect, defaults to the configured static disk}
                         {--l|limit=15 : Number of rows per top-N table}
                         {--json : Output the report as JSON instead of tables}';

    public $description = 'Show what the static cache contains and what is driving its size';

    /**
     * Content extensions the middleware appends, see StaticResponse::getFileExtension().
     */
    protected const TYPES = ['html', 'json', 'xml'];

    /**
     * Precompressed siblings written next to a cached file.
     */
    protected const COMPRESSION = ['gz', 'br'];

    /**
     * Upper bounds of the age buckets, in seconds.
     */
    protected const AGES = [
        '< 1 day' => 86400,
        '1 - 7 days' => 604800,
        '7 - 30 days' => 2592000,
        '30 - 90 days' => 7776000,
        '> 90 days' => PHP_INT_MAX,
    ];

    public function handle(): int
    {
        $disk = $this->option('disk') ?: config('static.files.disk');
        $driver = config('filesystems.disks.'.$disk.'.driver');

        if (is_null($driver)) {
            $this->components->error('Disk ['.$disk.'] is not configured.');

            return self::FAILURE;
        }

        // Walking the tree needs real paths, and cheap size/mtime stats. A remote
        // disk would mean one API call per file, which for the multi-million-file
        // caches this command exists to diagnose is not a report, it's an outage.
        if ($driver !== 'local') {
            $this->components->error('Disk ['.$disk.'] uses the ['.$driver.'] driver; only local disks can be inspected.');

            return self::FAILURE;
        }

        $root = rtrim(Storage::disk($disk)->path(''), '/');

        if (! is_dir($root)) {
            $this->components->warn('Static cache directory ['.$root.'] does not exist yet.');

            return self::SUCCESS;
        }

        $report = $this->scan($root);

        if ($report['files'] === 0) {
            $this->components->info('Static cache is empty ('.$root.').');

            return self::SUCCESS;
        }

        $this->option('json')
            ? $this->renderJson($disk, $root, $report)
            : $this->render($disk, $root, $report);

        return self::SUCCESS;
    }

    /**
     * Walk the cache directory once, accumulating everything the report needs.
     *
     * Memory stays proportional to the number of distinct URL *paths* (the query
     * string is folded into a counter), not to the number of files — which is the
     * whole point, since it's the file count that runs away.
     */
    protected function scan(string $root): array
    {
        $limit = max(1, (int) $this->option('limit'));
        $now = time();

        $files = $bytes = $pages = 0;
        $byHost = $byType = $byUri = $byParam = $byAge = $byDir = [];
        $shape = [
            'query' => ['pages' => 0, 'files' => 0, 'bytes' => 0],
            'plain' => ['pages' => 0, 'files' => 0, 'bytes' => 0],
        ];
        $largest = [];
        $smallestKept = 0;
        $oldest = $newest = null;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            $path = $file->getPathname();
            $size = $file->getSize();
            $mtime = $file->getMTime();

            $files++;
            $bytes += $size;

            $oldest = is_null($oldest) ? $mtime : min($oldest, $mtime);
            $newest = is_null($newest) ? $mtime : max($newest, $mtime);

            $relative = substr($path, strlen($root) + 1);
            $parts = $this->parse($relative);

            $this->rollUp($byDir, dirname($relative), $size);

            // What the user is really paying for: storage held by URLs that only
            // differ in their query string.
            $bucket = $parts['query'] === '' ? 'plain' : 'query';
            $shape[$bucket]['files']++;
            $shape[$bucket]['bytes'] += $size;

            $label = ($parts['type'] ?? 'other').($parts['compression'] ? '.'.$parts['compression'] : '');
            $byType[$label]['files'] = ($byType[$label]['files'] ?? 0) + 1;
            $byType[$label]['bytes'] = ($byType[$label]['bytes'] ?? 0) + $size;

            $host = $parts['host'] ?? '—';
            $byHost[$host]['bytes'] = ($byHost[$host]['bytes'] ?? 0) + $size;
            $byHost[$host]['files'] = ($byHost[$host]['files'] ?? 0) + 1;

            $byUri[$host.$parts['uri']]['bytes'] = ($byUri[$host.$parts['uri']]['bytes'] ?? 0) + $size;

            foreach (self::AGES as $age => $ceiling) {
                if ($now - $mtime < $ceiling) {
                    $byAge[$age]['files'] = ($byAge[$age]['files'] ?? 0) + 1;
                    $byAge[$age]['bytes'] = ($byAge[$age]['bytes'] ?? 0) + $size;

                    break;
                }
            }

            if ($size > $smallestKept || count($largest) < $limit) {
                $largest[] = [
                    'path' => $parts['host'].$parts['uri']
                        .($parts['query'] ? '?'.$parts['query'] : '')
                        .($parts['compression'] ? ' ['.$parts['compression'].']' : ''),
                    'bytes' => $size,
                ];

                // Trim lazily rather than on every insert: sorting a 2N list once
                // per N additions is far cheaper than keeping it ordered.
                if (count($largest) >= $limit * 2) {
                    $largest = $this->takeLargest($largest, $limit);
                    $smallestKept = end($largest)['bytes'];
                }
            }

            // A .gz/.br sibling is the same page stored twice, so it may add to the
            // byte totals above but must not count as another cached page below.
            if ($parts['compression'] && is_file($this->stripSuffix($path, $parts['compression']))) {
                continue;
            }

            $pages++;
            $shape[$bucket]['pages']++;
            $byHost[$host]['pages'] = ($byHost[$host]['pages'] ?? 0) + 1;
            $byUri[$host.$parts['uri']]['variants'] = ($byUri[$host.$parts['uri']]['variants'] ?? 0) + 1;

            foreach ($this->parameterNames($parts['query']) as $name) {
                $byParam[$name] = ($byParam[$name] ?? 0) + 1;
            }
        }

        $uris = count($byUri);

        arsort($byParam);
        uasort($byHost, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
        uasort($byType, fn ($a, $b) => $b['files'] <=> $a['files']);

        $byVariants = $byUri;
        uasort($byVariants, fn ($a, $b) => [$b['variants'] ?? 0, $b['bytes']] <=> [$a['variants'] ?? 0, $a['bytes']]);

        return [
            'files' => $files,
            'bytes' => $bytes,
            'pages' => $pages,
            'uris' => $uris,
            'oldest' => $oldest,
            'newest' => $newest,
            'hosts' => $byHost,
            'types' => $byType,
            'ages' => $byAge,
            'shape' => $shape,
            'directories' => array_slice($this->collapse($byDir), 0, $limit, true),
            'params' => array_slice($byParam, 0, $limit, true),
            'variants' => array_slice($byVariants, 0, $limit, true),
            'largest' => $this->takeLargest($largest, $limit),
        ];
    }

    /**
     * Split a cached file's relative path back into the request it was written for.
     *
     * The layout is "<host>/<METHOD>/<uri>?<query><.ext>[.gz|.br]", where the "?"
     * is always present because the middleware appends it unconditionally — which
     * makes it a reliable separator even when the query string is empty.
     */
    protected function parse(string $relative): array
    {
        $segments = array_values(array_filter(explode('/', $relative), fn ($segment) => $segment !== ''));

        $host = null;

        if (config('static.files.include_domain') && count($segments) > 1) {
            $host = array_shift($segments);
        }

        // The method segment is written by the middleware, but stays optional here
        // so the report doesn't collapse on a cache from an older layout.
        if ($segments !== [] && preg_match('/^[A-Z]+$/', $segments[0])) {
            array_shift($segments);
        }

        $name = implode('/', $segments);

        [$uri, $tail] = array_pad(explode('?', $name, 2), 2, '');

        $compression = null;

        foreach (self::COMPRESSION as $extension) {
            if (str_ends_with($tail, '.'.$extension)) {
                $compression = $extension;
                $tail = $this->stripSuffix($tail, $extension);

                break;
            }
        }

        $type = null;

        foreach (self::TYPES as $extension) {
            if (str_ends_with($tail, '.'.$extension)) {
                $type = $extension;
                $tail = $this->stripSuffix($tail, $extension);

                break;
            }
        }

        return [
            'host' => $host,
            'uri' => '/'.ltrim($uri, '/'),
            'query' => $tail,
            'type' => $type,
            'compression' => $compression,
        ];
    }

    /**
     * Parameter names only: the values are the unbounded part, and it's the names
     * that identify which integration is multiplying your cache.
     */
    protected function parameterNames(string $query): array
    {
        if ($query === '') {
            return [];
        }

        return collect(explode('&', $query))
            ->map(fn ($pair) => urldecode(Str::before($pair, '=')))
            ->filter()
            ->unique()
            ->all();
    }

    /**
     * Add a file's size to every directory above it, so each directory ends up
     * reporting the cumulative size of its whole subtree — what `du` shows.
     */
    protected function rollUp(array &$directories, string $directory, int $size): void
    {
        while (true) {
            $directories[$directory]['files'] = ($directories[$directory]['files'] ?? 0) + 1;
            $directories[$directory]['bytes'] = ($directories[$directory]['bytes'] ?? 0) + $size;

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }
    }

    /**
     * Drop directory levels holding exactly the same bytes as their parent.
     *
     * Every file lives under "<host>/<METHOD>/", so without this the top of the
     * table is always those two structural levels at 100% of the cache. A level
     * that adds nothing over its parent says nothing about where the size sits;
     * a second host, or the first branch point below it, does and survives.
     */
    protected function collapse(array $directories): array
    {
        return collect($directories)
            ->reject(fn ($row, $directory) => $directory === '.'
                || ($directories[dirname($directory)]['bytes'] ?? null) === $row['bytes'])
            ->sortByDesc('bytes')
            ->all();
    }

    protected function stripSuffix(string $value, string $extension): string
    {
        return substr($value, 0, -(strlen($extension) + 1));
    }

    protected function takeLargest(array $files, int $limit): array
    {
        usort($files, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

        return array_slice($files, 0, $limit);
    }

    protected function render(string $disk, string $root, array $report): void
    {
        $this->newLine();
        $this->components->info('Static cache on disk ['.$disk.'] — '.$root);

        $this->components->twoColumnDetail('<fg=gray>Files on disk</>', number_format($report['files']));
        $this->components->twoColumnDetail('<fg=gray>Size on disk</>', $this->bytes($report['bytes']));
        $this->components->twoColumnDetail('<fg=gray>Cached pages</>', number_format($report['pages']).' <fg=gray>(compressed copies excluded)</>');
        $this->components->twoColumnDetail('<fg=gray>Distinct URLs</>', number_format($report['uris']));
        $this->components->twoColumnDetail('<fg=gray>Average page</>', $this->bytes((int) ($report['bytes'] / max(1, $report['pages']))));
        $this->components->twoColumnDetail('<fg=gray>Oldest write</>', $this->age($report['oldest']));
        $this->components->twoColumnDetail('<fg=gray>Newest write</>', $this->age($report['newest']));

        $this->newLine();

        // The headline number: how much of the cache is the same page over and over.
        $overhead = $report['pages'] - $report['uris'];

        if ($overhead > 0) {
            $share = round($overhead / max(1, $report['pages']) * 100);

            $this->components->warn(
                number_format($overhead).' of '.number_format($report['pages']).' cached pages ('.$share.'%) are extra '
                .'query-string variants of '.number_format($report['uris']).' distinct URLs.'
            );
        }

        $this->heading('Storage held by URLs with and without a query string');

        $this->table(['URL shape', 'Pages', 'Files', 'Size', 'Share'], [
            ['With query string', number_format($report['shape']['query']['pages']), number_format($report['shape']['query']['files']), $this->bytes($report['shape']['query']['bytes']), $this->share($report['shape']['query']['bytes'], $report['bytes'])],
            ['Plain URL', number_format($report['shape']['plain']['pages']), number_format($report['shape']['plain']['files']), $this->bytes($report['shape']['plain']['bytes']), $this->share($report['shape']['plain']['bytes'], $report['bytes'])],
        ]);

        $this->heading('Largest directories, cumulative — levels identical to their parent are collapsed');

        $this->table(['Directory', 'Files', 'Size', 'Share'], collect($report['directories'])
            ->map(fn ($row, $directory) => [
                Str::limit($directory, 60),
                number_format($row['files']),
                $this->bytes($row['bytes']),
                $this->share($row['bytes'], $report['bytes']),
            ])->values()->all());

        $this->table(['Host', 'Pages', 'Files', 'Size'], collect($report['hosts'])
            ->map(fn ($row, $host) => [
                $host,
                number_format($row['pages'] ?? 0),
                number_format($row['files']),
                $this->bytes($row['bytes']),
            ])->values()->all());

        $this->table(['File type', 'Files', 'Size'], collect($report['types'])
            ->map(fn ($row, $type) => [$type, number_format($row['files']), $this->bytes($row['bytes'])])
            ->values()->all());

        $this->heading('Top URLs by number of cached variants');

        $this->table(['URL', 'Variants', 'Size'], collect($report['variants'])
            ->map(fn ($row, $uri) => [
                Str::limit($uri, 70),
                number_format($row['variants'] ?? 0),
                $this->bytes($row['bytes']),
            ])->values()->all());

        if ($report['params'] !== []) {
            $this->heading('Most common query parameters');

            $this->table(['Query parameter', 'Cached pages'], collect($report['params'])
                ->map(fn ($count, $name) => [$name, number_format($count)])
                ->values()->all());
        }

        $this->heading('Time since a file was last written — old entries are prune candidates');

        $this->table(['Age', 'Files', 'Size'], collect(self::AGES)
            ->keys()
            ->filter(fn ($bucket) => isset($report['ages'][$bucket]))
            ->map(fn ($bucket) => [
                $bucket,
                number_format($report['ages'][$bucket]['files']),
                $this->bytes($report['ages'][$bucket]['bytes']),
            ])->values()->all());

        $this->table(['Largest files', 'Size'], collect($report['largest'])
            ->map(fn ($file) => [Str::limit($file['path'], 70), $this->bytes($file['bytes'])])
            ->all());

        $this->renderSettings();
    }

    /**
     * The config that decides how fast this cache grows, printed alongside the
     * numbers so the levers are visible from the same screen as the problem.
     */
    protected function renderSettings(): void
    {
        $this->components->info('Settings that affect cache size');

        $this->components->twoColumnDetail('<fg=gray>static.files.include_query_string</>', $this->flag(config('static.files.include_query_string')));
        $this->components->twoColumnDetail('<fg=gray>static.files.include_domain</>', $this->flag(config('static.files.include_domain')));

        // A published config file may predate the compression feature, so these
        // keys are treated as optional rather than assumed.
        if (! is_null(config('static.compression'))) {
            $this->components->twoColumnDetail('<fg=gray>static.compression.gzip</>', $this->flag(config('static.compression.gzip')));
            $this->components->twoColumnDetail('<fg=gray>static.compression.brotli</>', $this->flag(config('static.compression.brotli')));
            $this->components->twoColumnDetail('<fg=gray>static.compression.keep_uncompressed</>', $this->flag(config('static.compression.keep_uncompressed')));
        }

        $this->newLine();
    }

    protected function renderJson(string $disk, string $root, array $report): void
    {
        $this->line((string) json_encode([
            'disk' => $disk,
            'root' => $root,
            'files' => $report['files'],
            'bytes' => $report['bytes'],
            'pages' => $report['pages'],
            'uris' => $report['uris'],
            'oldest' => $report['oldest'],
            'newest' => $report['newest'],
            'hosts' => $report['hosts'],
            'types' => $report['types'],
            'ages' => $report['ages'],
            'shape' => $report['shape'],
            'directories' => $report['directories'],
            'params' => $report['params'],
            'variants' => $report['variants'],
            'largest' => $report['largest'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function heading(string $title): void
    {
        $this->newLine();
        $this->line('  <fg=gray>'.$title.'</>');
    }

    protected function share(int $bytes, int $total): string
    {
        return round($bytes / max(1, $total) * 100).'%';
    }

    protected function flag(mixed $value): string
    {
        return $value ? '<fg=yellow>enabled</>' : '<fg=gray>disabled</>';
    }

    protected function age(?int $timestamp): string
    {
        if (is_null($timestamp)) {
            return '—';
        }

        return date('Y-m-d H:i', $timestamp).' <fg=gray>('.Carbon::createFromTimestamp($timestamp)->diffForHumans().')</>';
    }

    protected function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0
            ? $bytes.' B'
            : sprintf('%.1f %s', $value, $units[$unit]);
    }
}
