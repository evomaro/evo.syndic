<?php

namespace App\Http\Controllers;

use App\Models\Assembly;
use App\Models\AssemblyElectorate;
use App\Models\AssemblyEligibilitySnapshot;
use App\Models\AssemblyMinuteVersion;
use App\Models\AssemblyQuorumSnapshot;
use App\Models\AssemblyResolution;
use App\Models\GovernanceDocumentVersion;
use App\Models\GovernanceRuleVersion;
use App\Models\GovernanceVotingShareSource;
use App\Services\GovernanceIntegrityAuditService;
use App\Services\PhaseSevenEligibilityService;
use App\Services\PhaseSevenGovernanceWorkflow;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PhaseSevenGovernanceController extends Controller
{
    public function diagnostics(TenantContext $context, GovernanceIntegrityAuditService $audit)
    {
        $filters = ['organization' => $context->organization()->id, 'residence' => $context->residence()->id];

        return Inertia::render('Governance/Diagnostics', [
            'reports' => collect(['assemblies', 'eligibility', 'votes', 'resolutions', 'evidence'])
                ->mapWithKeys(fn ($kind) => [$kind => $audit->{$kind}($filters)]),
            'notice' => __('Diagnostics techniques en lecture seule — aucun résultat juridique n’est certifié.'),
        ]);
    }

    public function generateEligibility(Request $request, Assembly $assembly, TenantContext $context, PhaseSevenEligibilityService $service)
    {
        $this->scope($assembly, $context);
        $data = $request->validate(['voting_share_source_id' => 'nullable|integer|exists:governance_voting_share_sources,id']);
        $source = isset($data['voting_share_source_id']) ? GovernanceVotingShareSource::findOrFail($data['voting_share_source_id']) : null;
        $service->generate($assembly, $source, $request->user());

        return back();
    }

    public function reviewEligibility(Request $request, AssemblyEligibilitySnapshot $snapshot, TenantContext $context, PhaseSevenEligibilityService $service)
    {
        $this->scope($snapshot->assembly, $context);
        $service->review($snapshot, $request->user());

        return back();
    }

    public function overrideEligibility(Request $request, AssemblyElectorate $interest, TenantContext $context, PhaseSevenEligibilityService $service)
    {
        $this->scope($interest->assembly, $context);
        $data = $request->validate([
            'eligibility_status' => ['nullable', Rule::in(['eligible', 'restricted', 'ineligible', 'indeterminate'])],
            'restriction_reason' => 'nullable|string|max:5000',
            'voting_weight_numerator' => 'nullable|integer|min:0',
            'reason' => 'required|string|min:10|max:5000',
            'evidence_version_id' => 'required|integer|exists:governance_document_versions,id',
        ]);
        $service->overrideInterest(
            $interest,
            collect($data)->only(['eligibility_status', 'restriction_reason', 'voting_weight_numerator'])->filter(fn ($value) => $value !== null)->all(),
            $data['reason'],
            GovernanceDocumentVersion::findOrFail($data['evidence_version_id']),
            $request->user(),
        );

        return back();
    }

    public function previewQuorum(Request $request, Assembly $assembly, TenantContext $context, PhaseSevenGovernanceWorkflow $workflow)
    {
        $this->scope($assembly, $context);
        $data = $request->validate(['rule_version_id' => 'required|integer|exists:governance_rule_versions,id']);
        $rule = GovernanceRuleVersion::findOrFail($data['rule_version_id']);
        abort_unless(! $rule->rule || $rule->rule->organization_id === $context->organization()->id, 404);
        $workflow->previewQuorum($assembly, $rule, $request->user());

        return back();
    }

    public function confirmQuorum(Request $request, AssemblyQuorumSnapshot $snapshot, TenantContext $context, PhaseSevenGovernanceWorkflow $workflow)
    {
        $this->scope($snapshot->assembly, $context);
        $workflow->confirmQuorum($snapshot, $request->user());

        return back();
    }

    public function openVoting(Request $request, AssemblyResolution $resolution, TenantContext $context, PhaseSevenGovernanceWorkflow $workflow)
    {
        $this->scope($resolution->assembly, $context);
        $data = $request->validate(['mode' => ['required', Rule::in(['recorded_interest', 'recorded_participant', 'secret_aggregate'])]]);
        $workflow->openVoting($resolution, $data['mode'], $request->user());

        return back();
    }

    public function closeSecretBallot(Request $request, AssemblyResolution $resolution, TenantContext $context, PhaseSevenGovernanceWorkflow $workflow)
    {
        $this->scope($resolution->assembly, $context);
        $data = $request->validate([
            'for' => 'required|integer|min:0', 'against' => 'required|integer|min:0',
            'abstention' => 'required|integer|min:0', 'invalid' => 'required|integer|min:0',
            'not_cast' => 'required|integer|min:0', 'denominator' => 'required|integer|min:1',
            'evidence_version_id' => 'required|integer|exists:governance_document_versions,id',
            'reviewer_user_id' => 'required|integer|exists:users,id|different:recorded_by',
        ]);
        $reviewer = $context->organization()->users()->whereKey($data['reviewer_user_id'])->firstOrFail();
        $workflow->closeSecretBallot(
            $resolution,
            $data,
            GovernanceDocumentVersion::findOrFail($data['evidence_version_id']),
            $request->user(),
            $reviewer,
        );

        return back();
    }

    public function challenge(Request $request, AssemblyResolution $resolution, TenantContext $context, PhaseSevenGovernanceWorkflow $workflow)
    {
        $this->scope($resolution->assembly, $context);
        $data = $request->validate([
            'status' => ['required', Rule::in(['under_challenge', 'suspended', 'failed', 'superseded'])],
            'reason' => 'required|string|min:10|max:5000',
            'evidence_version_id' => 'required|integer|exists:governance_document_versions,id',
        ]);
        $workflow->challenge($resolution, $data['status'], $data['reason'], GovernanceDocumentVersion::findOrFail($data['evidence_version_id']), $request->user());

        return back();
    }

    public function approveMinutes(Request $request, AssemblyMinuteVersion $version, TenantContext $context, PhaseSevenGovernanceWorkflow $workflow)
    {
        $this->scope($version->minutes->assembly, $context);
        $data = $request->validate([
            'type' => ['required', Rule::in(['review', 'approval'])],
            'evidence_version_id' => 'nullable|integer|exists:governance_document_versions,id',
        ]);
        $workflow->approveMinutes(
            $version,
            $data['type'],
            $request->user(),
            isset($data['evidence_version_id']) ? GovernanceDocumentVersion::findOrFail($data['evidence_version_id']) : null,
        );

        return back();
    }

    public function finalizeAssembly(Request $request, Assembly $assembly, TenantContext $context, PhaseSevenGovernanceWorkflow $workflow)
    {
        $this->scope($assembly, $context);
        $workflow->finalizeAssembly($assembly, $request->user());

        return back();
    }

    private function scope(Assembly $assembly, TenantContext $context): void
    {
        abort_unless(
            $assembly->organization_id === $context->organization()->id
            && $assembly->residence_id === $context->residence()->id,
            404,
        );
    }
}
