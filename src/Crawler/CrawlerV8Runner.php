<?php

namespace Backstage\Static\Laravel\Crawler;

use GuzzleHttp\RequestOptions;
use Illuminate\Config\Repository;
use Illuminate\Console\View\Components\Factory as ComponentFactory;
use Spatie\Crawler\Crawler;

/**
 * Runs the site crawl using the spatie/crawler v8 API.
 *
 * Version-specific: excluded from static analysis, since only one
 * spatie/crawler major version is installed at a time.
 */
class CrawlerV8Runner
{
    public function __construct(
        protected Repository $config,
        protected ComponentFactory $components,
    ) {}

    public function crawl(): void
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
