<?php

namespace Backstage\Static\Laravel\Commands;

use Backstage\Static\Laravel\Crawler\CrawlerV8Runner;
use Backstage\Static\Laravel\Crawler\CrawlerV9Runner;
use Backstage\Static\Laravel\Middleware\StaticResponse;
use Backstage\Static\Laravel\StaticCache;
use Exception;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Spatie\Crawler\Crawler;

class StaticBuildCommand extends Command
{
    public $signature = 'static:build';

    public $description = 'Build Static version';

    protected Repository $config;

    protected StaticCache $static;

    public function __construct(Repository $config, StaticCache $static)
    {
        parent::__construct();

        $this->config = $config;
        $this->static = $static;
    }

    public function handle(): void
    {
        if ($this->config->get('static.build.clear_before_start')) {
            $this->call(StaticClearCommand::class);
        }

        if ($this->config->get('static.build.force_root_url')) {
            URL::forceRootUrl($this->config->get('app.url'));
        }

        match ($driver = $this->config->get('static.driver', 'routes')) {
            'crawler' => $this->cacheWithCrawler(),
            'routes' => $this->cacheWithRoutes(),
            default => throw new Exception('Static driver '.$driver.' is not supported'),
        };
    }

    public function cacheWithRoutes(): void
    {
        /**
         * @var Collection<\Illuminate\Routing\Route> $routes
         */
        $routes = collect(Route::getRoutes()->get('GET'))->filter(
            fn ($route) => in_array(StaticResponse::class, Route::gatherRouteMiddleware($route)),
        );

        $failed = 0;

        foreach ($routes as $route) {
            $request = Request::create($route->uri());

            $request->headers->set('User-Agent', 'LaravelStatic/1.0');

            $response = Route::dispatchToRoute($request);

            if (count($route->parameterNames()) !== 0) {
                $name = $route->getName() ?? $route->uri();

                $this->components->warn('Skipping route: '.$name.', cannot cache routes with parameters');

                continue;
            }

            if (! $response->isOk()) {
                $this->components->error('Failed to cache route '.$route->uri());

                $failed++;

                continue;
            }

            $this->components->info('Route '.$route->uri().' has been cached');
        }

        if ($failed > 0) {
            $this->components->warn('Failed to cache '.$failed.' routes');
        }
    }

    public function cacheWithCrawler(): void
    {
        // spatie/crawler v9 introduced addObserver() as part of its API
        // rework; its presence tells the two majors apart. PHPStan analyses
        // against a single installed major, so it sees this as constant.
        // @phpstan-ignore function.alreadyNarrowedType
        $runner = method_exists(Crawler::class, 'addObserver')
            ? new CrawlerV9Runner($this->config, $this->components)
            : new CrawlerV8Runner($this->config, $this->components);

        $runner->crawl();
    }
}
