<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#0f172a;font-size:11px;direction:{{ $locale === 'ar' ? 'rtl' : 'ltr' }}}header{background:#0f172a;color:white;padding:22px}h1{margin:4px 0;font-size:24px}.meta{margin:18px 0;padding:14px;background:#f8fafc}table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #e2e8f0;text-align:{{ $locale === 'ar' ? 'right' : 'left' }}}th{color:#475569}.total{font-size:20px;text-align:right;margin-top:18px;color:#0f766e}</style></head>
<body>
<header><small>EVOSYNDIC</small><h1>{{ $locale === 'ar' ? 'طلب مساهمات' : 'Appel de fonds' }}</h1><b>{{ $fundCall->number }}</b></header>
<div class="meta"><b>{{ $fundCall->title }}</b><br>{{ $locale === 'ar' ? 'تاريخ الإصدار' : 'Émis le' }} {{ $fundCall->issue_date->format('d/m/Y') }} · {{ $locale === 'ar' ? 'الاستحقاق' : 'Échéance' }} {{ $fundCall->due_date->format('d/m/Y') }}</div>
<table><thead><tr><th>{{ $locale === 'ar' ? 'الوحدة' : 'Lot' }}</th><th>{{ $locale === 'ar' ? 'المالك' : 'Copropriétaire' }}</th><th>{{ $locale === 'ar' ? 'الفئة' : 'Catégorie' }}</th><th>{{ $locale === 'ar' ? 'المبلغ' : 'Montant MAD' }}</th></tr></thead><tbody>
@foreach($charges as $charge)
<tr><td>{{ $charge->lot_reference_snapshot }}</td><td>{{ $charge->contact_name_snapshot ?: '-' }}</td><td>{{ data_get($charge->validation_snapshot, 'category.name', $charge->line->category->name) }}</td><td>{{ \App\Support\Money::decimal($charge->amount_cents) }}</td></tr>
@endforeach
</tbody></table>
<div class="total">{{ $locale === 'ar' ? 'المجموع' : 'Total' }}: {{ \App\Support\Money::decimal($charges->sum('amount_cents')) }} MAD</div>
</body></html>
