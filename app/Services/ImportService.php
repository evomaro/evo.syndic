<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ImportBatch;
use App\Models\Lot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportService
{
    public function rows(ImportBatch $batch): array
    {
        $sheet = IOFactory::load(Storage::disk('local')->path($batch->stored_path))->getActiveSheet()->toArray(null, true, true, false);
        if (! $sheet) {
            return [];
        }
        $headers = array_map(fn ($value) => trim((string) $value), array_shift($sheet));

        return array_values(array_filter(array_map(fn ($row) => array_combine($headers, array_pad($row, count($headers), null)), $sheet), fn ($row) => collect($row)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty()));
    }

    public function process(ImportBatch $batch): void
    {
        $batch->refresh();
        if (in_array($batch->status, ['completed', 'completed_with_errors', 'rolled_back'], true)) {
            return;
        }
        $batch->update(['status' => 'processing', 'processing_started_at' => $batch->processing_started_at ?? now()]);
        foreach ($this->rows($batch) as $index => $source) {
            $number = $index + 2;
            if ($batch->rows()->where('row_number', $number)->whereIn('status', ['created', 'updated', 'skipped'])->exists()) {
                continue;
            }
            $row = collect($batch->column_mapping)->mapWithKeys(fn ($column, $target) => [$target => $source[$column] ?? null])->all();
            try {
                DB::transaction(function () use ($batch, $source, $row, $number) {
                    $result = $this->processRow($batch, $row);
                    $batch->rows()->updateOrCreate(['row_number' => $number], [
                        'status' => $result['action'], 'action' => $result['action'], 'subject_type' => $result['model']::class, 'subject_id' => $result['model']->id,
                        'source_values' => $source, 'before_values' => $result['before'], 'after_values' => $result['model']->fresh()->getAttributes(), 'error' => null, 'processed_at' => now(),
                    ]);
                });
            } catch (\Throwable $exception) {
                $batch->rows()->updateOrCreate(['row_number' => $number], ['status' => 'failed', 'action' => 'failed', 'source_values' => $source, 'error' => $exception->getMessage(), 'processed_at' => now()]);
            }
            $this->refreshCounts($batch);
        }
        $this->refreshCounts($batch);
        $batch->update(['status' => $batch->failed_rows ? 'completed_with_errors' : 'completed', 'completed_at' => now(), 'report' => $this->report($batch)]);
    }

    public function rollback(ImportBatch $batch): array
    {
        $report = ['reversed' => 0, 'blocked' => []];
        DB::transaction(function () use ($batch, &$report) {
            foreach ($batch->rows()->whereIn('status', ['created', 'updated'])->latest('row_number')->get() as $row) {
                $model = $row->subject_type::find($row->subject_id);
                if (! $model) {
                    continue;
                }
                if (! $this->unchanged($model, $row->after_values ?? [])) {
                    $report['blocked'][] = ['row' => $row->row_number, 'reason' => 'modified_after_import'];

                    continue;
                }
                if ($row->action === 'created') {
                    $dependency = $this->dependency($model);
                    if ($dependency) {
                        $report['blocked'][] = ['row' => $row->row_number, 'reason' => $dependency];

                        continue;
                    }
                    $model->delete();
                } else {
                    $model->forceFill(collect($row->before_values)->except(['id', 'created_at', 'updated_at'])->all())->saveQuietly();
                }
                $row->update(['status' => 'rolled_back']);
                $report['reversed']++;
            }
            $batch->update(['status' => 'rolled_back', 'rolled_back_at' => now(), 'report' => $report]);
        });

        return $report;
    }

    private function processRow(ImportBatch $batch, array $row): array
    {
        $residence = $batch->residence;
        $organization = $batch->organization;
        if ($batch->type === 'lots') {
            if (blank($row['reference'] ?? null)) {
                throw new \InvalidArgumentException('reference_required');
            }

            return $this->save($residence->lots()->firstOrNew(['reference' => trim($row['reference'])]), ['lot_number' => ($row['lot_number'] ?? null) ?: $row['reference'], 'type' => ($row['type'] ?? null) ?: 'apartment', 'surface' => ($row['surface'] ?? null) ?: null]);
        }
        if ($batch->type === 'contacts') {
            $key = filled($row['cin'] ?? null) ? ['cin' => trim($row['cin'])] : (filled($row['primary_email'] ?? null) ? ['primary_email' => strtolower(trim($row['primary_email']))] : (filled($row['primary_phone'] ?? null) ? ['primary_phone' => trim($row['primary_phone'])] : null));
            if (! $key) {
                throw new \InvalidArgumentException('contact_identifier_required');
            }

            return $this->save($organization->contacts()->firstOrNew($key), ['type' => ($row['type'] ?? null) ?: 'individual', 'first_name' => ($row['first_name'] ?? null) ?: null, 'last_name' => ($row['last_name'] ?? null) ?: null, 'company_name' => ($row['company_name'] ?? null) ?: null, 'preferred_language' => ($row['preferred_language'] ?? null) ?: 'fr']);
        }
        $lot = $residence->lots()->where('reference', $row['lot_reference'] ?? null)->firstOrFail();
        if ($batch->type === 'allocations') {
            $key = $residence->allocationKeys()->where('code', ($row['allocation_key_code'] ?? null) ?: 'general')->firstOrFail();
            if (! is_numeric($row['value'] ?? null) || (float) $row['value'] < 0) {
                throw new \InvalidArgumentException('allocation_value_invalid');
            }

            return $this->save($key->values()->firstOrNew(['lot_id' => $lot->id]), ['value' => $row['value']]);
        }
        $identifier = $row['contact_identifier'] ?? null;
        $contact = $organization->contacts()->where(fn ($query) => $query->where('cin', $identifier)->orWhere('primary_email', $identifier)->orWhere('primary_phone', $identifier))->firstOrFail();
        if ($batch->type === 'ownerships') {
            if ((float) $row['percentage'] <= 0 || (float) $row['percentage'] > 100) {
                throw new \InvalidArgumentException('ownership_percentage_invalid');
            }

            return $this->save($lot->ownerships()->firstOrNew(['contact_id' => $contact->id, 'starts_on' => $row['starts_on']]), ['ownership_percentage' => $row['percentage'], 'is_primary_contact' => filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOL)]);
        }
        if ($batch->type === 'occupancies') {
            return $this->save($lot->occupancies()->firstOrNew(['contact_id' => $contact->id, 'starts_on' => $row['starts_on']]), ['type' => ($row['occupancy_type'] ?? null) ?: 'other', 'is_primary_occupant' => filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOL)]);
        }
        throw new \InvalidArgumentException('unsupported_import_type');
    }

    private function save(Model $model, array $attributes): array
    {
        $exists = $model->exists;
        $before = $exists ? $model->getAttributes() : null;
        $model->fill($attributes);
        if ($exists && ! $model->isDirty()) {
            return ['model' => $model, 'action' => 'skipped', 'before' => $before];
        }
        $model->save();

        return ['model' => $model, 'action' => $exists ? 'updated' : 'created', 'before' => $before];
    }

    private function refreshCounts(ImportBatch $batch): void
    {
        $counts = $batch->rows()->selectRaw('status, count(*) aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $batch->update(['processed_rows' => $counts->sum(), 'created_rows' => $counts['created'] ?? 0, 'updated_rows' => $counts['updated'] ?? 0, 'skipped_rows' => $counts['skipped'] ?? 0, 'failed_rows' => $counts['failed'] ?? 0]);
        $batch->refresh();
    }

    private function report(ImportBatch $batch): array
    {
        return ['created' => $batch->created_rows, 'updated' => $batch->updated_rows, 'skipped' => $batch->skipped_rows, 'failed' => $batch->failed_rows];
    }

    private function unchanged(Model $model, array $after): bool
    {
        return collect($after)->except(['updated_at'])->every(fn ($value, $key) => (string) $model->getAttribute($key) === (string) $value);
    }

    private function dependency(Model $model): ?string
    {
        if ($model instanceof Lot && ($model->ownerships()->exists() || $model->occupancies()->exists() || $model->allocationValues()->exists())) {
            return 'lot_has_later_dependencies';
        }
        if ($model instanceof Contact && ($model->ownerships()->exists() || $model->occupancies()->exists())) {
            return 'contact_has_later_dependencies';
        }

        return null;
    }
}
