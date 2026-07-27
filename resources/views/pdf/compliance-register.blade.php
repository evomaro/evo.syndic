<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #172033; }
        h1 { font-size: 18px; } .notice { padding: 8px; background: #fff6d8; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; } th, td { border: 1px solid #ccd3df; padding: 4px; vertical-align: top; }
        th { background: #eef2f7; }
    </style>
</head>
<body>
<h1>{{ __('Registre de conformité') }} — {{ $organization->name }}</h1>
<div class="notice">{{ __('Outil opérationnel configurable — ne constitue pas un conseil juridique ou fiscal.') }} {{ $snapshotAt->toIso8601String() }}</div>
<table>
    <thead><tr>@foreach(array_keys($rows[0] ?? []) as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
    <tbody>@foreach($rows as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@endforeach</tbody>
</table>
</body>
</html>
