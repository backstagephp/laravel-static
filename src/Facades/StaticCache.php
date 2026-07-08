<?php

namespace Backstage\Static\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool clear(?array $paths = null)
 * @method static void build(string|array $urls)
 * @method static \Illuminate\Contracts\Filesystem\Filesystem disk(?string $override = null)
 *
 * @see \Backstage\Static\Laravel\StaticCache
 */
class StaticCache extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Backstage\Static\Laravel\StaticCache::class;
    }
}
