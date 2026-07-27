<?php

namespace App\Services;

use App\Models\GovernanceRuleSource;
use App\Models\GovernanceRuleVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GovernanceRuleWorkflow
{
    public function verifySource(GovernanceRuleSource $source, User $actor): GovernanceRuleSource
    {
        return DB::transaction(function () use ($source, $actor) {
            $source = GovernanceRuleSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if (! $source->official_url && ! $source->document_reference) {
                throw ValidationException::withMessages(['source' => __('Une URL officielle ou une référence documentaire est obligatoire.')]);
            }
            $source->update(['confidence' => 'source_verified', 'last_verified_on' => today(), 'verified_by' => $actor->id]);
            activity()->performedOn($source)->causedBy($actor)->withProperties(['organization_id' => $source->organization_id, 'version' => $source->version])->log('governance.rule_source_verified');

            return $source->fresh();
        });
    }

    public function review(GovernanceRuleVersion $version, User $actor, bool $counsel = false): GovernanceRuleVersion
    {
        return DB::transaction(function () use ($version, $actor, $counsel) {
            $version = GovernanceRuleVersion::query()->whereKey($version->id)->with('source')->lockForUpdate()->firstOrFail();
            $this->assertMutable($version);
            if (! $version->source || $version->source->confidence !== 'source_verified') {
                throw ValidationException::withMessages(['source' => __('La source officielle doit être vérifiée avant l’interprétation professionnelle.')]);
            }
            $changes = $counsel
                ? ['status' => 'counsel_reviewed', 'confidence' => 'counsel_reviewed', 'counsel_reviewed_by' => $actor->id, 'counsel_reviewed_at' => now('UTC')]
                : ['status' => 'professionally_reviewed', 'confidence' => 'professionally_reviewed', 'professionally_reviewed_by' => $actor->id, 'professionally_reviewed_at' => now('UTC')];
            $version->update($changes);
            activity()->performedOn($version)->causedBy($actor)->withProperties(['organization_id' => $version->rule?->organization_id, 'review' => $counsel ? 'counsel' : 'professional'])->log('governance.rule_version_reviewed');

            return $version->fresh();
        });
    }

    public function approve(GovernanceRuleVersion $version, User $actor): GovernanceRuleVersion
    {
        return DB::transaction(function () use ($version, $actor) {
            $version = GovernanceRuleVersion::query()->whereKey($version->id)->with('source')->lockForUpdate()->firstOrFail();
            $this->assertMutable($version);
            if (! in_array($version->status, ['professionally_reviewed', 'counsel_reviewed'], true) || ! $version->source || $version->source->confidence !== 'source_verified') {
                throw ValidationException::withMessages(['approval' => __('Une source vérifiée et une revue professionnelle sont obligatoires.')]);
            }
            if ((int) $version->source->verified_by === (int) $actor->id) {
                throw ValidationException::withMessages(['approval' => __('Le vérificateur de source ne peut pas approuver la même version.')]);
            }
            $version->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now('UTC')]);
            activity()->performedOn($version)->causedBy($actor)->withProperties(['organization_id' => $version->rule?->organization_id])->log('governance.rule_version_approved');

            return $version->fresh();
        });
    }

    public function activate(GovernanceRuleVersion $version, User $actor): GovernanceRuleVersion
    {
        return DB::transaction(function () use ($version, $actor) {
            $version = GovernanceRuleVersion::query()->whereKey($version->id)->with(['source', 'rule'])->lockForUpdate()->firstOrFail();
            if ($version->status !== 'approved' || ! $version->approved_at || ! $version->source || $version->source->confidence !== 'source_verified') {
                throw ValidationException::withMessages(['activation' => __('Seule une version approuvée avec source vérifiée peut être activée.')]);
            }
            if ((int) $version->approved_by === (int) $actor->id) {
                throw ValidationException::withMessages(['activation' => __('L’approbateur et l’activateur doivent être distincts.')]);
            }
            $overlap = GovernanceRuleVersion::query()
                ->where('governance_rule_id', $version->governance_rule_id)
                ->whereKeyNot($version->id)
                ->when($version->supersedes_version_id, fn ($query, $id) => $query->whereKeyNot($id))
                ->where('status', 'active')
                ->whereDate('effective_from', '<=', $version->effective_until ?: '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $version->effective_from))
                ->lockForUpdate()->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['effective_from' => __('Une version active chevauche cette période.')]);
            }
            if ($version->supersedes_version_id) {
                GovernanceRuleVersion::whereKey($version->supersedes_version_id)->where('status', 'active')->update(['status' => 'superseded', 'confidence' => 'superseded', 'active' => false]);
            }
            $version->update(['status' => 'active', 'active' => true, 'activated_by' => $actor->id, 'activated_at' => now('UTC'), 'immutable_at' => now('UTC')]);
            activity()->performedOn($version)->causedBy($actor)->withProperties(['organization_id' => $version->rule?->organization_id])->log('governance.rule_version_activated');

            return $version->fresh();
        });
    }

    public function amend(GovernanceRuleVersion $active, User $actor): GovernanceRuleVersion
    {
        return DB::transaction(function () use ($active, $actor) {
            $active = GovernanceRuleVersion::query()->whereKey($active->id)->lockForUpdate()->firstOrFail();
            if ($active->status !== 'active') {
                throw ValidationException::withMessages(['version' => __('Seule une version active peut être amendée.')]);
            }
            $copy = $active->replicate([
                'status', 'confidence', 'active', 'source_verified_by', 'source_verified_at',
                'professionally_reviewed_by', 'professionally_reviewed_at', 'counsel_reviewed_by',
                'counsel_reviewed_at', 'approved_by', 'approved_at', 'activated_by', 'activated_at',
                'immutable_at',
            ]);
            $copy->version = (int) GovernanceRuleVersion::where('governance_rule_id', $active->governance_rule_id)->max('version') + 1;
            $copy->status = 'unverified_draft';
            $copy->confidence = 'unverified_draft';
            $copy->active = false;
            $copy->supersedes_version_id = $active->id;
            $copy->save();
            activity()->performedOn($copy)->causedBy($actor)->withProperties(['organization_id' => $active->rule?->organization_id, 'supersedes_version_id' => $active->id])->log('governance.rule_version_amended');

            return $copy;
        });
    }

    private function assertMutable(GovernanceRuleVersion $version): void
    {
        if ($version->immutable_at || in_array($version->status, ['active', 'superseded', 'withdrawn'], true)) {
            throw ValidationException::withMessages(['version' => __('Une version active ou historique est immuable.')]);
        }
    }
}
