<?php

namespace Tests\Traits;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

trait RefreshAndKeepDatabase
{
    use RefreshDatabase;

    protected function refreshTestDatabase()
    {
        // If there is no migrations table, we presume db structure is not created.
        if (\Schema::hasTable('migrations')) {
            RefreshDatabaseState::$migrated = true;
        }
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan(
                'migrate:fresh',
                ['--path' => 'database/migrations/base'] + $this->migrateFreshUsing()
            );

            $this->app[Kernel::class]->setArtisan(null);
        }

        // Here we specifically don't run transaction, so all the data would stay in database.
        //$this->beginDatabaseTransaction();
    }
}
