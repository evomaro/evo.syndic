<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
@page{margin:34px}body{font-family:DejaVu Sans,sans-serif;color:#172033;font-size:10px}header{background:#102c3b;color:#fff;padding:22px}.brand{color:#5eead4;letter-spacing:2px;font-weight:bold}h1{margin:6px 0;font-size:22px}.meta{width:100%;border-collapse:collapse;margin:18px 0}.meta td{border:1px solid #d8e1e8;padding:8px;width:50%;vertical-align:top}.label{font-size:8px;color:#64748b;text-transform:uppercase}.ar{direction:rtl;text-align:right}.section{margin-top:18px;border-bottom:2px solid #0f766e;padding-bottom:4px;font-size:14px}.agenda{width:100%;border-collapse:collapse}.agenda td{border-bottom:1px solid #e2e8f0;padding:8px;vertical-align:top}.notice{background:#f0fdfa;border:1px solid #99f6e4;padding:10px;margin-top:16px}.footer{margin-top:18px;color:#64748b;font-size:8px}.ltr{direction:ltr;unicode-bidi:embed}
</style></head><body><header><div class="brand">EVOSYNDIC · PROJET TECHNIQUE DE CONVOCATION</div><h1>Assemblée générale / <span class="ar">الجمع العام</span></h1><div>{{ $payload['residence']['name'] }} · {{ $payload['assembly']['reference'] }}</div></header>
<table class="meta"><tr><td><div class="label">Type / النوع</div>{{ $payload['assembly']['type'] }} — {{ $payload['assembly']['convocation_number'] === 2 ? 'Deuxième convocation / الدعوة الثانية' : 'Première convocation / الدعوة الأولى' }}</td><td><div class="label">Autorité / الجهة الداعية</div>{{ $payload['assembly']['convening_authority'] }}</td></tr><tr><td><div class="label">Date et heure / التاريخ والساعة</div><span class="ltr">{{ $payload['assembly']['meeting_date'] }} {{ $payload['assembly']['starts_at'] }}</span></td><td><div class="label">Lieu / المكان</div>{{ $payload['assembly']['location'] }}</td></tr></table>
<div class="section">Ordre du jour / <span class="ar">جدول الأعمال</span></div>
<table class="agenda">
@foreach($payload['agenda'] as $item)
<tr><td style="width:28px">{{ $item['order'] }}</td><td><strong>{{ $item['title_fr'] }}</strong>
@if($item['title_ar'])
<div class="ar">{{ $item['title_ar'] }}</div>
@endif
@if($item['proposed_text_fr'])
<p>{{ $item['proposed_text_fr'] }}</p>
@endif
@if($item['proposed_text_ar'])
<p class="ar">{{ $item['proposed_text_ar'] }}</p>
@endif
</td></tr>
@endforeach
</table>
<div class="notice"><strong>Documents et mandat / الوثائق والتوكيل</strong><br>Les documents protégés sont disponibles dans l’espace copropriétaire. Tout mandat doit être écrit et respecter les limites légales configurées.<br><span class="ar">الوثائق المحمية متاحة في فضاء المالك المشترك. يجب أن يكون كل توكيل كتابيا وأن يحترم الحدود القانونية المهيأة.</span></div>
<div class="footer"><strong>Non certifié — ne constitue pas un avis juridique.</strong> {{ $payload['legal']['notice'] }}<br>
Classification: {{ $payload['legal']['classification'] }} · Statut de revue: {{ $payload['legal']['review_status'] }}<br>
SHA-256 calculé sur le PDF émis et conservé dans le registre EvoSyndic.</div></body></html>
