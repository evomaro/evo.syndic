<?php

namespace App\Services;

use App\Models\ComplianceSource;
use App\Models\ComplianceTemplate;
use App\Models\ComplianceTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComplianceTemplateWorkflow
{
    private const VERIFIED_CONFIDENCE = ['source_verified', 'professionally_reviewed', 'counsel_reviewed'];

    public function verifySource(ComplianceSource $source, User $actor): ComplianceSource
    {
        if ((! $source->official_url && ! $source->document_reference) || ! $source->published_on || ! $source->effective_on) {
            throw ValidationException::withMessages(['source' => __('Une référence officielle et ses dates sont requises.')]);
        }

        $source->update([
            'confidence' => 'source_verified',
            'last_verified_on' => today(),
            'verified_by' => $actor->id,
        ]);
        activity()->performedOn($source)->causedBy($actor)->withProperties(['confidence' => 'source_verified'])->log('compliance.source_verified');

        return $source->fresh();
    }

    public function approve(ComplianceTemplateVersion $version, User $actor): ComplianceTemplateVersion
    {
        $version->load(['source', 'template']);
        $errors = [];
        if (! $version->source || $version->source->organization_id !== $version->template->organization_id
            || $version->source->authority_id !== $version->template->authority_id
            || ! in_array($version->source->confidence, self::VERIFIED_CONFIDENCE, true) || ! $version->source->verified_by) {
            $errors['source'] = __('Une source officielle vérifiée est requise.');
        }
        if ($version->professional_review_status !== 'approved') {
            $errors['professional_review_status'] = __('Une revue professionnelle explicite est requise.');
        }
        if ($version->counsel_review_status === 'required') {
            $errors['counsel_review_status'] = __('La revue du conseil est requise.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $version->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now('UTC')]);
        activity()->performedOn($version)->causedBy($actor)->withProperties(['template_id' => $version->template_id, 'version' => $version->version])->log('compliance.template_version_approved');

        return $version->fresh();
    }

    public function professionalReview(ComplianceTemplateVersion $version, User $actor): ComplianceTemplateVersion
    {
        if (! in_array($version->status, ['draft', 'source_review', 'professional_review_required', 'ready_for_approval'], true)) {
            throw ValidationException::withMessages(['status' => __('Cette version ne peut plus recevoir de revue professionnelle.')]);
        }
        $version->update([
            'professional_review_status' => 'approved', 'professional_reviewed_by' => $actor->id,
            'professional_reviewed_at' => now('UTC'), 'status' => 'ready_for_approval',
        ]);
        activity()->performedOn($version)->causedBy($actor)->withProperties(['template_id' => $version->template_id, 'version' => $version->version])->log('compliance.template_version_professionally_reviewed');

        return $version->fresh();
    }

    public function activate(ComplianceTemplateVersion $version, User $actor): ComplianceTemplateVersion
    {
        return DB::transaction(function () use ($version, $actor) {
            ComplianceTemplate::query()->whereKey($version->template_id)->lockForUpdate()->firstOrFail();
            $version = ComplianceTemplateVersion::query()->whereKey($version->id)->with(['source', 'template'])->lockForUpdate()->firstOrFail();
            if ($version->status !== 'approved' || ! $version->approved_by || ! $version->source || ! in_array($version->source->confidence, self::VERIFIED_CONFIDENCE, true)) {
                throw ValidationException::withMessages(['status' => __('La version doit être approuvée et liée à une source vérifiée.')]);
            }
            if ($version->source->organization_id !== $version->template->organization_id || $version->source->authority_id !== $version->template->authority_id) {
                throw ValidationException::withMessages(['source' => __('La source et le modèle ne partagent pas le même périmètre et la même autorité.')]);
            }
            if ((int) $version->approved_by === (int) $actor->id) {
                throw ValidationException::withMessages(['actor' => __('L’approbation et l’activation doivent être séparées.')]);
            }
            $active = ComplianceTemplateVersion::query()->where('template_id', $version->template_id)->where('status', 'active')->whereKeyNot($version->id)->lockForUpdate()->get();
            if ($version->supersedes_id && $active->contains('id', $version->supersedes_id)) {
                $predecessor = $active->firstWhere('id', $version->supersedes_id);
                if (! $version->effective_from || ! $predecessor->effective_from || $version->effective_from->lte($predecessor->effective_from)) {
                    throw ValidationException::withMessages(['effective_from' => __('La nouvelle version doit commencer après la version remplacée.')]);
                }
                ComplianceTemplateVersion::query()->whereKey($predecessor->id)->update([
                    'status' => 'superseded', 'effective_until' => $version->effective_from->subDay()->toDateString(), 'updated_at' => now('UTC'),
                ]);
            }
            $overlap = ComplianceTemplateVersion::query()->where('template_id', $version->template_id)->where('status', 'active')->whereKeyNot($version->id)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $version->effective_from))
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $version->effective_until ?? '9999-12-31'))
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['effective_from' => __('Une version active couvre déjà cette période.')]);
            }
            $version->update(['status' => 'active', 'activated_by' => $actor->id, 'activated_at' => now('UTC')]);
            activity()->performedOn($version)->causedBy($actor)->withProperties(['template_id' => $version->template_id, 'version' => $version->version])->log('compliance.template_version_activated');

            return $version->fresh();
        }, 3);
    }

    public function createAmendment(ComplianceTemplateVersion $active, User $actor): ComplianceTemplateVersion
    {
        return DB::transaction(function () use ($active, $actor) {
            ComplianceTemplate::query()->whereKey($active->template_id)->lockForUpdate()->firstOrFail();
            $active = ComplianceTemplateVersion::query()->lockForUpdate()->findOrFail($active->id);
            if ($active->status !== 'active') {
                throw ValidationException::withMessages(['status' => __('Seule une version active peut être amendée.')]);
            }
            $copy = $active->replicate(['status', 'source_verified_by', 'source_verified_at', 'approved_by', 'approved_at', 'activated_by', 'activated_at']);
            $copy->version = (int) ComplianceTemplateVersion::where('template_id', $active->template_id)->max('version') + 1;
            $copy->status = 'draft';
            $copy->supersedes_id = $active->id;
            $copy->professional_review_status = 'pending';
            $copy->professional_reviewed_by = null;
            $copy->professional_reviewed_at = null;
            $copy->save();
            activity()->performedOn($copy)->causedBy($actor)->withProperties(['supersedes_id' => $active->id])->log('compliance.template_amendment_created');

            return $copy;
        }, 3);
    }

    public function withdraw(ComplianceTemplateVersion $version, User $actor, string $reason): ComplianceTemplateVersion
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Un motif documenté est obligatoire.')]);
        }

        return DB::transaction(function () use ($version, $actor, $reason) {
            ComplianceTemplate::query()->whereKey($version->template_id)->lockForUpdate()->firstOrFail();
            $version = ComplianceTemplateVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($version->status === 'withdrawn') {
                return $version;
            }
            if ($version->status !== 'active') {
                throw ValidationException::withMessages(['status' => __('Seule une version active peut être retirée.')]);
            }
            DB::table('compliance_template_versions')->where('id', $version->id)->update([
                'status' => 'withdrawn',
                'effective_until' => today()->toDateString(),
                'withdrawal_reason' => trim($reason),
                'withdrawn_by' => $actor->id,
                'withdrawn_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            activity()->performedOn($version)->causedBy($actor)->withProperties([
                'template_id' => $version->template_id,
                'version' => $version->version,
                'reason' => trim($reason),
            ])->log('compliance.template_version_withdrawn');

            return $version->fresh();
        }, 3);
    }
}
