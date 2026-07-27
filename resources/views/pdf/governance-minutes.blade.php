<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
@page{margin:50px 32px 46px}body{font-family:DejaVu Sans,sans-serif;color:#172033;font-size:9px;direction:rtl}header{background:#102c3b;color:white;padding:20px;text-align:right}h1{font-size:21px;margin:5px 0}.ar{direction:rtl;text-align:right}.fr{direction:ltr;text-align:left}.section{font-size:13px;color:#0f766e;border-bottom:2px solid #5eead4;margin-top:16px;padding-bottom:3px;page-break-after:avoid}.grid{width:100%;border-collapse:collapse;direction:rtl;page-break-inside:auto}.grid tr{page-break-inside:avoid}.grid th,.grid td{border:1px solid #d8e1e8;padding:6px;vertical-align:top;overflow-wrap:anywhere}.grid th{background:#f1f5f9}.yes{color:#047857;font-weight:bold}.no{color:#b91c1c;font-weight:bold}.signatures{margin-top:28px;width:100%;page-break-inside:avoid}.signatures td{height:70px;border:1px solid #cbd5e1;padding:8px;width:50%}.continuation-identity{color:#526074;font-size:8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;margin-bottom:10px}.footer{position:fixed;bottom:-30px;left:0;right:0;color:#64748b;font-size:8px;text-align:center}.footer .page:after{content:counter(page)}.ltr{direction:ltr;unicode-bidi:embed}.notice{border:1px solid #d9a91a;background:#fff8dc;padding:8px;margin:10px 0;page-break-inside:avoid}.classification{font-weight:bold}.block{page-break-inside:avoid}
</style></head><body>
@php
    $attendanceLabels = ['present' => 'Présent / حاضر', 'represented' => 'Représenté / ممثل', 'remote' => 'À distance / عن بعد', 'absent' => 'Absent / غائب', 'disputed' => 'Contesté / متنازع عليه', 'pending' => 'En attente / قيد الانتظار'];
    $proxyLabels = ['verified' => 'Vérifié / تم التحقق', 'revoked' => 'Révoqué / ملغى', 'rejected' => 'Rejeté / مرفوض', 'submitted' => 'Soumis / مقدم'];
    $verificationLabels = ['reviewed_configuration' => 'Configuration revue / إعداد مراجع', 'professional_review_required' => 'Revue professionnelle requise / مراجعة مهنية مطلوبة', 'unverified' => 'Non vérifié / غير متحقق'];
@endphp
<header><div>EVOSYNDIC · PROCÈS-VERBAL / محضر الاجتماع</div><h1>{{ $payload['residence']['name'] }}</h1><div>{{ $payload['assembly']['reference'] }} · <span class="ltr">{{ $payload['assembly']['meeting_date'] }}</span></div></header>
<div class="notice"><span class="classification">{{ $documentStatus === 'finalized' ? 'Version finalisée / نسخة نهائية' : 'Projet / مسودة' }}</span><br>وثيقة تقنية غير معتمدة ولا تشكل استشارة قانونية. Document technique non certifié — ne constitue pas un avis juridique.</div>
<div class="section">Séance / الجلسة</div><p>Présidence: {{ $payload['assembly']['chairperson'] }} · Secrétariat: {{ $payload['assembly']['secretary'] }}<br>Ouverture: {{ $payload['assembly']['opened_at'] }} · Clôture: {{ $payload['assembly']['closed_at'] }}</p>
@if(!empty($payload['agenda']))
<div class="section">Ordre du jour / جدول الأعمال</div>
<table class="grid"><thead><tr><th>#</th><th>Français</th><th>العربية</th></tr></thead><tbody>
@foreach($payload['agenda'] as $item)
<tr><td class="ltr">{{ $item['order'] }}</td><td class="fr">{{ $item['title_fr'] }}<br>{{ $item['explanation_fr'] }}</td><td class="ar">{{ $item['title_ar'] }}<br>{{ $item['explanation_ar'] }}</td></tr>
@endforeach
</tbody></table>
@endif
<div class="section">Présence et quorum / الحضور والنصاب</div><p>{{ $payload['quorum']['present_or_represented_headcount'] }} / {{ $payload['quorum']['eligible_headcount'] }} — {{ $payload['quorum']['quorum_met'] ? 'Quorum atteint / اكتمل النصاب' : 'Quorum insuffisant / النصاب غير مكتمل' }}</p>
<table class="grid"><thead><tr><th>Copropriétaire / المالك</th><th>État / الحالة</th><th>Poids / الوزن</th></tr></thead><tbody>
@foreach($payload['attendance'] as $row)
<tr><td>{{ $row['name'] }}</td><td>{{ $attendanceLabels[$row['status']] ?? 'État technique non reconnu / حالة تقنية غير معروفة' }}</td><td class="ltr">{{ $row['weight'] }}</td></tr>
@endforeach
</tbody></table>
@if(!empty($payload['proxies']))
<div class="section">Mandats / الوكالات</div>
<table class="grid"><thead><tr><th>Mandant / الموكل</th><th>État / الحالة</th><th>Vérification / التحقق</th><th>Poids / الوزن</th></tr></thead><tbody>
@foreach($payload['proxies'] as $proxy)
<tr><td>{{ $proxy['principal'] }}</td><td>{{ $proxyLabels[$proxy['status']] ?? 'État technique non reconnu / حالة تقنية غير معروفة' }}</td><td>{{ $verificationLabels[$proxy['verification']] ?? 'Non vérifié / غير متحقق' }}</td><td class="ltr">{{ $proxy['weight'] }}</td></tr>
@endforeach
</tbody></table>
@endif
<div class="section">Résolutions / المقررات</div>
@foreach($payload['resolutions'] as $resolution)
<h3>{{ $resolution['code'] }}</h3><p>{{ $resolution['text_fr'] }}</p>
@if($resolution['text_ar'])
<p class="ar">{{ $resolution['text_ar'] }}</p>
@endif
<table class="grid"><tr><td>Pour {{ $resolution['for'] }}</td><td>Contre {{ $resolution['against'] }}</td><td>Abstention {{ $resolution['abstention'] }}</td><td>Invalides {{ $resolution['invalid'] }}</td><td>Non exprimés {{ $resolution['not_cast'] }}</td><td class="{{ $resolution['adopted'] ? 'yes' : 'no' }}">{{ $resolution['adopted'] ? 'ADOPTÉE / مقبول' : 'REJETÉE / مرفوض' }}</td></tr></table><p>Règle: {{ $resolution['rule_identifier'] }} v{{ $resolution['rule_version'] }} · {{ $resolution['comparison'] }} {{ $resolution['threshold_numerator'] }}/{{ $resolution['threshold_denominator'] }}</p>
@endforeach
@if($payload['reservations_fr']||$payload['reservations_ar'])
<div class="section">Réserves / التحفظات</div><p>{{ $payload['reservations_fr'] }}</p><p class="ar">{{ $payload['reservations_ar'] }}</p>
@endif
@if($payload['incidents_fr']||$payload['incidents_ar'])
<div class="block">
<div class="continuation-identity">EVOSYNDIC · {{ $payload['assembly']['reference'] }} · {{ $payload['residence']['name'] }}</div>
<div class="section">Discussions et incidents / المناقشات والوقائع</div><p class="fr">{{ $payload['incidents_fr'] }}</p><p class="ar">{{ $payload['incidents_ar'] }}</p>
</div>
@endif
<table class="signatures"><tr><td>Président / الرئيس<br>{{ $payload['assembly']['chairperson'] }}</td><td>Secrétaire / الكاتب<br>{{ $payload['assembly']['secretary'] }}</td></tr></table><div class="footer">Ce document est produit depuis un payload figé. Sa version signée ne peut être remplacée. / تم إنتاج هذه الوثيقة انطلاقا من بيانات مجمدة ولا يمكن استبدال النسخة الموقعة. · Page <span class="page"></span></div></body></html>
