<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->environment('testing')) {
            return;
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            $this->fail(
                'Refusing to run tests on a non-isolated database (connection='.$connection.', database='.$database.'). '
                .'Use sqlite :memory: in phpunit.xml so tests never wipe your dev data.'
            );
        }
    }
}
