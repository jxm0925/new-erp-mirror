<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $database = (string) ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');

        if (! str_ends_with(strtolower($database), '_test')) {
            throw new \RuntimeException(
                "Refusing to run database tests against unsafe database [{$database}]. ".
                'DB_DATABASE must end with _test.'
            );
        }

        parent::setUp();
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        // Cached configuration must not silently route a test transaction to the development database.
        $connection = $app->make('db')->connection();
        $configured = (string) $connection->getDatabaseName();
        if ($connection->getDriverName() !== 'mysql' || ! str_ends_with(strtolower($configured), '_test')) {
            throw new \RuntimeException("Refusing database tests: resolved MySQL database [{$configured}] must end with _test.");
        }
        return $app;
    }
}
