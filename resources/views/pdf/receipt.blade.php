<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 32px; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; direction: {{ $locale === 'ar' ? 'rtl' : 'ltr' }}; }
        .top { background: #0f172a; color: white; padding: 24px; border-radius: 12px; }
        .brand { color: #5eead4; font-size: 12px; font-weight: bold; letter-spacing: 2px; }
        h1 { margin: 8px 0 0; font-size: 27px; }
        .reference { font-size: 14px; margin-top: 8px; }
        .grid { width: 100%; margin-top: 24px; border-collapse: collapse; }
        .grid td { width: 50%; padding: 11px; border: 1px solid #e2e8f0; vertical-align: top; }
        .label { color: #64748b; font-size: 9px; text-transform: uppercase; margin-bottom: 4px; }
        .value { font-size: 13px; font-weight: bold; }
        .amount { margin: 24px 0; padding: 20px; background: #f0fdfa; border: 1px solid #99f6e4; text-align: center; }
        .amount strong { display: block; color: #0f766e; font-size: 28px; }
        table.lines { width: 100%; border-collapse: collapse; }
        .lines th, .lines td { padding: 9px; border-bottom: 1px solid #e2e8f0; text-align: {{ $locale === 'ar' ? 'right' : 'left' }}; }
        .lines th { background: #f8fafc; color: #475569; }
        .qr { width: 112px; height: 112px; }
        .footer { margin-top: 28px; width: 100%; color: #64748b; font-size: 9px; }
        .ltr { direction: ltr; unicode-bidi: embed; }
    </style>
</head>
<body>
    <div class="top">
        <div class="brand">EVOSYNDIC</div>
        <h1>{{ $locale === 'ar' ? 'وصل الدفع' : 'Reçu de paiement' }}</h1>
        <div class="reference ltr">{{ $number }}</div>
    </div>
    <table class="grid">
        <tr><td><div class="label">{{ $locale === 'ar' ? 'الإقامة' : 'Résidence' }}</div><div class="value ltr">{{ $payment->residence->name }}</div></td><td><div class="label">{{ $locale === 'ar' ? 'تاريخ الدفع' : 'Date du paiement' }}</div><div class="value ltr">{{ $payment->payment_date->format('d/m/Y') }}</div></td></tr>
        <tr><td><div class="label">{{ $locale === 'ar' ? 'الدافع' : 'Payeur / reçu de' }}</div><div class="value ltr">{{ $payment->payer?->display_name ?? $payment->received_from }}</div></td><td><div class="label">{{ $locale === 'ar' ? 'طريقة الدفع' : 'Mode de paiement' }}</div><div class="value ltr">{{ $payment->method }}</div></td></tr>
    </table>
    <div class="amount"><span>{{ $locale === 'ar' ? 'المبلغ المستلم' : 'Montant reçu' }}</span><strong class="ltr">{{ \App\Support\Money::formatted($payment->amount_cents) }} MAD</strong></div>
    <table class="lines"><thead><tr><th>{{ $locale === 'ar' ? 'المرجع' : 'Référence' }}</th><th>{{ $locale === 'ar' ? 'الوحدة' : 'Lot' }}</th><th>{{ $locale === 'ar' ? 'المبلغ' : 'Montant' }}</th></tr></thead><tbody>
    @forelse($payment->allocations as $allocation)<tr><td class="ltr">{{ $allocation->charge->fundCall->number }}</td><td class="ltr">{{ $allocation->lot->reference }}</td><td class="ltr">{{ \App\Support\Money::formatted($allocation->amount_cents) }} MAD</td></tr>@empty<tr><td colspan="3">{{ $locale === 'ar' ? 'دفعة غير مخصصة' : 'Paiement non affecté' }}</td></tr>@endforelse
    </tbody></table>
    @if($payment->credit_cents > 0)<p><strong>{{ $locale === 'ar' ? 'الرصيد المتاح' : 'Crédit disponible' }}:</strong> {{ \App\Support\Money::formatted($payment->credit_cents) }} MAD</p>@endif
    <table class="footer"><tr><td><img src="{{ $qr }}" class="qr" alt="QR"></td><td>{{ $locale === 'ar' ? 'امسح الرمز للتحقق من صحة هذا الإيصال. لا يحتوي رابط التحقق على بيانات شخصية.' : 'Scannez ce code pour vérifier l’authenticité du reçu. La page publique ne contient aucune donnée personnelle.' }}<br><br>SHA-256: {{ substr(hash('sha256', $number), 0, 20) }}...</td></tr></table>
</body></html>
