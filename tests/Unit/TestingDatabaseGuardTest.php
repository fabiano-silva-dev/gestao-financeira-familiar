<?php

namespace Tests\Unit;

use Tests\TestCase;

class TestingDatabaseGuardTest extends TestCase
{
    public function test_phpunit_uses_the_testing_database(): void
    {
        $this->assertSame(
            'gestao_financeira_familiar_testing',
            config('database.connections.pgsql.database'),
        );
    }
}
