<?php

namespace App\Console\Commands;

use App\Services\AccountingCarryForwardAuditService;
use Illuminate\Console\Command;

class AuditAccountingCarryForwards extends Command
{
    protected $signature = 'accounting:audit-carry-forwards
        {--organization=} {--residence=} {--book=} {--exercise=} {--package=} {--json}';

    protected $description = 'Read-only audit of accounting carry-forwards';

    public function handle(AccountingCarryForwardAuditService $audit): int
    {
        $result = $audit->audit(array_filter([
            'organization' => $this->option('organization'),
            'residence' => $this->option('residence'),
            'book' => $this->option('book'),
            'exercise' => $this->option('exercise'),
            'package' => $this->option('package'),
        ]));
        $this->option('json')
            ? $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            : $this->line("Carry-forwards: {$result['checked']['carry_forward_batches']}; violations: {$result['violation_count']}");

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
