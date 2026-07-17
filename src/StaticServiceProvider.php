<?php

namespace Backstage\Static\Laravel;

use Backstage\Static\Laravel\Commands\StaticBuildCommand;
use Backstage\Static\Laravel\Commands\StaticClearCommand;
use Backstage\Static\Laravel\Middleware\PreventStaticResponseMiddleware;
use Illuminate\Contracts\Http\Kernel;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
        $kernel = $this->app->make(Kernel::class);

        $kernel->prependMiddlewareToGroup(
            'web',
            PreventStaticResponseMiddleware::class
        );
    }
}
