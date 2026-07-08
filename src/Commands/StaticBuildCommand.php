<?php

namespace Backstage\Static\Laravel\Commands;

use Backstage\Static\Laravel\Middleware\StaticResponse;
use Backstage\Static\Laravel\StaticCache;
use Exception;
use GuzzleHttp\RequestOptions;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Spatie\Crawler\Crawler;

class StaticBuildCommand extends Command
{
    public $signature = 'static:build {url? : Build only this URL instead of crawling the whole site}';

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
        // A single URL rebuilds just that page: no clear, no crawl — the
        // targeted counterpart to `static:clear -u`.
        if ($url = $this->argument('url')) {
            $this->static->build($url);

            $this->components->info('Built static cache for: '.$url);

            return;
        }

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
        $bypassHeader = $this->config->get('static.build.bypass_header');

        $profile = new ($this->config->get('static.build.crawl_profile'))(
            $this->config->get('app.url'),
        );

        $observer = new ($this->config->get('static.build.crawl_observer'))(
            $this->components,
        );

        $crawler = Crawler::create([
            RequestOptions::VERIFY => ! app()->environment('local', 'testing'),
            RequestOptions::ALLOW_REDIRECTS => true,
            RequestOptions::HEADERS => [
                array_key_first($bypassHeader) => array_shift($bypassHeader),
                'User-Agent' => 'LaravelStatic/1.0',
            ],
        ])
            ->setCrawlObserver($observer)
            ->setCrawlProfile($profile)
            ->setConcurrency($this->config->get('static.build.concurrency'))
            ->setDefaultScheme($this->config->get('static.build.default_scheme'));

        if ($this->config->get('static.build.accept_no_follow')) {
            $crawler->acceptNofollowLinks();
        }

        $crawler->startCrawling($this->config->get('app.url'));
    }
}
