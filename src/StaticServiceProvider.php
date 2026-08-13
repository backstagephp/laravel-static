<?php

namespace Backstage\Static\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\URL;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Backstage\Static\Laravel\Commands\StaticBuildCommand;
use Backstage\Static\Laravel\Commands\StaticClearCommand;
use Backstage\Static\Laravel\Middleware\PreventStaticResponseMiddleware;

class StaticServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-static')
            ->hasConfigFile()
            ->hasCommands([
                StaticClearCommand::class,
                StaticBuildCommand::class,
            ]);
    }

    public function packageBooted()
    {
        // Pin link generation to app.url for served requests as well, not just
        // for builds: under the 'crawler' driver every page is rendered by a
        // separate HTTP request, so forcing the root inside the build command
        // alone would never reach the process that actually renders the HTML.
        if ($this->app['config']->get('static.build.force_root_url')) {
            URL::forceRootUrl($this->app['config']->get('app.url'));
        }

        $kernel = $this->app->make(Kernel::class);

        $kernel->prependMiddlewareToGroup(
            'web',
            PreventStaticResponseMiddleware::class
        );
    }
}
