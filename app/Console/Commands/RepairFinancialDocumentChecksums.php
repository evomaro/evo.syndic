<?php

namespace App\Console\Commands;

use App\Models\FinancialDocument;
use App\Services\FinancialDocumentRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RepairFinancialDocumentChecksums extends Command
{
    protected $signature = 'evosyndic:repair-financial-document-checksums
        {--record= : Target one financial document ID}
        {--receipt-only : Inspect only receipt documents}
        {--voucher-only : Inspect only supplier voucher documents}
        {--organization= : Restrict to one organization}
        {--residence= : Restrict to one residence}
        {--expected-evidence= : Require the dry-run evidence fingerprint for a targeted record}
        {--dry-run : Explicitly run read-only inspection}
        {--apply : Apply confirmed document recovery; otherwise always read-only}
        {--json : Print machine-readable output}';

    protected $description = 'Safely inspect or recover receipt and voucher document checksum mismatches';

    public function handle(FinancialDocumentRecoveryService $service): int
    {
        if ($this->option('receipt-only') && $this->option('voucher-only')) {
            return $this->finish(['ok' => false, 'error' => 'Receipt-only and voucher-only are mutually exclusive.'], self::INVALID);
        }
        if ($this->option('dry-run') && $this->option('apply')) {
            return $this->finish(['ok' => false, 'error' => 'Dry-run and apply are mutually exclusive.'], self::INVALID);
        }
        if ($this->option('expected-evidence') && ! $this->option('record')) {
            return $this->finish(['ok' => false, 'error' => 'Expected evidence requires --record.'], self::INVALID);
        }

        $documents = $this->query()->get();
        $apply = (bool) $this->option('apply');
        $executionId = (string) Str::uuid();
        $rows = [];
        $refused = 0;
        $repaired = 0;
        $repairable = 0;

        foreach ($documents as $document) {
            try {
                $row = $service->inspect($document);
                $repairable += $row['repairable'] ? 1 : 0;
                $row['outcome'] = $row['classification'] === 'valid' ? 'already_valid' : ($apply ? 'pending' : 'dry_run');

                if ($apply && $row['repairable']) {
                    $row = $service->repair(
                        $document->id,
                        $this->option('expected-evidence') ?: $row['evidence_fingerprint'],
                        $executionId,
                        'system:checksum-repair-command',
                        (bool) $this->option('expected-evidence'),
                    );
                    $repaired += $row['outcome'] === 'repaired' ? 1 : 0;
                } elseif ($row['classification'] !== 'valid' && ! $row['repairable']) {
                    $row['outcome'] = 'refused';
                    $refused++;
                }
            } catch (ValidationException $exception) {
                $row = [
                    'record_id' => $document->id,
                    'type' => $document->type,
                    'number' => $document->number,
                    'outcome' => 'refused',
                    'error' => collect($exception->errors())->flatten()->first(),
                ];
                $refused++;
            } catch (Throwable $exception) {
                report($exception);
                $row = [
                    'record_id' => $document->id,
                    'type' => $document->type,
                    'number' => $document->number,
                    'outcome' => 'failed',
                    'error' => 'Financial document recovery failed safely.',
                ];
                $refused++;
            }
            $rows[] = $row;
        }

        $summary = [
            'ok' => $refused === 0,
            'mode' => $apply ? 'apply' : 'dry-run',
            'execution_id' => $executionId,
            'selected' => $documents->count(),
            'repairable' => $repairable,
            'repaired' => $repaired,
            'refused' => $refused,
            'unchanged' => collect($rows)->where('outcome', 'already_valid')->count(),
            'records' => $rows,
        ];

        return $this->finish($summary, $refused === 0 ? self::SUCCESS : self::FAILURE);
    }

    private function query(): Builder
    {
        return FinancialDocument::query()
            ->whereIn('type', ['receipt', 'supplier_voucher'])
            ->when($this->option('record'), fn (Builder $query, string $id) => $query->whereKey((int) $id))
            ->when($this->option('receipt-only'), fn (Builder $query) => $query->where('type', 'receipt'))
            ->when($this->option('voucher-only'), fn (Builder $query) => $query->where('type', 'supplier_voucher'))
            ->when($this->option('organization'), fn (Builder $query, string $id) => $query->where('organization_id', (int) $id))
            ->when($this->option('residence'), fn (Builder $query, string $id) => $query->where('residence_id', (int) $id))
            ->orderBy('id');
    }

    private function finish(array $payload, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        if (isset($payload['records'])) {
            $this->table(
                ['ID', 'Type', 'Number', 'Classification', 'Outcome'],
                collect($payload['records'])->map(fn (array $row) => [
                    $row['record_id'] ?? '',
                    $row['type'] ?? '',
                    $row['number'] ?? '',
                    $row['classification'] ?? $row['error'] ?? '',
                    $row['outcome'] ?? '',
                ])->all(),
            );
            $this->line(sprintf(
                '%s: selected=%d repairable=%d repaired=%d refused=%d unchanged=%d',
                strtoupper($payload['mode']),
                $payload['selected'],
                $payload['repairable'],
                $payload['repaired'],
                $payload['refused'],
                $payload['unchanged'],
            ));
        } else {
            $this->error($payload['error'] ?? 'Checksum repair command failed.');
        }

        return $exitCode;
    }
}
