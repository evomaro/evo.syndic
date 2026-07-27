<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 9px; }
        h1 { font-size: 17px; margin: 0 0 8px; }
        .notice { border: 1px solid #d9a91a; background: #fff8dc; padding: 8px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #cbd5e1; padding: 4px; vertical-align: top; overflow-wrap: anywhere; }
        th { background: #edf2f7; }
        .meta { color: #526074; margin-bottom: 4px; }
    </style>
</head>
<body>
    <h1>{{ $locale === 'ar' ? 'سجل الحوكمة التقني' : 'Registre technique de gouvernance' }}</h1>
    <div class="meta">{{ $metadata['organization'] }} · {{ $metadata['residence'] }} · {{ $metadata['generated_at'] }}</div>
    <div class="notice">{{ $locale === 'ar' ? $metadata['legal_notice_ar'] : $metadata['legal_notice_fr'] }}</div>
    <table>
        <thead><tr>@foreach ($headings as $heading)<th>{{ str_replace('_', ' ', $heading) }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>@foreach ($headings as $heading)<td>{{ is_scalar($row[$heading] ?? null) ? $row[$heading] : json_encode($row[$heading], JSON_UNESCAPED_UNICODE) }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ max(1, count($headings)) }}">{{ $locale === 'ar' ? 'لا توجد بيانات' : 'Aucune donnée' }}</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
