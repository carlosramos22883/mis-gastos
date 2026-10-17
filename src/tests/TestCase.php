<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seguro: RefreshDatabase borra todas las tablas. Nunca debe correr
     * contra la base de desarrollo (Docker define DB_CONNECTION=pgsql).
     */
    protected function beforeRefreshingDatabase()
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Los tests deben usar SQLite en memoria; se evitó borrar la base '.config('database.default').'.');
        }
    }
}
