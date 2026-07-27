<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\SupplierStatementService;
use App\Support\Money;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierStatementController extends Controller
{
    public function show(Request $request, Supplier $supplier, TenantContext $context, SupplierStatementService $service)
    {
        abort_unless($supplier->organization_id === $context->organization()->id, 404);
        $dates = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $statement = $service->build($supplier, $context->residence()->id, $dates['from'] ?? null, $dates['to'] ?? null);
        if ($request->boolean('csv')) {
            return response()->streamDownload(function () use ($statement) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Date', 'Type', 'Numéro', 'Libellé', 'Débit MAD', 'Crédit MAD', 'Solde MAD']);
                foreach ($statement['rows'] as $row) {
                    fputcsv($out, [$this->safe($row['date']), $this->safe($row['type']), $this->safe($row['number']), $this->safe($row['label']), Money::decimal($row['debit_cents']), Money::decimal($row['credit_cents']), Money::decimal($row['balance_cents'])]);
                } fclose($out);
            }, 'releve-fournisseur.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        if ($request->boolean('pdf')) {
            return Pdf::loadView('pdf.supplier-statement', compact('statement'))->download('releve-fournisseur.pdf');
        }
        if ($request->expectsJson()) {
            return response()->json($statement);
        }

        return Inertia::render('Expenses/SupplierStatement', ['statement' => $statement]);
    }

    private function safe(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
