<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication() {
        $app = parent::createApplication();
        if(config('database.default')!=='pgsql' || !str_ends_with(config('database.connections.pgsql.database'),'_test')) {
            throw new \RuntimeException('Tests require a dedicated PostgreSQL database ending in _test.');
        }
        return $app;
    }
}
