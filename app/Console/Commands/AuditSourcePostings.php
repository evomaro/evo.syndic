<?php

namespace App\Console\Commands;

use App\Services\SourcePostingIntegrityAuditService;
use Illuminate\Console\Command;

class AuditSourcePostings extends Command
{
    protected $signature = 'evosyndic:audit-source-postings
        {--organization= : Organization ID}
        {--residence= : Residence ID}
        {--source-domain= : fund_call, payment, payment_allocation, supplier_invoice, supplier_credit_note, supplier_settlement}
        {--from= : Inclusive source date}
        {--to= : Inclusive source date}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only audit of automated accounting source postings';

    public function handle(SourcePostingIntegrityAuditService $audit): int
    {
        $result = $audit->audit([
            'organization' => $this->option('organization'),
            'residence' => $this->option('residence'),
            'source_domain' => $this->option('source-domain'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
        ]);
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Source postings checked: {$result['checked']['source_postings']}");
            $this->line("Violations: {$result['violation_count']}");
            foreach ($result['classifications'] as $classification => $count) {
                $this->line(" - {$classification}: {$count}");
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
