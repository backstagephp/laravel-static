<?php

namespace Backstage\Static\Laravel\Tests;

use Backstage\Static\Laravel\StaticServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            StaticServiceProvider::class,
        ];
    }
}
