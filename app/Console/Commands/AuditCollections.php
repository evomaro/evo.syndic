<?php

namespace App\Console\Commands;

use App\Services\CollectionAuditService;
use Illuminate\Console\Command;

class AuditCollections extends Command
{
    protected $signature = 'evosyndic:audit-collections {--residence=} {--exercise=} {--payment=} {--fund-call=} {--json}';

    protected $description = 'Read-only reconciliation audit for EvoSyndic collections';

    public function handle(CollectionAuditService $audit): int
    {
        $result = $audit->audit([
            'residence' => $this->option('residence'), 'exercise' => $this->option('exercise'),
            'payment' => $this->option('payment'), 'fund_call' => $this->option('fund-call'),
        ]);
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf('Checked %d payments and %d charges.', $result['checked']['payments'], $result['checked']['charges']));
            foreach ($result['violations'] as $violation) {
                $this->error("{$violation['code']}: {$violation['entity']} {$violation['id']}");
            }
            $result['ok'] ? $this->info('Collection reconciliation passed.') : $this->error('Collection reconciliation failed.');
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
