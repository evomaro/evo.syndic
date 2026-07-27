<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
@page{margin:32px}body{font-family:DejaVu Sans,sans-serif;color:#172033;font-size:9px}header{background:#102c3b;color:white;padding:20px}h1{font-size:21px;margin:5px 0}.ar{direction:rtl;text-align:right}.section{font-size:13px;color:#0f766e;border-bottom:2px solid #5eead4;margin-top:16px;padding-bottom:3px}.grid{width:100%;border-collapse:collapse}.grid th,.grid td{border:1px solid #d8e1e8;padding:6px;vertical-align:top}.grid th{background:#f1f5f9}.yes{color:#047857;font-weight:bold}.no{color:#b91c1c;font-weight:bold}.signatures{margin-top:28px;width:100%}.signatures td{height:70px;border:1px solid #cbd5e1;padding:8px;width:50%}.footer{margin-top:18px;color:#64748b;font-size:8px}.ltr{direction:ltr;unicode-bidi:embed}
</style></head><body><header><div>EVOSYNDIC · PROCÈS-VERBAL / محضر الاجتماع</div><h1>{{ $payload['residence']['name'] }}</h1><div>{{ $payload['assembly']['reference'] }} · <span class="ltr">{{ $payload['assembly']['meeting_date'] }}</span></div></header>
<div class="section">Séance / الجلسة</div><p>Présidence: {{ $payload['assembly']['chairperson'] }} · Secrétariat: {{ $payload['assembly']['secretary'] }}<br>Ouverture: {{ $payload['assembly']['opened_at'] }} · Clôture: {{ $payload['assembly']['closed_at'] }}</p>
<div class="section">Présence et quorum / الحضور والنصاب</div><p>{{ $payload['quorum']['present_or_represented_headcount'] }} / {{ $payload['quorum']['eligible_headcount'] }} — {{ $payload['quorum']['quorum_met'] ? 'Quorum atteint / اكتمل النصاب' : 'Quorum insuffisant / النصاب غير مكتمل' }}</p>
<table class="grid"><thead><tr><th>Copropriétaire / المالك</th><th>État / الحالة</th><th>Poids / الوزن</th></tr></thead><tbody>
@foreach($payload['attendance'] as $row)
<tr><td>{{ $row['name'] }}</td><td>{{ $row['status'] }}</td><td class="ltr">{{ $row['weight'] }}</td></tr>
@endforeach
</tbody></table>
<div class="section">Résolutions / المقررات</div>
@foreach($payload['resolutions'] as $resolution)
<h3>{{ $resolution['code'] }}</h3><p>{{ $resolution['text_fr'] }}</p>
@if($resolution['text_ar'])
<p class="ar">{{ $resolution['text_ar'] }}</p>
@endif
<table class="grid"><tr><td>Pour {{ $resolution['for'] }}</td><td>Contre {{ $resolution['against'] }}</td><td>Abstention {{ $resolution['abstention'] }}</td><td class="{{ $resolution['adopted'] ? 'yes' : 'no' }}">{{ $resolution['adopted'] ? 'ADOPTÉE / مقبول' : 'REJETÉE / مرفوض' }}</td></tr></table><p>Règle: {{ $resolution['rule_identifier'] }} v{{ $resolution['rule_version'] }} · {{ $resolution['comparison'] }} {{ $resolution['threshold_numerator'] }}/{{ $resolution['threshold_denominator'] }}</p>
@endforeach
@if($payload['reservations_fr']||$payload['reservations_ar'])
<div class="section">Réserves / التحفظات</div><p>{{ $payload['reservations_fr'] }}</p><p class="ar">{{ $payload['reservations_ar'] }}</p>
@endif
<table class="signatures"><tr><td>Président / الرئيس<br>{{ $payload['assembly']['chairperson'] }}</td><td>Secrétaire / الكاتب<br>{{ $payload['assembly']['secretary'] }}</td></tr></table><div class="footer">Ce document est produit depuis un payload figé. Sa version signée ne peut être remplacée. / تم إنتاج هذه الوثيقة انطلاقا من بيانات مجمدة ولا يمكن استبدال النسخة الموقعة.</div></body></html>
