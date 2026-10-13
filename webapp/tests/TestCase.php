<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Most tests run in "single database" mode: no company is opened, and the central tables and the company tables
     * live side by side in the one in-memory database, so every test sees the whole schema. The multi-company
     * behaviour has its own tests (tests/Feature/Tenancy) that create real company databases.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->make('migrator')->path(database_path('migrations/tenant'));

        return $app;
    }
}
