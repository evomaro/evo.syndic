<?php

namespace App\Http\Controllers;

use App\Models\Assembly;
use App\Models\AssemblyMinuteVersion;
use App\Models\Convocation;
use App\Models\User;
use App\Services\AgendaQuestionService;
use App\Services\AttendanceProxyService;
use App\Services\GovernancePortalAccessService;
use App\Services\MinutesService;
use App\Support\ArabicPdf;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OwnerGovernanceController extends Controller
{
    public function index(Request $r, TenantContext $c)
    {
        $user = $r->user();
        $today = today();
        $assemblies = Assembly::where('organization_id', $c->organization()->id)->where('residence_id', $c->residence()->id)->where(function ($q) use ($user, $today) {
            $q->whereHas('electorate.contact', fn ($contacts) => $contacts->whereHas('users', fn ($users) => $users->where('users.id', $user->id)->whereNull('contact_user.revoked_at'))->whereHas('ownerships', fn ($ownerships) => $ownerships->whereDate('starts_on', '<=', $today)->where(fn ($dates) => $dates->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))->whereHas('lot', fn ($lots) => $lots->whereColumn('lots.residence_id', 'assemblies.residence_id')->where('active', true))))->orWhereHas('proxies', fn ($proxies) => $proxies->where('representative_user_id', $user->id)->where('status', 'verified'));
        })->whereNotIn('status', ['draft', 'preparing'])->orderByDesc('meeting_date')->paginate(20)->through(fn ($a) => $a->only(['id', 'reference', 'type', 'meeting_date', 'starts_at', 'location', 'status', 'convocation_number']));

        return Inertia::render('Governance/OwnerIndex', ['assemblies' => $assemblies]);
    }

    public function show(Request $r, Assembly $a, TenantContext $c, GovernancePortalAccessService $access)
    {
        $this->owner($a, $r, $c);
        $a->load(['agendaItems' => fn ($q) => $q->where('resident_visible', true)->whereNot('status', 'removed')->select(['id', 'assembly_id', 'display_order', 'title_fr', 'title_ar', 'explanation_fr', 'explanation_ar', 'status']), 'documents' => fn ($q) => $q->where('audience', 'owners')->where('status', 'published')->with('publishedVersion'), 'convocations', 'minutes.signedVersion', 'resolutions' => fn ($q) => $q->whereIn('status', ['adopted', 'rejected'])->with('finalResult'), 'resolutions.executionActions' => fn ($q) => $q->select(['id', 'resolution_id', 'action_type', 'status', 'due_on', 'description', 'completion_result'])]);
        $e = $access->electorate($a, $r->user());
        abort_unless($e, 404);
        $proxyOnly = $access->isProxyOnly($a, $r->user());

        return Inertia::render('Governance/OwnerShow', ['assembly' => $a->makeHidden(['cancellation_reason', 'postponement_reason', 'adjournment_reason', 'governance_mandate_id']), 'electorate' => $e->only(['id', 'contact_name_snapshot', 'lot_ids', 'ownership_fractions', 'voting_weight_numerator', 'voting_weight_denominator', 'eligibility_status', 'restriction_reason', 'snapshotted_at']), 'proxyOnly' => $proxyOnly, 'delivery' => $proxyOnly ? null : $a->convocations->last()?->recipients()->where('electorate_id', $e->id)->first()?->only(['delivery_method', 'status', 'notified_at', 'attempt_count'])]);
    }

    public function question(Request $r, Assembly $a, TenantContext $c, AgendaQuestionService $s, GovernancePortalAccessService $access)
    {
        $this->owner($a, $r, $c);
        $d = $r->validate(['question_fr' => 'required|string|max:5000', 'question_ar' => 'nullable|string|max:5000']);
        $e = $access->ownerElectorate($a, $r->user());
        abort_unless($e, 403);
        $s->submit($a, $e, $r->user(), $d['question_fr'], $d['question_ar'] ?? null);

        return back();
    }

    public function proxy(Request $r, Assembly $a, TenantContext $c, AttendanceProxyService $s, GovernancePortalAccessService $access)
    {
        $this->owner($a, $r, $c);
        $d = $r->validate(['representative_email' => 'required|email', 'file' => 'required|file|max:10240|extensions:pdf,jpg,jpeg,png|mimes:pdf,jpg,jpeg,png']);
        $representative = User::where('email', $d['representative_email'])->whereHas('organizations', fn ($q) => $q->where('organizations.id', $a->organization_id))->first();
        if (! $representative) {
            throw ValidationException::withMessages(['representative_email' => __('Le mandataire doit disposer d’un compte autorisé dans cette organisation.')]);
        }$e = $access->ownerElectorate($a, $r->user());
        abort_unless($e, 403);
        $s->submitProxy($a, $e, $representative, null, $r->file('file'), $r->user());

        return back();
    }

    public function proxyForm(Request $r, Assembly $a, TenantContext $c, GovernancePortalAccessService $access)
    {
        $this->owner($a, $r, $c);
        $e = $access->ownerElectorate($a, $r->user());
        abort_unless($e, 403);
        $html = '<html><meta charset="utf-8"><style>body{font-family:DejaVu Sans;padding:36px;line-height:1.7}.box{border:1px solid #aaa;padding:16px;margin:20px 0}</style><body><h1>Mandat de vote / توكيل للتصويت</h1><p>Assemblée '.e($a->reference).' — '.e($a->meeting_date->toDateString()).'</p><div class="box">Je soussigné(e) '.e($e->contact_name_snapshot).', mandate __________________ pour me représenter et voter en mon nom.<br><br>أنا الموقع(ة) أسفله '.e($e->contact_name_snapshot).' أوكل __________________ لتمثيلي والتصويت باسمي.</div><p>Date / التاريخ: ____________ &nbsp;&nbsp; Signature / التوقيع: __________________</p><small>Le mandat écrit reste soumis aux limites légales configurées (maximum trois copropriétaires et 10 % des voix).</small></body></html>';

        return Pdf::loadHTML(ArabicPdf::shapeHtml($html, 'ar'))->download('mandat-'.$a->reference.'.pdf', ['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function convocation(Request $r, Convocation $convocation, TenantContext $c)
    {
        $this->owner($convocation->assembly, $r, $c);
        $disk = Storage::disk($convocation->disk);
        abort_unless($disk->exists($convocation->path) && hash_equals($convocation->checksum, hash('sha256', $disk->get($convocation->path))), 409);

        return $disk->download($convocation->path, 'convocation.pdf', ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function minutes(Request $r, AssemblyMinuteVersion $version, TenantContext $c, MinutesService $s)
    {
        $this->owner($version->minutes->assembly, $r, $c);
        abort_unless($version->status === 'signed', 404);

        return $s->download($version);
    }

    private function owner(Assembly $a, Request $r, TenantContext $c): void
    {
        abort_unless($a->organization_id === $c->organization()->id && $a->residence_id === $c->residence()->id, 404);
        $this->authorize('ownerPortal', $a);
    }
}
