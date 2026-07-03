<?php

namespace Backstage\Static\Laravel\Crawler;

use GuzzleHttp\RequestOptions;
use Illuminate\Config\Repository;
use Illuminate\Console\View\Components\Factory as ComponentFactory;
use Spatie\Crawler\Crawler;

/**
 * Runs the site crawl using the spatie/crawler v9 API.
 *
 * Version-specific: excluded from static analysis, since only one
 * spatie/crawler major version is installed at a time.
 */
class CrawlerV9Runner
{
    public function __construct(
        protected Repository $config,
        protected ComponentFactory $components,
    ) {}

    public function crawl(): void
    {
        $bypassHeader = $this->config->get('static.build.bypass_header');

        $crawler = Crawler::create($this->config->get('app.url'), [
            RequestOptions::VERIFY => ! app()->environment('local', 'testing'),
            RequestOptions::ALLOW_REDIRECTS => true,
            RequestOptions::HEADERS => [
                array_key_first($bypassHeader) => array_shift($bypassHeader),
                'User-Agent' => 'LaravelStatic/1.0',
            ],
        ])
            ->crawlProfile(new ($this->config->get('static.build.crawl_profile'))(
                $this->config->get('app.url'),
            ))
            ->concurrency($this->config->get('static.build.concurrency'))
            ->defaultScheme($this->config->get('static.build.default_scheme'));

        if ($this->config->get('static.build.accept_no_follow')) {
            $crawler->followNofollow();
        }

        $observer = $this->config->get('static.build.crawl_observer');

        if ($observer && $observer !== StaticCrawlObserver::class) {
            // A custom observer must extend crawler v9's CrawlObserver.
            $crawler->addObserver(new $observer($this->components));
        } else {
            // The default StaticCrawlObserver targets the v8 observer
            // signatures and cannot load on v9; closures replace it.
            $crawler
                ->onCrawled(fn (string $url) => $this->components->info('Crawled and cached url: '.$url))
                ->onFailed(fn (string $url) => $this->components->error('Failed to crawl url: '.$url))
                ->onFinished(fn () => $this->components->info('Static cache build completed'));
        }

        $crawler->start();
    }
}
