<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 10mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 9px; }
        h1 { font-size: 17px; margin: 0 0 5px; }
        .meta { color: #475569; margin-bottom: 12px; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        thead { display: table-header-group; }
        th, td { border: 1px solid #cbd5e1; padding: 4px; overflow-wrap: anywhere; }
        th { background: #e2e8f0; text-align: start; }
        .footer { position: fixed; bottom: -10mm; width: 100%; color: #64748b; }
        .page:after { content: counter(page); }
    </style>
</head>
<body>
    <h1>{{ $report['type'] }}</h1>
    <div class="meta">
        {{ $organization->name }} — {{ $residence->name }} |
        {{ $book->framework?->name_fr }} |
        {{ $exercise->reference ?? ($exercise->starts_on.' / '.$exercise->ends_on) }} |
        MAD | {{ $report['generated_at'] }} | snapshot #{{ $report['snapshot_entry_id'] }}
    </div>
    <table>
        <thead><tr>@foreach($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ max(1, count($headings)) }}">{{ $locale === 'ar' ? 'لا توجد بيانات' : 'Aucune donnée' }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="footer">{{ $locale === 'ar' ? 'الصفحة' : 'Page' }} <span class="page"></span> — {{ $locale === 'ar' ? 'تقرير استشاري غير مصادق عليه' : 'Rapport de consultation non certifié' }}</div>
</body>
</html>
