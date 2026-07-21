<?php

namespace App\Http\Controllers;

use App\Models\FinancialDocument;
use App\Models\Payment;
use App\Services\ReceiptService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ReceiptController extends Controller
{
    public function download(FinancialDocument $document, TenantContext $context)
    {
        abort_unless($document->type === 'receipt' && $document->organization_id === $context->organization()->id && $document->residence_id === $context->residence()->id, 404);
        abort_unless($document->subject instanceof Payment, 404);
        $user = request()->user();
        $staff = $user->canInOrganization('view_finance', $context->organization());
        $ownerContactIds = $user->contacts()->where('contacts.organization_id', $document->organization_id)->pluck('contacts.id');
        abort_unless($staff || $ownerContactIds->contains($document->subject->payer_contact_id), 403);

        return Storage::disk($document->disk)->download($document->path, $document->number.'.pdf');
    }

    public function verify(string $token)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);
        $document = FinancialDocument::query()->where('verification_token_hash', hash('sha256', $token))->with(['residence.organization', 'subject'])->first();
        if (! $document) {
            return Inertia::render('Finance/ReceiptVerification', ['receipt' => null])->toResponse(request())->setStatusCode(404);
        }
        $integrity = Storage::disk($document->disk)->exists($document->path) && hash_equals($document->checksum, hash('sha256', Storage::disk($document->disk)->get($document->path)));

        return Inertia::render('Finance/ReceiptVerification', ['receipt' => ['type' => 'receipt', 'number' => $document->number, 'issuer' => $document->residence->name, 'issued_at' => $document->generated_at->toDateString(), 'amount_cents' => $document->subject?->amount_cents, 'status' => $document->status, 'integrity' => $integrity]]);
    }

    public function retry(Payment $payment, TenantContext $context, ReceiptService $receipts)
    {
        abort_unless($payment->organization_id === $context->organization()->id && $payment->residence_id === $context->residence()->id, 404);
        abort_unless($payment->status === 'validated', 409);
        $receipts->generate($payment, request()->user());

        return back()->with('success', __('Reçu vérifié ou régénéré.'));
    }
}
