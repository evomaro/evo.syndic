<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseFourMySqlConcurrencyTest extends TestCase
{
    public function test_mysql_phase_four_locking_profile_is_available(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the dedicated isolated MySQL Phase 04 concurrency profile.');
        }
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertNotNull(DB::selectOne("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_quotations' AND INDEX_NAME = 'maint_quote_one_accepted'"));
        $this->assertNotNull(DB::selectOne("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_work_orders' AND INDEX_NAME = 'maint_wo_primary_request'"));
        $this->assertNotNull(DB::selectOne("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'preventive_interventions' AND INDEX_NAME = 'prevent_occurrence_uq'"));
    }
}
