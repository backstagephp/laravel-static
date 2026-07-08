<?php

namespace Backstage\Static\Laravel\Jobs;

use Backstage\Static\Laravel\Facades\StaticCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Queued, targeted refresh of a single page's static cache. Dispatch this after
 * the data behind a page changes to re-render just that URL, instead of clearing
 * and rebuilding the whole site:
 *
 *     BuildStaticPage::dispatch($model->url());
 *
 * A no-op when static caching is disabled, and a failed render is reported
 * rather than thrown — the page simply keeps its previous cached copy until the
 * next build.
 */
class BuildStaticPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $url) {}

    public function handle(): void
    {
        if (! config('static.enabled')) {
            return;
        }

        try {
            StaticCache::build($this->url);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
