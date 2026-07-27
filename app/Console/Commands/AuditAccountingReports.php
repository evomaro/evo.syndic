<?php

namespace App\Console\Commands;

use App\Services\AccountingReportIntegrityAuditService;
use Illuminate\Console\Command;

class AuditAccountingReports extends Command
{
    protected $signature = 'evosyndic:audit-accounting-reports
        {--organization= : Organization ID}
        {--residence= : Residence ID}
        {--exercise= : Financial exercise ID}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only audit of accounting report balances and source reconciliation';

    public function handle(AccountingReportIntegrityAuditService $audit): int
    {
        $result = $audit->audit([
            'organization' => $this->option('organization'),
            'residence' => $this->option('residence'),
            'exercise' => $this->option('exercise'),
        ]);
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Reports checked: {$result['checked']['reports']}");
            $this->line("Violations: {$result['violation_count']}");
            foreach ($result['classifications'] as $classification => $count) {
                $this->line(" - {$classification}: {$count}");
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
