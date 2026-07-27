<?php

namespace App\Http\Controllers;

use App\Models\FinancialDocument;
use App\Services\FinancialDocumentChecksumService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class FinancialDocumentController extends Controller
{
    public function download(FinancialDocument $document, TenantContext $context, FinancialDocumentChecksumService $checksums)
    {
        abort_unless($document->type === 'supplier_voucher' && $document->organization_id === $context->organization()->id && $document->residence_id === $context->residence()->id, 404);
        abort_unless(request()->user()->canInOrganization('view_supplier_payables', $context->organization()), 403);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);
        $bytes = Storage::disk($document->disk)->get($document->path);
        abort_unless($checksums->matches($document->checksum, $bytes), 409);

        return Storage::disk($document->disk)->download($document->path, $document->number.'.pdf', [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function verify(string $token, FinancialDocumentChecksumService $checksums)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);
        $document = FinancialDocument::query()->where('verification_token_hash', hash('sha256', $token))->where('type', 'supplier_voucher')->with(['residence', 'subject.supplier'])->first();
        if (! $document) {
            return Inertia::render('Finance/ReceiptVerification', ['receipt' => null])->toResponse(request())->setStatusCode(404);
        }
        $integrity = Storage::disk($document->disk)->exists($document->path)
            && $checksums->matches($document->checksum, Storage::disk($document->disk)->get($document->path));

        return Inertia::render('Finance/ReceiptVerification', ['receipt' => ['type' => 'supplier_voucher', 'number' => $document->number, 'issuer' => $document->residence->name, 'issued_at' => $document->generated_at->toDateString(), 'amount_cents' => $document->subject?->amount_cents, 'counterparty' => $document->subject?->supplier?->legal_name, 'status' => $document->status, 'integrity' => $integrity]])
            ->toResponse(request())->header('Cache-Control', 'public, max-age=60')->header('X-Content-Type-Options', 'nosniff');
    }
}
