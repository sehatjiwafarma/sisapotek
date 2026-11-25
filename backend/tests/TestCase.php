<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();
        $driver = $connection->getConfig('driver');
        $database = $connection->getDatabaseName();

        if (! $app->environment('testing') || ! (
            ($driver === 'sqlite' && $database === ':memory:')
            || ($driver === 'pgsql' && $database === 'apotek_test')
        )) {
            throw new \RuntimeException('Pengujian diblokir: database harus SQLite :memory: atau PostgreSQL apotek_test. Koneksi saat ini: '.$driver.'/'.$database);
        }

        // Separate PHP test processes must not erase each other's fake uploads or compiled views.
        if (empty($_SERVER['TEST_TOKEN'])) {
            $app->make(ParallelTesting::class)->resolveTokenUsing(fn (): string => (string) getmypid());
        }
        $compiled = sys_get_temp_dir().'/apotek-test-views-'.getmypid();
        $app->make('files')->ensureDirectoryExists($compiled);
        $app->make('config')->set('view.compiled', $compiled);

        return $app;
    }
}
