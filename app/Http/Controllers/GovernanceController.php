<?php

namespace App\Http\Controllers;

use App\Http\Requests\Governance\AgendaItemRequest;
use App\Http\Requests\Governance\AssemblyRequest;
use App\Models\Assembly;
use App\Models\GovernanceMandate;
use App\Queries\GovernanceReportingQuery;
use App\Services\AgendaService;
use App\Services\GovernanceRuleService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class GovernanceController extends Controller
{
    public function dashboard(TenantContext $c, GovernanceReportingQuery $report)
    {
        return Inertia::render('Governance/Dashboard', ['metrics' => $report->summary($c), 'upcoming' => Assembly::where('organization_id', $c->organization()->id)->where('residence_id', $c->residence()->id)->whereNotIn('status', ['closed', 'cancelled'])->orderBy('meeting_date')->limit(8)->get(['id', 'reference', 'type', 'meeting_date', 'starts_at', 'status', 'convocation_number'])]);
    }

    public function index(Request $r, TenantContext $c)
    {
        $query = Assembly::where('organization_id', $c->organization()->id)->where('residence_id', $c->residence()->id)->when($r->string('search')->toString(), fn ($q, $s) => $q->where(fn ($q) => $q->where('reference', 'like', "%$s%")->orWhere('location', 'like', "%$s%")))->when($r->string('status')->toString(), fn ($q, $s) => $q->where('status', $s));
        $sort = in_array($r->string('sort')->toString(), ['meeting_date', 'reference', 'status'], true) ? $r->string('sort')->toString() : 'meeting_date';
        $direction = $r->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        return Inertia::render('Governance/Index', ['assemblies' => $query->orderBy($sort, $direction)->paginate(20)->withQueryString(), 'filters' => $r->only(['search', 'status', 'sort', 'direction'])]);
    }

    public function create(TenantContext $c, GovernanceRuleService $rules)
    {
        $rules->ensureVersions();

        return Inertia::render('Governance/Form', ['exercises' => $c->residence()->financialExercises()->orderByDesc('starts_on')->get(['id', 'name', 'status']), 'mandates' => GovernanceMandate::where('residence_id', $c->residence()->id)->where('status', 'active')->get(['id', 'role', 'starts_on', 'ends_on'])]);
    }

    public function store(AssemblyRequest $r, TenantContext $c)
    {
        $date = CarbonImmutable::parse($r->validated('meeting_date'), $c->residence()->timezone);
        $assembly = Assembly::create($r->safe()->except(['expected_ends_at']) + ['organization_id' => $c->organization()->id, 'residence_id' => $c->residence()->id, 'reference' => 'AG-'.$date->format('Y').'-'.strtoupper(Str::random(6)), 'status' => 'draft', 'convocation_number' => 1, 'expected_ends_at' => $r->validated('expected_ends_at'), 'timezone' => $c->residence()->timezone, 'convocation_deadline_at' => $date->subDays(config('governance.notice_days')), 'documents_available_at' => $date->subDays(config('governance.document_access_days')), 'created_by' => $r->user()->id]);
        activity()->performedOn($assembly)->causedBy($r->user())->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id])->log('governance.assembly_created');

        return to_route('governance.show', $assembly);
    }

    public function show(Assembly $assembly, TenantContext $c)
    {
        $this->scope($assembly, $c);
        $this->authorize('view', $assembly);
        $assembly->load(['transitions', 'agendaItems.resolution.ruleVersion', 'electorate', 'convocations.recipients', 'documents.versions', 'documents.publishedVersion', 'attendanceRecords.electorate', 'proxies.principal', 'quorumSnapshots', 'resolutions.ballots', 'resolutions.finalResult', 'minutes.versions', 'resolutions.executionActions', 'agendaQuestions.electorate', 'decisionNotifications']);

        return Inertia::render('Governance/Show', ['assembly' => $assembly, 'rules' => app(GovernanceRuleService::class)->ensureVersions(), 'users' => $c->organization()->users()->get(['users.id', 'users.name']), 'contacts' => $c->organization()->contacts()->whereHas('ownerships.lot', fn ($q) => $q->where('residence_id', $c->residence()->id))->get(['id', 'first_name', 'last_name', 'company_name', 'type'])]);
    }

    public function addAgenda(AgendaItemRequest $r, Assembly $assembly, TenantContext $c, AgendaService $service)
    {
        $this->scope($assembly, $c);
        $this->authorize('update', $assembly);
        $data = $r->validated();
        $rule = null;
        if ($id = $r->validated('rule_identifier')) {
            $rule = app(GovernanceRuleService::class)->ensureVersions()[$id];
            $data['resolution'] = ['code' => $r->validated('resolution_code'), 'category' => $r->validated('resolution_category'), 'proposed_text_fr' => $r->validated('proposed_text_fr') ?: $r->validated('title_fr'), 'proposed_text_ar' => $r->validated('proposed_text_ar')];
        }$service->add($assembly, $data, $rule ?? app(GovernanceRuleService::class)->ensureVersions()['article_20_relative_majority'], $r->user());

        return back();
    }

    private function scope(Assembly $a, TenantContext $c): void
    {
        abort_unless($a->organization_id === $c->organization()->id && $a->residence_id === $c->residence()->id, 404);
    }
}
