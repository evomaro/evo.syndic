<?php

namespace App\Console\Commands;

use App\Services\AccountingClosingAuditService;
use Illuminate\Console\Command;

class AuditAccountingClosingPackages extends Command
{
    protected $signature = 'accounting:audit-closing-packages
        {--organization=} {--residence=} {--book=} {--exercise=} {--package=} {--json}';

    protected $description = 'Read-only audit of accounting closing packages';

    public function handle(AccountingClosingAuditService $audit): int
    {
        $result = $audit->audit($this->filters());
        $this->option('json')
            ? $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            : $this->line("Packages: {$result['checked']['packages']}; violations: {$result['violation_count']}");

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function filters(): array
    {
        return array_filter([
            'organization' => $this->option('organization'),
            'residence' => $this->option('residence'),
            'book' => $this->option('book'),
            'exercise' => $this->option('exercise'),
            'package' => $this->option('package'),
        ]);
    }
}
