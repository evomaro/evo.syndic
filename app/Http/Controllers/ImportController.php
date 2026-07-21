<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Services\ImportService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ImportController extends Controller
{
    public function index(TenantContext $context)
    {
        return Inertia::render('Imports/Index', ['batches' => ImportBatch::where('organization_id', $context->organization()->id)->withCount('rows')->latest()->paginate(20)]);
    }

    public function upload(Request $request, TenantContext $context, ImportService $service)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['lots', 'contacts', 'ownerships', 'occupancies', 'allocations'])], 'file' => 'required|file|max:10240|mimes:csv,txt,xlsx']);
        abort_if($data['type'] !== 'contacts' && ! $context->residence, 422);
        $path = $data['file']->store('imports');
        $batch = ImportBatch::create([
            'organization_id' => $context->organization()->id, 'residence_id' => $context->residence?->id, 'user_id' => $request->user()->id,
            'type' => $data['type'], 'original_filename' => $data['file']->getClientOriginalName(), 'mime_type' => $data['file']->getMimeType(), 'file_size' => $data['file']->getSize(),
            'file_hash' => hash_file('sha256', Storage::path($path)), 'stored_path' => $path, 'status' => 'uploaded',
        ]);
        $batch->update(['total_rows' => count($service->rows($batch)), 'status' => 'mapping']);

        return redirect()->route('imports.map', $batch);
    }

    public function map(ImportBatch $batch, TenantContext $context, ImportService $service)
    {
        $this->guard($batch, $context);
        $rows = $service->rows($batch);

        return Inertia::render('Imports/Map', ['batch' => $batch, 'columns' => array_keys($rows[0] ?? []), 'preview' => array_slice($rows, 0, 10)]);
    }

    public function confirm(Request $request, ImportBatch $batch, TenantContext $context)
    {
        $this->guard($batch, $context);
        abort_unless(in_array($batch->status, ['uploaded', 'mapping', 'pending', 'failed'], true), 409);
        $data = $request->validate(['mapping' => 'required|array']);
        $batch->update(['column_mapping' => $data['mapping'], 'status' => 'pending']);
        $job = new ProcessImportBatch($batch->id, $batch->organization_id, $batch->residence_id, $request->user()->id);
        if ($batch->total_rows <= config('imports.synchronous_threshold')) {
            dispatch_sync($job);
        } else {
            dispatch($job);
        }

        return redirect()->route('imports.index')->with('success', $batch->total_rows <= config('imports.synchronous_threshold') ? __('Import terminé.') : __('Import placé dans la file d’attente.'));
    }

    public function errors(ImportBatch $batch, TenantContext $context)
    {
        $this->guard($batch, $context);
        $lines = ['row,error'];
        foreach ($batch->rows()->where('status', 'failed')->orderBy('row_number')->get() as $row) {
            $lines[] = $row->row_number.',"'.str_replace('"', '""', $row->error).'"';
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=evosyndic-import-{$batch->id}-errors.csv"]);
    }

    public function rollback(ImportBatch $batch, TenantContext $context, ImportService $service)
    {
        $this->guard($batch, $context);
        abort_unless(in_array($batch->status, ['completed', 'completed_with_errors'], true), 409);
        $service->rollback($batch);

        return back()->with('success', __('Annulation de l’import terminée.'));
    }

    public function template(string $type)
    {
        $headers = ['lots' => ['reference', 'lot_number', 'type', 'surface'], 'contacts' => ['type', 'first_name', 'last_name', 'company_name', 'cin', 'primary_email', 'primary_phone', 'preferred_language'], 'ownerships' => ['lot_reference', 'contact_identifier', 'percentage', 'is_primary', 'starts_on'], 'occupancies' => ['lot_reference', 'contact_identifier', 'occupancy_type', 'is_primary', 'starts_on'], 'allocations' => ['lot_reference', 'allocation_key_code', 'value']][$type] ?? abort(404);

        return response(implode(',', $headers)."\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=evosyndic-$type.csv"]);
    }

    private function guard(ImportBatch $batch, TenantContext $context): void
    {
        abort_unless($batch->organization_id === $context->organization()->id && (! $batch->residence_id || $batch->residence_id === $context->residence?->id), 404);
    }
}
