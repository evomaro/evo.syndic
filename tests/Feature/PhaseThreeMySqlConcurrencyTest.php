<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseThreeMySqlConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_expense_locking_profile_is_available(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the dedicated MySQL integration configuration for concurrent invoice and settlement validation.');
        }
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertNotNull(DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(1, DB::selectOne("SELECT GET_LOCK('evosyndic-phase03-settlement-test', 1) AS acquired")->acquired);
        DB::selectOne("SELECT RELEASE_LOCK('evosyndic-phase03-settlement-test')");
    }
}
