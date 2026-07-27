<?php

namespace App\Http\Controllers;

use App\Models\AgendaQuestionSubmission;
use App\Models\Assembly;
use App\Models\AssemblyAgendaItem;
use App\Models\AssemblyBallot;
use App\Models\AssemblyElectorate;
use App\Models\AssemblyMinutes;
use App\Models\AssemblyMinuteVersion;
use App\Models\AssemblyProxy;
use App\Models\AssemblyResolution;
use App\Models\ConvocationRecipient;
use App\Models\DecisionNotification;
use App\Models\GovernanceRuleVersion;
use App\Models\ResolutionExecutionAction;
use App\Services\AgendaQuestionService;
use App\Services\AgendaService;
use App\Services\AssemblyWorkflow;
use App\Services\AttendanceProxyService;
use App\Services\BallotService;
use App\Services\ConvocationService;
use App\Services\DecisionNotificationService;
use App\Services\ElectorateSnapshotService;
use App\Services\MinutesService;
use App\Services\QuorumService;
use App\Services\ResolutionExecutionService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GovernanceActionController extends Controller
{
    public function transition(Request $r, Assembly $a, TenantContext $c, AssemblyWorkflow $s)
    {
        $this->scope($a, $c);
        $this->authorize('transition', $a);
        $d = $r->validate(['status' => 'required|string|max:40', 'reason' => 'nullable|string|max:5000', 'idempotency_key' => 'nullable|string|max:100']);
        $s->transition($a, $d['status'], $r->user(), $d['reason'] ?? null, $d['idempotency_key'] ?? "ui:{$d['status']}:".now()->timestamp);

        return back();
    }

    public function officers(Request $r, Assembly $a, TenantContext $c)
    {
        $this->scope($a, $c);
        $d = $r->validate(['chairperson_contact_id' => ['required', Rule::exists('contacts', 'id')->where('organization_id', $a->organization_id)], 'secretary_user_id' => ['required', Rule::exists('users', 'id')]]);
        if (! in_array($a->status, ['scheduled', 'in_session'], true)) {
            throw ValidationException::withMessages(['officers' => __('Les responsables de séance ne peuvent être désignés à cette étape.')]);
        }$a->update($d);
        activity()->performedOn($a)->causedBy($r->user())->withProperties(['organization_id' => $a->organization_id, 'residence_id' => $a->residence_id] + $d)->log('governance.session_officers_designated');

        return back();
    }

    public function freeze(Request $r, Assembly $a, TenantContext $c, ElectorateSnapshotService $electorate, AgendaService $agenda, AssemblyWorkflow $flow)
    {
        $this->scope($a, $c);
        $this->authorize('update', $a);
        if ($a->status === 'draft') {
            $flow->transition($a, 'preparing', $r->user(), null, 'prepare');
        }$electorate->generate($a->fresh(), $r->user());
        $agenda->freeze($a->fresh(), $r->user());

        return back();
    }

    public function correctElectorate(Request $r, AssemblyElectorate $e, TenantContext $c, ElectorateSnapshotService $s)
    {
        $this->scope($e->assembly, $c);
        $d = $r->validate(['eligibility_status' => ['nullable', Rule::in(['eligible', 'ineligible', 'restricted'])], 'restriction_reason' => 'nullable|string|max:2000', 'voting_weight_numerator' => 'nullable|integer|min:0', 'reason' => 'required|string|min:10|max:2000']);
        $s->correct($e, collect($d)->except('reason')->filter(fn ($value) => $value !== null)->all(), $r->user(), $d['reason']);

        return back();
    }

    public function amendAgenda(Request $r, AssemblyAgendaItem $item, TenantContext $c, AgendaService $s)
    {
        $this->scope($item->assembly, $c);
        $d = $r->validate(['title_fr' => 'nullable|string|max:255', 'title_ar' => 'nullable|string|max:255', 'explanation_fr' => 'nullable|string|max:5000', 'explanation_ar' => 'nullable|string|max:5000', 'proposed_text_fr' => 'nullable|string|max:10000', 'proposed_text_ar' => 'nullable|string|max:10000', 'reason' => 'required|string|min:10|max:2000']);
        $s->amend($item, collect($d)->except('reason')->all(), $r->user(), $d['reason']);

        return back();
    }

    public function issue(Request $r, Assembly $a, TenantContext $c, ConvocationService $s)
    {
        $this->scope($a, $c);
        $this->authorize('issue', $a);
        $d = $r->validate(['late_exception' => 'boolean', 'reason' => 'nullable|string|max:5000']);
        $s->issue($a, $r->user(), (bool) ($d['late_exception'] ?? false), $d['reason'] ?? null);

        return back();
    }

    public function delivery(Request $r, ConvocationRecipient $recipient, TenantContext $c, ConvocationService $s)
    {
        $a = $recipient->convocation->assembly;
        $this->scope($a, $c);
        $r->validate(['method' => 'required|string', 'status' => 'required|string', 'reason' => 'nullable|string|max:5000', 'proof' => 'nullable|file|max:10240|extensions:pdf,jpg,jpeg,png|mimes:pdf,jpg,jpeg,png']);
        $s->recordDelivery($recipient, $r->string('method'), $r->string('status'), $r->user(), $r->input('reason'), $r->file('proof'));

        return back();
    }

    public function attendance(Request $r, Assembly $a, AssemblyElectorate $e, TenantContext $c, AttendanceProxyService $s)
    {
        $this->scope($a, $c);
        abort_unless($e->assembly_id === $a->id, 404);
        $d = $r->validate(['status' => ['required', Rule::in(['present', 'represented', 'absent', 'excluded', 'ineligible'])], 'reason' => 'nullable|string|max:1000']);
        $s->record($a, $e, $d['status'], $r->user(), $d['reason'] ?? null);

        return back();
    }

    public function verifyProxy(Request $r, AssemblyProxy $proxy, TenantContext $c, AttendanceProxyService $s)
    {
        $this->scope($proxy->assembly, $c);
        $s->verify($proxy, $r->user());

        return back();
    }

    public function revokeProxy(Request $r, AssemblyProxy $proxy, TenantContext $c, AttendanceProxyService $s)
    {
        $this->scope($proxy->assembly, $c);
        $d = $r->validate(['reason' => 'required|string|min:10|max:2000']);
        $s->revoke($proxy, $r->user(), $d['reason']);

        return back();
    }

    public function quorum(Request $r, Assembly $a, TenantContext $c, QuorumService $s)
    {
        $this->scope($a, $c);
        $rule = GovernanceRuleVersion::whereKey($r->validate(['rule_id' => 'required|exists:governance_rule_versions,id'])['rule_id'])->firstOrFail();
        $s->calculate($a, $rule, $r->user());

        return back();
    }

    public function ballot(Request $r, AssemblyResolution $resolution, TenantContext $c, BallotService $s)
    {
        $this->scope($resolution->assembly, $c);
        $d = $r->validate(['electorate_id' => ['required', Rule::exists('assembly_electorates', 'id')->where('assembly_id', $resolution->assembly_id)], 'choice' => ['required', Rule::in(['for', 'against', 'abstention', 'not_participating', 'ineligible', 'invalid'])], 'proxy_id' => 'nullable|integer']);
        $s->enter($resolution, AssemblyElectorate::findOrFail($d['electorate_id']), $d['choice'], $r->user(), $d['proxy_id'] ?? null);

        return back();
    }

    public function correctBallot(Request $r, AssemblyBallot $ballot, TenantContext $c, BallotService $s)
    {
        $this->scope($ballot->resolution->assembly, $c);
        $d = $r->validate(['choice' => 'required|string', 'reason' => 'required|string|min:10|max:2000']);
        $s->correct($ballot, $d['choice'], $r->user(), $d['reason']);

        return back();
    }

    public function finalize(Request $r, AssemblyResolution $resolution, TenantContext $c, BallotService $s)
    {
        $this->scope($resolution->assembly, $c);
        $s->finalize($resolution, $r->user());

        return back();
    }

    public function reopenResult(Request $r, AssemblyResolution $resolution, TenantContext $c, BallotService $s)
    {
        $this->scope($resolution->assembly, $c);
        $d = $r->validate(['reason' => 'required|string|min:15|max:2000']);
        $s->reopen($resolution, $r->user(), $d['reason']);

        return back();
    }

    public function second(Request $r, Assembly $a, TenantContext $c, AssemblyWorkflow $s)
    {
        $this->scope($a, $c);
        $d = $r->validate(['reference' => 'required|string|max:64', 'meeting_date' => 'required|date', 'starts_at' => 'required|date_format:H:i', 'expected_ends_at' => 'nullable|date_format:H:i']);
        $second = $s->secondConvocation($a, $d, $r->user());

        return to_route('governance.show', $second);
    }

    public function prepareMinutes(Request $r, Assembly $a, TenantContext $c, MinutesService $s)
    {
        $this->scope($a, $c);
        $d = $r->validate(['reservations_fr' => 'nullable|string|max:10000', 'reservations_ar' => 'nullable|string|max:10000', 'incidents_fr' => 'nullable|string|max:10000', 'incidents_ar' => 'nullable|string|max:10000']);
        $s->prepare($a, $d, $r->user());

        return back();
    }

    public function reviewMinutes(Request $r, AssemblyMinuteVersion $version, TenantContext $c, MinutesService $s)
    {
        $this->scope($version->minutes->assembly, $c);
        $s->review($version, $r->user());

        return back();
    }

    public function signMinutes(Request $r, AssemblyMinutes $minutes, TenantContext $c, MinutesService $s)
    {
        $this->scope($minutes->assembly, $c);
        $d = $r->validate(['chairperson' => 'required|string|max:160', 'secretary' => 'required|string|max:160', 'method' => ['required', Rule::in(['wet_signature_recorded', 'verified_internal_signature'])]]);
        $s->sign($minutes, $d, $r->user());

        return back();
    }

    public function correctiveAnnex(Request $r, AssemblyMinutes $minutes, TenantContext $c, MinutesService $s)
    {
        $this->scope($minutes->assembly, $c);
        $d = $r->validate(['reason' => 'required|string|min:10|max:2000', 'text_fr' => 'required|string|max:10000', 'text_ar' => 'nullable|string|max:10000']);
        $s->correctiveAnnex($minutes, $d['reason'], $d['text_fr'], $d['text_ar'] ?? null, $r->user());

        return back();
    }

    public function prepareNotifications(Request $r, Assembly $a, TenantContext $c, DecisionNotificationService $s)
    {
        $this->scope($a, $c);
        $s->prepare($a, $a->minutes->signedVersion, $r->user());

        return back();
    }

    public function deliverDecision(Request $r, DecisionNotification $notification, TenantContext $c, DecisionNotificationService $s)
    {
        $this->scope($notification->assembly, $c);
        $d = $r->validate(['channel' => 'required|string', 'status' => 'required|string', 'reason' => 'nullable|string|max:2000']);
        $s->deliver($notification, $d['channel'], $d['status'], $r->user(), $d['reason'] ?? null);

        return back();
    }

    public function execution(Request $r, AssemblyResolution $resolution, TenantContext $c, ResolutionExecutionService $s)
    {
        $this->scope($resolution->assembly, $c);
        $d = $r->validate(['action_type' => ['required', Rule::in(['budget_approval', 'account_approval', 'fund_call_preparation', 'exceptional_expense', 'major_maintenance_work', 'supplier_contract', 'maintenance_request', 'work_order', 'equipment_acquisition', 'equipment_retirement', 'syndic_appointment', 'regulation_amendment', 'other'])], 'responsible_user_id' => 'nullable|exists:users,id', 'due_on' => 'nullable|date', 'description' => 'required|string|max:5000', 'source_key' => 'required|string|max:120']);
        $s->create($resolution, $d, $r->user());

        return back();
    }

    public function fundCall(Request $r, ResolutionExecutionAction $action, TenantContext $c, ResolutionExecutionService $s)
    {
        $this->scope($action->resolution->assembly, $c);
        $d = $r->validate(['financial_exercise_id' => 'required|integer|exists:financial_exercises,id', 'title' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'issue_date' => 'required|date', 'due_date' => 'required|date|after_or_equal:issue_date']);
        $s->createDraftFundCall($action, $d + ['organization_id' => $action->organization_id, 'residence_id' => $action->residence_id], $r->user());

        return back();
    }

    public function maintenanceRequest(Request $r, ResolutionExecutionAction $action, TenantContext $c, ResolutionExecutionService $s)
    {
        $this->scope($action->resolution->assembly, $c);
        $d = $r->validate(['maintenance_category_id' => 'required|integer|exists:maintenance_categories,id', 'reference' => 'required|string|max:80', 'title' => 'required|string|max:255', 'description' => 'required|string|max:10000', 'location' => 'nullable|string|max:255', 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'observed_on' => 'nullable|date', 'sla_snapshot' => 'required|array']);
        $s->createMaintenanceRequest($action, $d, $r->user());

        return back();
    }

    public function completeExecution(Request $r, ResolutionExecutionAction $action, TenantContext $c, ResolutionExecutionService $s)
    {
        $this->scope($action->resolution->assembly, $c);
        $d = $r->validate(['result' => 'required|string|min:3|max:5000']);
        $s->complete($action, $d['result'], $r->user());

        return back();
    }

    public function decideQuestion(Request $r, AgendaQuestionSubmission $question, TenantContext $c, AgendaQuestionService $s)
    {
        $this->scope($question->assembly, $c);
        $d = $r->validate(['status' => ['required', Rule::in(['accepted', 'rejected'])], 'reason' => 'nullable|string|max:2000']);
        $s->decide($question, $d['status'], $r->user(), $d['reason'] ?? null);

        return back();
    }

    private function scope(Assembly $a, TenantContext $c): void
    {
        abort_unless($a->organization_id === $c->organization()->id && $a->residence_id === $c->residence()->id, 404);
    }
}
