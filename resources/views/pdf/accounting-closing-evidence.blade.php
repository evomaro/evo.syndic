<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px 28px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #14213d; }
        h1 { font-size: 17px; margin: 0 0 6px; }
        .notice { border: 1px solid #d6a900; background: #fff9dd; padding: 8px; margin: 10px 0; }
        .meta { width: 100%; margin-bottom: 10px; }
        .meta td { padding: 2px 6px; vertical-align: top; }
        table.report { border-collapse: collapse; width: 100%; table-layout: fixed; }
        .report th, .report td { border: 1px solid #cbd5e1; padding: 3px; overflow-wrap: anywhere; }
        .report th { background: #edf7f5; }
        thead { display: table-header-group; }
        footer { position: fixed; bottom: -22px; left: 0; right: 0; text-align: center; color: #64748b; }
    </style>
</head>
<body>
    @php
        $stateLabels = [
            'draft' => ['Brouillon', 'مسودة'],
            'blocked' => ['Bloqué', 'محظور'],
            'ready_for_review' => ['Prêt pour revue', 'جاهز للمراجعة'],
            'reviewed' => ['Revu', 'تمت مراجعته'],
            'approved' => ['Approuvé', 'تمت الموافقة'],
            'executing' => ['Exécution en cours', 'قيد التنفيذ'],
            'closed' => ['Clôturé', 'مقفل'],
            'carry_forward_pending' => ['Report en attente', 'الترحيل قيد الانتظار'],
            'carry_forward_completed' => ['Report terminé', 'اكتمل الترحيل'],
            'reopened' => ['Rouvert', 'أعيد فتحه'],
            'superseded' => ['Remplacé', 'تم استبداله'],
        ];
        $stateLabel = $stateLabels[$package->state][app()->getLocale() === 'ar' ? 1 : 0] ?? $package->state;
    @endphp
    <h1>{{ app()->getLocale() === 'ar' ? 'حزمة أدلة الإقفال المحاسبي' : 'Dossier de preuves de clôture comptable' }}</h1>
    <div class="notice">
        {{ app()->getLocale() === 'ar'
            ? 'وثيقة استشارية غير مصادق عليها وغير معتمدة من محاسب.'
            : 'Document de consultation non certifié et non approuvé par un comptable.' }}
    </div>
    <table class="meta">
        <tr>
            <td><b>Package</b> #{{ $package->id }} / {{ $package->generation }}</td>
            <td><b>{{ app()->getLocale() === 'ar' ? 'الحالة' : 'État' }}</b> {{ $stateLabel }}</td>
            <td><b>Snapshot</b> #{{ $package->snapshot_entry_id }}</td>
        </tr>
        <tr>
            <td><b>Organisation</b> #{{ $package->organization_id }}</td>
            <td><b>Résidence</b> #{{ $package->residence_id }}</td>
            <td><b>Livre</b> #{{ $book->id }}</td>
        </tr>
        <tr>
            <td><b>Exercice</b> {{ $exercise->reference }}</td>
            <td><b>Période</b> {{ $exercise->starts_on->format('Y-m-d') }} — {{ $exercise->ends_on->format('Y-m-d') }}</td>
            <td><b>Devise</b> {{ $package->currency }}</td>
        </tr>
    </table>
    <table class="report">
        <thead><tr>@foreach($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($values as $row)
            <tr>@foreach($row as $value)<td>{{ is_bool($value) ? ($value ? 'yes' : 'no') : $value }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ max(count($headings), 1) }}">Aucune ligne.</td></tr>
        @endforelse
        </tbody>
    </table>
    <footer>{{ now()->toIso8601String() }} · {{ $package->integrity_fingerprint }}</footer>
</body>
</html>
