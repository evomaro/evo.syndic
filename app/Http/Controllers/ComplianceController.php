<?php

namespace App\Http\Controllers;

use App\Exports\AccountingReportExport;
use App\Models\ComplianceApplicabilityDecision;
use App\Models\ComplianceApplicabilityProfile;
use App\Models\ComplianceAuthority;
use App\Models\ComplianceEvidence;
use App\Models\ComplianceEvidenceVersion;
use App\Models\ComplianceObligation;
use App\Models\ComplianceReminderOccurrence;
use App\Models\ComplianceReminderPolicy;
use App\Models\ComplianceSource;
use App\Models\ComplianceTemplate;
use App\Models\ComplianceTemplateVersion;
use App\Models\FinancialExercise;
use App\Models\User;
use App\Services\ComplianceApplicabilityService;
use App\Services\ComplianceEvidenceService;
use App\Services\ComplianceObligationWorkflow;
use App\Services\ComplianceOccurrenceService;
use App\Services\ComplianceTemplateWorkflow;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class ComplianceController extends Controller
{
    private const CATEGORIES = ['tax', 'social_employment', 'corporate_governance', 'coownership_governance', 'accounting', 'insurance', 'contract_renewal', 'property_safety', 'supplier_documentation', 'administrative_authorization', 'internal_control', 'other'];

    private const SCHEDULES = ['one_time', 'monthly', 'quarterly', 'semiannual', 'annual', 'fiscal_period', 'event_triggered', 'manual'];

    private const EVIDENCE_TYPES = ['source_document', 'preparation_document', 'submitted_form', 'submission_receipt', 'payment_receipt', 'authority_acknowledgement', 'rejection_notice', 'approval_record', 'correspondence', 'internal_note'];

    public function index(Request $request, TenantContext $context)
    {
        $organization = $context->organization();
        $residence = $context->residence;
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->input('month'))
            ? (string) $request->input('month')
            : null;
        $query = ComplianceObligation::query()->where('organization_id', $organization->id)
            ->when($residence, fn ($q) => $q->where(fn ($scope) => $scope->whereNull('residence_id')->orWhere('residence_id', $residence->id)))
            ->when($request->filled('residence_id'), fn ($q) => $q->where('residence_id', $request->integer('residence_id')))
            ->when($request->filled('category'), fn ($q) => $q->whereHas('template', fn ($t) => $t->where('category', $request->string('category'))))
            ->when($request->filled('state'), fn ($q) => $q->where('operational_status', $request->string('state')))
            ->when($request->filled('deadline'), fn ($q) => $q->where('deadline_status', $request->string('deadline')))
            ->when($request->filled('fiscal_year_id'), fn ($q) => $q->where('financial_exercise_id', $request->integer('fiscal_year_id')))
            ->when($request->filled('authority_id'), fn ($q) => $q->whereHas('template', fn ($template) => $template->where('authority_id', $request->integer('authority_id'))))
            ->when($request->filled('assignee_id'), fn ($q) => $q->whereHas('assignments', fn ($assignment) => $assignment
                ->where('user_id', $request->integer('assignee_id'))->whereNull('ended_at')))
            ->when($month, fn ($q, string $value) => $q
                ->whereYear('current_due_on', substr($value, 0, 4))
                ->whereMonth('current_due_on', substr($value, 5, 2)))
            ->with(['template.authority', 'templateVersion', 'assignments' => fn ($q) => $q->whereNull('ended_at')])
            ->orderByRaw('CASE WHEN current_due_on IS NULL THEN 1 ELSE 0 END')->orderBy('current_due_on')->orderBy('id');

        return Inertia::render('Compliance/Index', [
            'obligations' => $query->paginate(30)->withQueryString(),
            'metrics' => [
                'overdue' => (clone $query)->where('deadline_status', 'overdue')->count(),
                'upcoming' => (clone $query)->whereIn('deadline_status', ['upcoming', 'due_soon'])->count(),
                'unassigned' => (clone $query)->whereDoesntHave('assignments', fn ($q) => $q->where('assignment_type', 'responsible')->whereNull('ended_at'))->count(),
                'undetermined' => ComplianceApplicabilityDecision::where('organization_id', $organization->id)->whereIn('outcome', ['undetermined', 'professional_review_required'])->count(),
            ],
            'templates' => ComplianceTemplate::where('organization_id', $organization->id)->with(['authority', 'versions.source'])->orderBy('code')->get(),
            'authorities' => ComplianceAuthority::where('organization_id', $organization->id)->orderBy('code')->get(),
            'sources' => ComplianceSource::where('organization_id', $organization->id)->with('authority')->orderByDesc('id')->limit(100)->get(),
            'policies' => ComplianceReminderPolicy::where('organization_id', $organization->id)->orderBy('name')->get(),
            'deliveries' => ComplianceReminderOccurrence::where('organization_id', $organization->id)->latest()->limit(50)->get(),
            'members' => $organization->users()->get(['users.id', 'users.name']),
            'exercises' => FinancialExercise::where('organization_id', $organization->id)
                ->when($residence, fn ($q) => $q->where('residence_id', $residence->id))
                ->orderByDesc('starts_on')->get(['id', 'reference', 'starts_on', 'ends_on']),
            'filters' => $request->only([
                'residence_id', 'category', 'state', 'deadline', 'fiscal_year_id',
                'authority_id', 'assignee_id', 'month', 'view',
            ]),
            'categories' => self::CATEGORIES,
            'disclaimer' => __('Outil opérationnel configurable — ne constitue pas un conseil juridique ou fiscal.'),
        ]);
    }

    public function show(ComplianceObligation $obligation, TenantContext $context)
    {
        $this->scope($obligation, $context);

        return Inertia::render('Compliance/Show', [
            'obligation' => $obligation->load(['template.authority', 'templateVersion.source', 'assignments', 'transitions', 'submissions', 'evidence.versions']),
            'members' => $context->organization()->users()->get(['users.id', 'users.name']),
            'evidenceTypes' => self::EVIDENCE_TYPES,
            'disclaimer' => __('Une tâche terminée en interne n’est pas une acceptation par une autorité.'),
        ]);
    }

    public function authority(Request $request, TenantContext $context)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:80', Rule::unique('compliance_authorities')->where('organization_id', $context->organization()->id)], 'jurisdiction' => 'required|string|max:100', 'name_fr' => 'required|string|max:255', 'name_ar' => 'required|string|max:255']);
        $authority = ComplianceAuthority::create($data + ['organization_id' => $context->organization()->id]);
        activity()->performedOn($authority)->causedBy($request->user())->withProperties(['organization_id' => $context->organization()->id])->log('compliance.authority_created');

        return back()->with('success', __('Autorité enregistrée sans implication d’applicabilité.'));
    }

    public function source(Request $request, TenantContext $context)
    {
        $data = $request->validate([
            'authority_id' => ['required', Rule::exists('compliance_authorities', 'id')->where('organization_id', $context->organization()->id)], 'official_title' => 'required|string|max:255',
            'official_url' => 'nullable|url|max:2000', 'document_reference' => 'nullable|string|max:255',
            'published_on' => 'nullable|date', 'effective_on' => 'nullable|date', 'notes_fr' => 'nullable|string', 'notes_ar' => 'nullable|string',
        ]);
        $source = ComplianceSource::create($data + ['organization_id' => $context->organization()->id, 'confidence' => 'unverified_draft', 'version' => 1]);
        activity()->performedOn($source)->causedBy($request->user())->withProperties(['organization_id' => $context->organization()->id])->log('compliance.source_created');

        return back()->with('success', __('Source créée comme brouillon non vérifié.'));
    }

    public function verifySource(ComplianceSource $source, Request $request, TenantContext $context, ComplianceTemplateWorkflow $workflow)
    {
        abort_unless($source->organization_id === $context->organization()->id, 404);
        $workflow->verifySource($source, $request->user());

        return back()->with('success', __('Vérification de source enregistrée.'));
    }

    public function template(Request $request, TenantContext $context)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', Rule::unique('compliance_templates')->where('organization_id', $context->organization()->id)], 'jurisdiction' => 'required|string|max:100',
            'category' => ['required', Rule::in(self::CATEGORIES)], 'authority_id' => 'required|exists:compliance_authorities,id',
            'source_id' => 'nullable|exists:compliance_sources,id', 'title_fr' => 'required|string|max:255', 'title_ar' => 'required|string|max:255',
            'applicability_description_fr' => 'required|string', 'applicability_description_ar' => 'required|string',
            'applicability_rule' => 'nullable|array', 'schedule_type' => ['required', Rule::in(self::SCHEDULES)],
            'deadline_rule' => 'required|array', 'calculation_method_fr' => 'required|string', 'calculation_method_ar' => 'required|string',
            'required_evidence_fr' => 'required|string', 'required_evidence_ar' => 'required|string',
            'effective_from' => 'nullable|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);
        abort_unless(ComplianceAuthority::where('organization_id', $context->organization()->id)->whereKey($data['authority_id'])->exists(), 422);
        if ($data['source_id'] ?? null) {
            abort_unless(ComplianceSource::where('organization_id', $context->organization()->id)->whereKey($data['source_id'])->exists(), 422);
        }
        $template = ComplianceTemplate::create(collect($data)->only(['code', 'jurisdiction', 'category', 'authority_id'])->all() + ['organization_id' => $context->organization()->id]);
        ComplianceTemplateVersion::create(collect($data)->except(['code', 'jurisdiction', 'category', 'authority_id'])->all() + [
            'template_id' => $template->id, 'version' => 1, 'status' => 'draft', 'confidence' => 'unverified_draft',
            'professional_review_required' => true, 'professional_review_status' => 'pending', 'counsel_review_status' => 'not_required',
        ]);
        activity()->performedOn($template)->causedBy($request->user())->log('compliance.template_created');

        return back()->with('success', __('Modèle créé comme brouillon non activable sans source et revues.'));
    }

    public function approve(ComplianceTemplateVersion $version, Request $request, TenantContext $context, ComplianceTemplateWorkflow $workflow)
    {
        $this->templateVersionScope($version, $context);
        $workflow->approve($version, $request->user());

        return back()->with('success', __('Version approuvée; activation encore requise.'));
    }

    public function reviewVersion(ComplianceTemplateVersion $version, Request $request, TenantContext $context, ComplianceTemplateWorkflow $workflow)
    {
        $this->templateVersionScope($version, $context);
        $workflow->professionalReview($version, $request->user());

        return back()->with('success', __('Revue professionnelle enregistrée; approbation et activation restent séparées.'));
    }

    public function amend(ComplianceTemplateVersion $version, Request $request, TenantContext $context, ComplianceTemplateWorkflow $workflow)
    {
        $this->templateVersionScope($version, $context);
        $workflow->createAmendment($version, $request->user());

        return back()->with('success', __('Nouvelle version brouillon créée; l’historique reste inchangé.'));
    }

    public function activate(ComplianceTemplateVersion $version, Request $request, TenantContext $context, ComplianceTemplateWorkflow $workflow)
    {
        $this->templateVersionScope($version, $context);
        $workflow->activate($version, $request->user());

        return back()->with('success', __('Version activée.'));
    }

    public function withdrawVersion(ComplianceTemplateVersion $version, Request $request, TenantContext $context, ComplianceTemplateWorkflow $workflow)
    {
        $this->templateVersionScope($version, $context);
        $data = $request->validate(['reason' => 'required|string|max:3000']);
        $workflow->withdraw($version, $request->user(), $data['reason']);

        return back()->with('success', __('Version retirée; les obligations historiques restent conservées.'));
    }

    public function applicability(Request $request, ComplianceTemplateVersion $version, TenantContext $context, ComplianceApplicabilityService $service)
    {
        $data = $request->validate(['attributes' => 'required|array', 'deadline_inputs' => 'nullable|array', 'financial_exercise_id' => 'nullable|integer']);
        $profile = ComplianceApplicabilityProfile::updateOrCreate(
            ['organization_id' => $context->organization()->id, 'residence_id' => $context->residence?->id],
            ['attributes' => $data['attributes'], 'updated_by' => $request->user()->id]
        );
        $decision = $service->decide($version, $profile->organization_id, $profile->residence_id, $profile->attributes, $request->user(), $data['financial_exercise_id'] ?? null, $data['deadline_inputs'] ?? []);

        return back()->with('success', __('Décision d’applicabilité : :outcome', ['outcome' => $decision->outcome]));
    }

    public function generate(Request $request, ComplianceApplicabilityDecision $decision, TenantContext $context, ComplianceOccurrenceService $service)
    {
        abort_unless($decision->organization_id === $context->organization()->id && $decision->residence_id === $context->residence?->id, 404);
        $inputs = $request->validate(['inputs' => 'required|array'])['inputs'];
        $timezone = $context->residence?->timezone ?: $context->organization()->timezone;
        $obligation = $service->generate($decision, $inputs, $timezone);

        return redirect()->route('compliance.obligations.show', $obligation)->with('success', __('Obligation générée de façon idempotente.'));
    }

    public function overrideApplicability(Request $request, ComplianceApplicabilityDecision $decision, TenantContext $context, ComplianceApplicabilityService $service)
    {
        abort_unless($decision->organization_id === $context->organization()->id && $decision->residence_id === $context->residence?->id, 404);
        $data = $request->validate(['outcome' => ['required', Rule::in(['applies', 'does_not_apply', 'undetermined', 'professional_review_required', 'temporarily_waived', 'superseded'])], 'reason' => 'required|string|max:3000', 'evidence_reference' => 'required|string|max:255']);
        $service->override($decision, $data['outcome'], $data['reason'], $data['evidence_reference'], $request->user());

        return back()->with('success', __('Dérogation enregistrée sans supprimer l’historique.'));
    }

    public function assign(Request $request, ComplianceObligation $obligation, TenantContext $context, ComplianceObligationWorkflow $workflow)
    {
        $this->scope($obligation, $context);
        $data = $request->validate(['user_id' => 'nullable|integer', 'role' => 'nullable|string|max:64', 'assignment_type' => ['required', Rule::in(['responsible', 'reviewer', 'escalation', 'watcher'])]]);
        $user = isset($data['user_id']) ? User::findOrFail($data['user_id']) : null;
        $workflow->assign($obligation, $user, $data['role'] ?? null, $data['assignment_type'], $request->user());

        return back()->with('success', __('Affectation enregistrée.'));
    }

    public function submission(Request $request, ComplianceObligation $obligation, TenantContext $context, ComplianceObligationWorkflow $workflow)
    {
        $this->scope($obligation, $context);
        $data = $request->validate(['submitted_on' => 'required|date', 'method' => 'required|string|max:64', 'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:3000']);
        $workflow->submit($obligation, $data, $request->user());

        return back()->with('success', __('Tentative de soumission enregistrée; aucune acceptation externe n’est déduite.'));
    }

    public function transition(Request $request, ComplianceObligation $obligation, TenantContext $context, ComplianceObligationWorkflow $workflow)
    {
        $this->scope($obligation, $context);
        $data = $request->validate(['status' => 'required|string|max:48', 'reason' => 'nullable|string|max:3000', 'evidence_id' => 'nullable|integer']);
        $permission = match ($data['status']) {
            'ready_for_submission' => 'review_compliance_obligations',
            'submitted' => 'record_compliance_submissions',
            'acknowledged', 'accepted' => 'record_external_acknowledgement',
            'rejected', 'correction_required' => 'record_compliance_rejection',
            'waived', 'not_applicable' => 'override_compliance_applicability',
            default => 'prepare_compliance_obligations',
        };
        abort_unless($request->user()->canInOrganization($permission, $context->organization()), 403);
        $evidence = isset($data['evidence_id']) ? ComplianceEvidence::where('obligation_id', $obligation->id)->findOrFail($data['evidence_id']) : null;
        $workflow->transition($obligation, $data['status'], $request->user(), $data['reason'] ?? null, $evidence);

        return back()->with('success', __('État mis à jour avec historique immuable.'));
    }

    public function overrideDeadline(Request $request, ComplianceObligation $obligation, TenantContext $context, ComplianceObligationWorkflow $workflow)
    {
        $this->scope($obligation, $context);
        $data = $request->validate(['new_due_on' => 'required|date', 'reason' => 'required|string|max:3000', 'evidence_reference' => 'required|string|max:255']);
        $workflow->overrideDeadline($obligation, $data['new_due_on'], $data['reason'], $data['evidence_reference'], $request->user());

        return back()->with('success', __('Échéance remplacée; l’échéance originale est conservée.'));
    }

    public function evidence(Request $request, ComplianceObligation $obligation, TenantContext $context, ComplianceEvidenceService $service)
    {
        $this->scope($obligation, $context);
        $data = $request->validate(['type' => ['required', Rule::in(self::EVIDENCE_TYPES)], 'title' => 'required|string|max:255', 'submission_id' => 'nullable|integer', 'file' => 'required|file|max:20480']);
        $service->store($obligation, $data['type'], $data['title'], $data['file'], $request->user(), $data['submission_id'] ?? null);

        return back()->with('success', __('Preuve sécurisée enregistrée.'));
    }

    public function download(ComplianceEvidenceVersion $version, TenantContext $context, ComplianceEvidenceService $service)
    {
        $version->load('evidence.obligation');
        $this->scope($version->evidence->obligation, $context);
        $service->assertIntegrity($version);
        activity()->performedOn($version->evidence->obligation)->causedBy(request()->user())->withProperties(['organization_id' => $version->evidence->organization_id, 'residence_id' => $version->evidence->residence_id, 'evidence_id' => $version->evidence_id, 'version' => $version->version])->log('compliance.evidence_downloaded');

        return Storage::disk($version->disk)->download($version->path, $version->name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function policy(Request $request, TenantContext $context)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'triggers' => 'required|array', 'recipient_types' => 'required|array', 'database_enabled' => 'boolean', 'email_enabled' => 'boolean', 'digest' => 'boolean']);
        ComplianceReminderPolicy::create($data + ['organization_id' => $context->organization()->id, 'residence_id' => $context->residence?->id, 'created_by' => $request->user()->id, 'active' => true]);

        return back()->with('success', __('Politique de rappel enregistrée.'));
    }

    public function export(Request $request, string $format, TenantContext $context)
    {
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf', 'json'], true), 404);
        $family = $request->validate([
            'family' => ['nullable', Rule::in(['register', 'evidence', 'submissions', 'overdue'])],
            'state' => 'nullable|string|max:48',
            'deadline' => 'nullable|string|max:48',
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'authority_id' => 'nullable|integer',
            'assignee_id' => 'nullable|integer',
            'fiscal_year_id' => 'nullable|integer',
        ])['family'] ?? 'register';
        $query = ComplianceObligation::query()->where('organization_id', $context->organization()->id)
            ->when($context->residence, fn ($q) => $q->where(fn ($scope) => $scope->whereNull('residence_id')->orWhere('residence_id', $context->residence->id)))
            ->when($request->filled('state'), fn ($q) => $q->where('operational_status', $request->string('state')))
            ->when($request->filled('deadline'), fn ($q) => $q->where('deadline_status', $request->string('deadline')))
            ->when($request->filled('category'), fn ($q) => $q->whereHas('template', fn ($template) => $template->where('category', $request->string('category'))))
            ->when($request->filled('authority_id'), fn ($q) => $q->whereHas('template', fn ($template) => $template->where('authority_id', $request->integer('authority_id'))))
            ->when($request->filled('assignee_id'), fn ($q) => $q->whereHas('assignments', fn ($assignment) => $assignment
                ->where('user_id', $request->integer('assignee_id'))->whereNull('ended_at')))
            ->when($request->filled('fiscal_year_id'), fn ($q) => $q->where('financial_exercise_id', $request->integer('fiscal_year_id')))
            ->when($family === 'overdue', fn ($q) => $q->where('deadline_status', 'overdue'))
            ->with([
                'template.authority', 'templateVersion', 'source',
                'assignments' => fn ($q) => $q->whereNull('ended_at'),
                'evidence.versions', 'submissions',
            ])
            ->orderBy('current_due_on')->orderBy('id');
        $snapshotAt = now('UTC');
        $snapshotId = (clone $query)->max('id') ?: 0;
        $records = $query->where('id', '<=', $snapshotId)->get();
        $base = fn ($row) => [
            'obligation_id' => $row->id,
            'organization' => $context->organization()->name,
            'residence' => $row->residence_id ? (string) $row->residence_id : __('Organisation'),
            'reporting_period' => $row->reporting_period,
            'template_code' => $row->template->code,
            'template_version' => (string) $row->templateVersion->version,
            'title' => app()->getLocale() === 'ar' ? $row->templateVersion->title_ar : $row->templateVersion->title_fr,
            'authority' => app()->getLocale() === 'ar' ? $row->template->authority->name_ar : $row->template->authority->name_fr,
        ];
        $rows = match ($family) {
            'evidence' => $records->flatMap(fn ($row) => $row->evidence->flatMap(
                fn ($evidence) => $evidence->versions->map(fn ($version) => $base($row) + [
                    'evidence_id' => $evidence->id,
                    'evidence_type' => $evidence->type,
                    'evidence_title' => $evidence->title,
                    'version' => $version->version,
                    'file_name' => $version->name,
                    'mime_type' => $version->mime_type,
                    'size' => $version->size,
                    'checksum' => $version->checksum,
                    'uploaded_at' => $version->created_at?->toIso8601String(),
                ])
            ))->values()->all(),
            'submissions' => $records->flatMap(fn ($row) => $row->submissions->map(
                fn ($submission) => $base($row) + [
                    'submission_id' => $submission->id,
                    'submitted_on' => $submission->submitted_on?->toDateString(),
                    'method' => $submission->method,
                    'reference' => $submission->reference,
                    'recorded_at' => $submission->recorded_at?->toIso8601String(),
                ]
            ))->values()->all(),
            default => $records->map(fn ($row) => $base($row) + [
                'source_verification' => $row->source?->confidence,
                'original_due_on' => $row->original_due_on?->toDateString(),
                'current_due_on' => $row->current_due_on?->toDateString(),
                'operational_state' => $row->operational_status,
                'deadline_classification' => $row->deadline_status,
                'responsible_user_ids' => $row->assignments->where('assignment_type', 'responsible')
                    ->pluck('user_id')->filter()->sort()->implode(','),
                'generated_at' => $row->generated_at?->toIso8601String(),
                'notice' => __('Ne constitue pas un conseil juridique ou fiscal.'),
            ])->values()->all(),
        };
        $rows = array_map(fn (array $row) => collect($row)->map(
            fn ($value) => is_string($value) ? $this->safe($value) : $value
        )->all(), $rows);
        activity()->causedBy($request->user())->withProperties([
            'organization_id' => $context->organization()->id,
            'residence_id' => $context->residence?->id,
            'format' => $format,
            'family' => $family,
            'snapshot_id' => $snapshotId,
            'rows' => count($rows),
        ])->log('compliance.register_exported');
        if ($format === 'json') {
            return response()->json([
                'family' => $family,
                'snapshot_at' => $snapshotAt,
                'snapshot_id' => $snapshotId,
                'filters' => $request->only([
                    'state', 'deadline', 'category', 'authority_id', 'assignee_id', 'fiscal_year_id',
                ]),
                'rows' => $rows,
                'not_legal_or_tax_advice' => true,
            ]);
        }
        if ($format === 'pdf') {
            return Pdf::loadView('pdf.compliance-register', ['rows' => $rows, 'snapshotAt' => $snapshotAt, 'organization' => $context->organization()])->setPaper('a4', 'landscape')->download("compliance-register-{$snapshotId}.pdf");
        }
        $headings = $rows ? array_keys($rows[0]) : [];

        return Excel::download(new AccountingReportExport(array_map('array_values', $rows), $headings), "compliance-{$family}-{$snapshotId}.{$format}", $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX);
    }

    private function scope(ComplianceObligation $obligation, TenantContext $context): void
    {
        abort_unless($obligation->organization_id === $context->organization()->id, 404);
        if ($obligation->residence_id !== null) {
            abort_unless($context->residence && $obligation->residence_id === $context->residence->id, 404);
        }
    }

    private function templateVersionScope(ComplianceTemplateVersion $version, TenantContext $context): void
    {
        abort_unless($version->template()->where('organization_id', $context->organization()->id)->exists(), 404);
    }

    private function safe(?string $value): ?string
    {
        return $value !== null && preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
