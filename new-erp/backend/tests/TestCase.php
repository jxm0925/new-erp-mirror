<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $database = (string) ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');

        if (strtolower($database) !== 'erp_sdjiantan') {
            throw new \RuntimeException(
                "Refusing to run database tests against unsafe database [{$database}]. ".
                'DB_DATABASE must equal erp_sdjiantan.'
            );
        }

        parent::setUp();
    }
}
