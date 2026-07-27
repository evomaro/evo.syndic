<?php

namespace App\Http\Controllers;

use App\Exports\GovernanceRegisterExport;
use App\Services\GovernanceExportService;
use App\Support\ArabicPdf;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class GovernanceExportController extends Controller
{
    public function export(Request $request, string $type, string $format, TenantContext $context, GovernanceExportService $service)
    {
        abort_unless(in_array($type, GovernanceExportService::TYPES, true), 404);
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf', 'json'], true), 404);
        $filters = $request->validate([
            'assembly' => 'nullable|integer',
            'status' => 'nullable|string|max:48',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'locale' => ['nullable', Rule::in(['fr', 'ar'])],
        ]);
        $rows = $service->rows($context, $type, $filters);
        $metadata = $service->metadata($context, $type, $filters, count($rows));
        $name = 'governance-'.$type.'-'.now('UTC')->format('Ymd-His');
        activity()->causedBy($request->user())->withProperties([
            'organization_id' => $context->organization()->id,
            'residence_id' => $context->residence()->id,
            'type' => $type, 'format' => $format, 'filters' => $filters, 'row_count' => count($rows),
        ])->log('governance.register_exported');

        if ($format === 'json') {
            return response()->json(['metadata' => $metadata, 'rows' => $rows], 200, [
                'Content-Disposition' => 'attachment; filename="'.$name.'.json"',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($format === 'pdf') {
            $locale = $filters['locale'] ?? app()->getLocale();
            $html = view('pdf.governance-register', [
                'metadata' => $metadata, 'rows' => $rows,
                'headings' => $rows ? array_keys($rows[0]) : [],
                'locale' => $locale,
            ])->render();

            return Pdf::loadHTML(ArabicPdf::shapeHtml($html, $locale))
                ->setPaper('a4', 'landscape')
                ->download($name.'.pdf');
        }

        return Excel::download(
            new GovernanceRegisterExport($rows, $rows ? array_keys($rows[0]) : []),
            $name.'.'.$format,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX,
            $format === 'csv' ? ['Content-Type' => 'text/csv; charset=UTF-8'] : [],
        );
    }
}
