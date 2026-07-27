<?php

namespace App\Http\Controllers;

use App\Models\GovernanceRule;
use App\Models\GovernanceRuleSource;
use App\Models\GovernanceRuleVersion;
use App\Services\GovernanceRuleWorkflow;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class GovernanceRuleController extends Controller
{
    public function index(TenantContext $context)
    {
        $organizationId = $context->organization()->id;

        return Inertia::render('Governance/Rules', [
            'sources' => GovernanceRuleSource::where('organization_id', $organizationId)->latest()->get(),
            'rules' => GovernanceRule::where('organization_id', $organizationId)
                ->with(['versions' => fn ($query) => $query->with('source')->orderByDesc('version')])
                ->orderBy('stable_code')->get(),
            'legalNotice' => [
                'classification' => 'technical_configuration_only',
                'fr' => 'Configuration technique — non certifiée et ne constituant pas un avis juridique.',
                'ar' => 'إعداد تقني غير معتمد ولا يشكل استشارة قانونية.',
            ],
        ]);
    }

    public function storeSource(Request $request, TenantContext $context)
    {
        $data = $request->validate([
            'code' => 'required|string|max:100',
            'jurisdiction' => 'required|string|max:100',
            'issuing_authority' => 'nullable|string|max:255',
            'official_title' => 'required|string|max:255',
            'official_url' => 'nullable|url|max:2000',
            'document_reference' => 'nullable|string|max:255',
            'published_on' => 'nullable|date',
            'effective_on' => 'nullable|date',
            'notes_fr' => 'nullable|string|max:10000',
            'notes_ar' => 'nullable|string|max:10000',
        ]);
        $version = (int) GovernanceRuleSource::where('organization_id', $context->organization()->id)
            ->where('code', $data['code'])->max('version') + 1;
        GovernanceRuleSource::create($data + [
            'organization_id' => $context->organization()->id,
            'version' => $version,
            'confidence' => 'unverified_draft',
        ]);

        return back();
    }

    public function verifySource(GovernanceRuleSource $source, Request $request, TenantContext $context, GovernanceRuleWorkflow $workflow)
    {
        $this->scopeSource($source, $context);
        $workflow->verifySource($source, $request->user());

        return back();
    }

    public function storeVersion(Request $request, TenantContext $context)
    {
        $data = $request->validate([
            'stable_code' => 'required|string|max:100',
            'jurisdiction' => 'required|string|max:100',
            'assembly_type' => ['required', Rule::in(['ordinary', 'extraordinary', 'mixed', 'other'])],
            'resolution_category' => 'required|string|max:80',
            'title_fr' => 'required|string|max:255',
            'title_ar' => 'required|string|max:255',
            'governance_rule_source_id' => 'nullable|integer',
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'numerator_definition' => 'required|string|max:80',
            'denominator_definition' => 'required|string|max:80',
            'threshold_numerator' => 'required|integer|min:0',
            'threshold_denominator' => 'required|integer|min:1',
            'comparison' => ['required', Rule::in(['gt', 'gte', 'lt', 'lte', 'eq'])],
            'rounding_policy' => ['required', Rule::in(['none', 'floor', 'ceiling', 'half_up'])],
            'abstention_behavior' => 'required|string|max:40',
            'invalid_ballot_behavior' => 'required|string|max:40',
            'voting_share_source_type' => 'required|string|max:80',
            'proxy_restrictions' => 'nullable|array',
            'eligibility_restrictions' => 'nullable|array',
            'notice_requirements' => 'nullable|array',
            'required_evidence' => 'nullable|array',
            'effective_date_policy' => 'required|string|max:80',
        ]);

        DB::transaction(function () use ($data, $context) {
            $source = null;
            if ($data['governance_rule_source_id'] ?? null) {
                $source = GovernanceRuleSource::whereKey($data['governance_rule_source_id'])
                    ->where('organization_id', $context->organization()->id)->firstOrFail();
            }
            $rule = GovernanceRule::firstOrCreate(
                ['organization_id' => $context->organization()->id, 'stable_code' => $data['stable_code']],
                collect($data)->only(['jurisdiction', 'assembly_type', 'resolution_category', 'title_fr', 'title_ar'])->all(),
            );
            $version = (int) $rule->versions()->max('version') + 1;
            $rule->versions()->create(collect($data)->except(['stable_code', 'governance_rule_source_id', 'jurisdiction'])->all() + [
                'governance_rule_source_id' => $source?->id,
                'identifier' => $rule->stable_code,
                'version' => $version,
                'official_source' => $source?->official_title ?: 'Source officielle non fournie',
                'source_url' => $source?->official_url ?: 'about:blank',
                'review_status' => 'pending_professional_review',
                'status' => 'unverified_draft',
                'confidence' => $source ? 'official_source_located' : 'unverified_draft',
                'quorum_rule' => 'configured_formula',
                'second_convocation_behavior' => 'unverified',
                'proxy_restrictions' => $data['proxy_restrictions'] ?? [],
                'eligibility_restrictions' => $data['eligibility_restrictions'] ?? [],
                'legal_payload' => ['classification' => 'technical_preview', 'certified' => false, 'legal_advice' => false],
                'active' => false,
            ]);
        });

        return back();
    }

    public function review(GovernanceRuleVersion $version, Request $request, TenantContext $context, GovernanceRuleWorkflow $workflow)
    {
        $this->scopeVersion($version, $context);
        $workflow->review($version, $request->user(), (bool) $request->boolean('counsel'));

        return back();
    }

    public function approve(GovernanceRuleVersion $version, Request $request, TenantContext $context, GovernanceRuleWorkflow $workflow)
    {
        $this->scopeVersion($version, $context);
        $workflow->approve($version, $request->user());

        return back();
    }

    public function activate(GovernanceRuleVersion $version, Request $request, TenantContext $context, GovernanceRuleWorkflow $workflow)
    {
        $this->scopeVersion($version, $context);
        $workflow->activate($version, $request->user());

        return back();
    }

    public function amend(GovernanceRuleVersion $version, Request $request, TenantContext $context, GovernanceRuleWorkflow $workflow)
    {
        $this->scopeVersion($version, $context);
        $workflow->amend($version, $request->user());

        return back();
    }

    private function scopeSource(GovernanceRuleSource $source, TenantContext $context): void
    {
        abort_unless($source->organization_id === $context->organization()->id, 404);
    }

    private function scopeVersion(GovernanceRuleVersion $version, TenantContext $context): void
    {
        abort_unless($version->rule && $version->rule->organization_id === $context->organization()->id, 404);
    }
}
