<?php

namespace App\Services;

use App\Models\ComplianceEvidenceVersion;
use App\Models\ComplianceObligation;
use App\Models\ComplianceReminderOccurrence;
use App\Models\ComplianceTemplateVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ComplianceAuditService
{
    public function templates(array $filters = []): array
    {
        $violations = [];
        $query = ComplianceTemplateVersion::query()->with('source');
        $this->templateFilter($query, $filters);
        $versions = $query->get();
        foreach ($versions as $version) {
            if ($version->effective_from && $version->effective_until && $version->effective_until->lt($version->effective_from)) {
                $violations[] = $this->violation('invalid_effective_dates', "template_version:{$version->id}");
            }
            if ($version->status === 'active' && (! $version->source || ! in_array($version->source->confidence, ['source_verified', 'professionally_reviewed', 'counsel_reviewed'], true))) {
                $violations[] = $this->violation('active_template_without_verified_source', "template_version:{$version->id}");
            }
        }
        $overlaps = ComplianceTemplateVersion::query()->where('status', 'active')->select('template_id')->groupBy('template_id')->havingRaw('COUNT(*) > 1')->pluck('template_id');
        foreach ($overlaps as $templateId) {
            $violations[] = $this->violation('overlapping_active_template_versions', "template:{$templateId}");
        }

        return $this->report('templates', $versions->count(), $violations);
    }

    public function obligations(array $filters = []): array
    {
        $violations = [];
        $query = ComplianceObligation::query()->with(['templateVersion', 'submissions', 'evidence', 'transitions', 'assignments']);
        $this->obligationFilter($query, $filters);
        $obligations = $query->get();
        foreach ($obligations as $obligation) {
            if (! $obligation->applicability_decision_id) {
                $violations[] = $this->violation('missing_applicability_decision', "obligation:{$obligation->id}");
            }
            if ($obligation->templateVersion?->status !== 'active') {
                $violations[] = $this->violation('occurrence_from_non_active_template', "obligation:{$obligation->id}");
            }
            if ($obligation->operational_status === 'submitted' && $obligation->submissions->isEmpty()) {
                $violations[] = $this->violation('submitted_without_submission_record', "obligation:{$obligation->id}");
            }
            if ($obligation->operational_status === 'accepted' && ! $obligation->evidence->contains(fn ($e) => in_array($e->type, ['authority_acknowledgement', 'approval_record'], true))) {
                $violations[] = $this->violation('accepted_without_acknowledgement_evidence', "obligation:{$obligation->id}");
            }
            if ($obligation->transitions->isEmpty()) {
                $violations[] = $this->violation('missing_activity_transition_evidence', "obligation:{$obligation->id}", false);
            }
            if (! in_array($obligation->operational_status, ['accepted', 'completed_internally', 'waived', 'not_applicable', 'cancelled', 'superseded'], true)
                && ! $obligation->assignments->contains(fn ($a) => $a->assignment_type === 'responsible' && ! $a->ended_at)) {
                $violations[] = $this->violation('missing_assignment', "obligation:{$obligation->id}", false);
            }
        }
        $duplicates = ComplianceObligation::query()->select('organization_id', 'occurrence_key')->groupBy('organization_id', 'occurrence_key')->havingRaw('COUNT(*) > 1')->get();
        foreach ($duplicates as $duplicate) {
            $violations[] = $this->violation('duplicate_obligation_occurrence', "organization:{$duplicate->organization_id}");
        }

        return $this->report('obligations', $obligations->count(), $violations);
    }

    public function reminders(array $filters = []): array
    {
        $violations = [];
        $query = ComplianceReminderOccurrence::query()->with('recipient');
        if ($filters['organization'] ?? null) {
            $query->where('organization_id', $filters['organization']);
        }
        if ($filters['residence'] ?? null) {
            $query->where('residence_id', $filters['residence']);
        }
        $reminders = $query->get();
        foreach ($reminders as $reminder) {
            if (! $reminder->idempotency_key) {
                $violations[] = $this->violation('missing_delivery_idempotency_key', "reminder:{$reminder->id}");
            }
            if (! DB::table('organization_user')->where('organization_id', $reminder->organization_id)->where('user_id', $reminder->recipient_user_id)->exists()) {
                $violations[] = $this->violation('reminder_sent_to_unauthorized_user', "reminder:{$reminder->id}");
            }
            $terminal = ComplianceObligation::whereKey($reminder->obligation_id)->whereIn('operational_status', ['accepted', 'completed_internally', 'waived', 'not_applicable', 'cancelled', 'superseded'])->exists();
            if ($reminder->delivered_at && $terminal) {
                $violations[] = $this->violation('reminder_sent_after_completion', "reminder:{$reminder->id}");
            }
        }

        return $this->report('reminders', $reminders->count(), $violations);
    }

    public function evidence(array $filters = []): array
    {
        $violations = [];
        $query = ComplianceEvidenceVersion::query()->with('evidence.obligation');
        $versions = $query->get();
        foreach ($versions as $version) {
            $evidence = $version->evidence;
            if (! $evidence?->obligation) {
                $violations[] = $this->violation('orphan_evidence', "evidence_version:{$version->id}");

                continue;
            }
            if ($evidence->organization_id !== $evidence->obligation->organization_id || $evidence->residence_id !== $evidence->obligation->residence_id) {
                $violations[] = $this->violation('cross_scope_evidence', "evidence:{$evidence->id}");
            }
            if (! Storage::disk($version->disk)->exists($version->path) || ! hash_equals($version->checksum, hash('sha256', Storage::disk($version->disk)->get($version->path)))) {
                $violations[] = $this->violation('broken_evidence_checksum', "evidence_version:{$version->id}");
            }
        }

        return $this->report('evidence', $versions->count(), $violations);
    }

    private function templateFilter(Builder $query, array $filters): void
    {
        if ($filters['template'] ?? null) {
            $query->where('template_id', $filters['template']);
        }
    }

    private function obligationFilter(Builder $query, array $filters): void
    {
        foreach (['organization' => 'organization_id', 'residence' => 'residence_id', 'template' => 'template_id', 'obligation' => 'id', 'status' => 'operational_status'] as $key => $column) {
            if ($filters[$key] ?? null) {
                $query->where($column, $filters[$key]);
            }
        }
        if ($filters['fiscal-year'] ?? null) {
            $query->where('financial_exercise_id', $filters['fiscal-year']);
        }
    }

    private function violation(string $check, string $record, bool $blocking = true): array
    {
        return compact('check', 'record', 'blocking');
    }

    private function report(string $kind, int $checked, array $violations): array
    {
        return ['kind' => $kind, 'checked' => $checked, 'ok' => ! collect($violations)->contains('blocking', true), 'violations' => $violations, 'generated_at' => now('UTC')->toIso8601String(), 'read_only' => true];
    }
}
