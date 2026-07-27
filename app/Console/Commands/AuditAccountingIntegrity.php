<?php

namespace App\Console\Commands;

use App\Services\AccountingIntegrityAuditService;
use Illuminate\Console\Command;

class AuditAccountingIntegrity extends Command
{
    protected $signature = 'evosyndic:audit-accounting {--organization=} {--residence=} {--json}';

    protected $description = 'Read-only accounting integrity audit';

    public function handle(AccountingIntegrityAuditService $service): int
    {
        $report = $service->run(array_filter(['organization' => $this->option('organization'), 'residence' => $this->option('residence')]));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line("Posted entries checked: {$report['checked']['posted_entries']}");
            foreach ($report['counts'] as $classification => $count) {
                $this->line("$classification: $count");
            }
            $report['ok'] ? $this->info('Accounting integrity audit passed.') : $this->error('Accounting integrity violations found.');
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
