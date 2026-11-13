<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Publicala\NightwatchCap\NightwatchCapServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [NightwatchCapServiceProvider::class];
    }
}
