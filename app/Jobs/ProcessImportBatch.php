<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Models\Residence;
use App\Models\User;
use App\Services\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessImportBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $batchId, public int $organizationId, public ?int $residenceId, public int $userId) {}

    public function handle(ImportService $service): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);
        abort_unless($batch->organization_id === $this->organizationId && $batch->residence_id === $this->residenceId && $batch->user_id === $this->userId, 403);
        $user = User::findOrFail($this->userId);
        abort_unless($user->belongsToOrganization($this->organizationId) && $user->canInOrganization('import_data', $batch->organization), 403);
        if ($this->residenceId) {
            $residence = Residence::where('organization_id', $this->organizationId)->findOrFail($this->residenceId);
            abort_if($residence->status === 'archived', 409);
        }
        $service->process($batch);
    }

    public function failed(\Throwable $exception): void
    {
        ImportBatch::whereKey($this->batchId)->update(['status' => 'failed', 'report' => ['message' => $exception->getMessage()]]);
    }
}
