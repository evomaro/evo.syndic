<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyElectorate;
use App\Models\AssemblyEligibilitySnapshot;
use App\Models\GovernanceDocumentVersion;
use App\Models\GovernanceVotingShareSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhaseSevenEligibilityService
{
    public function generate(Assembly $assembly, ?GovernanceVotingShareSource $shareSource, User $actor): AssemblyEligibilitySnapshot
    {
        return DB::transaction(function () use ($assembly, $shareSource, $actor) {
            $assembly = Assembly::query()->whereKey($assembly->id)->lockForUpdate()->firstOrFail();
            if ($shareSource && ($shareSource->organization_id !== $assembly->organization_id || $shareSource->residence_id !== $assembly->residence_id)) {
                abort(404);
            }
            $eligibilityOn = $assembly->eligibility_on ?: $assembly->meeting_date;
            $rows = DB::table('lot_ownerships')
                ->join('lots', 'lots.id', '=', 'lot_ownerships.lot_id')
                ->join('contacts', 'contacts.id', '=', 'lot_ownerships.contact_id')
                ->where('lots.residence_id', $assembly->residence_id)
                ->whereDate('lot_ownerships.starts_on', '<=', $eligibilityOn)
                ->where(fn ($q) => $q->whereNull('lot_ownerships.ends_on')->orWhereDate('lot_ownerships.ends_on', '>=', $eligibilityOn))
                ->orderBy('lots.id')->orderBy('lot_ownerships.contact_id')->orderBy('lot_ownerships.id')
                ->get([
                    'lot_ownerships.id', 'lot_ownerships.lot_id', 'lot_ownerships.contact_id',
                    'lot_ownerships.ownership_percentage', 'lot_ownerships.starts_on', 'lot_ownerships.ends_on',
                    'lot_ownerships.updated_at', 'lots.reference', 'contacts.type', 'contacts.first_name',
                    'contacts.last_name', 'contacts.company_name', 'contacts.primary_email',
                    'contacts.primary_phone', 'contacts.address', 'contacts.preferred_language',
                ]);
            $findings = [];
            if ($rows->isEmpty()) {
                $findings[] = ['code' => 'missing_historical_ownership', 'blocking' => true];
            }
            foreach ($rows->groupBy('lot_id') as $lotRows) {
                $total = $lotRows->sum(fn ($row) => (float) $row->ownership_percentage);
                if ($total > 100.0001) {
                    $findings[] = ['code' => 'ownership_total_exceeds_100', 'lot_id' => (int) $lotRows->first()->lot_id, 'total' => $total, 'blocking' => true];
                }
                if ($lotRows->duplicates(fn ($row) => $row->contact_id)->isNotEmpty()) {
                    $findings[] = ['code' => 'duplicate_active_ownership_interest', 'lot_id' => (int) $lotRows->first()->lot_id, 'blocking' => true];
                }
            }
            if (! $shareSource) {
                $findings[] = ['code' => 'voting_share_source_missing', 'blocking' => true];
            } elseif ($shareSource->status !== 'approved') {
                $findings[] = ['code' => 'voting_share_source_unverified', 'source_id' => $shareSource->id, 'blocking' => true];
            }
            if ($shareSource && (int) $shareSource->denominator <= 0) {
                $findings[] = ['code' => 'invalid_voting_share_denominator', 'blocking' => true];
            }
            $denominator = $shareSource && (int) $shareSource->denominator > 0
                ? (int) $shareSource->denominator
                : (10 ** ($shareSource?->decimal_precision ?? 4));
            $shares = $shareSource?->configuration['shares'] ?? [];
            $weightedRows = $rows->map(function ($row) use ($shareSource, $shares, $denominator, &$findings) {
                $weight = 0;
                if ($shareSource?->status === 'approved') {
                    $rawShare = $shares[(string) $row->reference] ?? null;
                    if ($rawShare === null || ! is_numeric($rawShare)) {
                        $findings[] = ['code' => 'voting_share_missing', 'lot_id' => (int) $row->lot_id, 'blocking' => true];
                    } elseif ((float) $rawShare < 0) {
                        $findings[] = ['code' => 'negative_voting_share', 'lot_id' => (int) $row->lot_id, 'blocking' => true];
                    } else {
                        $base = $this->decimalInteger((string) $rawShare, (int) $shareSource->decimal_precision);
                        $percentage = $this->decimalInteger((string) $row->ownership_percentage, 4);
                        $product = $base * $percentage;
                        if ($product % 1_000_000 !== 0) {
                            $findings[] = ['code' => 'voting_share_rounding_required', 'lot_id' => (int) $row->lot_id, 'blocking' => true];
                        } else {
                            $weight = intdiv($product, 1_000_000);
                        }
                    }
                }
                $row->calculated_weight = $weight;
                $row->weight_denominator = $denominator;

                return $row;
            });
            if ($shareSource?->status === 'approved' && $shareSource->expected_total !== null) {
                $expected = $this->decimalInteger((string) $shareSource->expected_total, (int) $shareSource->decimal_precision);
                $actual = (int) $weightedRows->sum('calculated_weight');
                if ($actual !== $expected) {
                    $findings[] = ['code' => 'voting_share_total_mismatch', 'expected' => $expected, 'actual' => $actual, 'blocking' => true];
                }
            }
            $fingerprintPayload = $rows->map(fn ($row) => [
                'ownership_id' => (int) $row->id, 'lot_id' => (int) $row->lot_id,
                'contact_id' => (int) $row->contact_id, 'percentage' => (string) $row->ownership_percentage,
                'starts_on' => $row->starts_on, 'ends_on' => $row->ends_on,
            ])->all();
            $fingerprint = hash('sha256', json_encode([
                'eligibility_on' => (string) $eligibilityOn,
                'share_source' => $shareSource?->only(['id', 'code', 'version', 'status', 'decimal_precision', 'expected_total', 'denominator', 'configuration']),
                'ownerships' => $fingerprintPayload,
            ], JSON_THROW_ON_ERROR));
            $version = (int) $assembly->eligibilitySnapshots()->max('version') + 1;
            $snapshot = $assembly->eligibilitySnapshots()->create([
                'organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id,
                'voting_share_source_id' => $shareSource?->id, 'version' => $version, 'eligibility_on' => $eligibilityOn,
                'status' => collect($findings)->contains('blocking', true) ? 'indeterminate' : 'ready_for_review',
                'input_fingerprint' => $fingerprint, 'ownership_boundary_at' => $rows->max('updated_at'),
                'findings' => $findings, 'generated_by' => $actor->id, 'generated_at' => now('UTC'),
            ]);
            if ($assembly->electorate()->exists()) {
                throw ValidationException::withMessages(['snapshot' => __('Un corps électoral existe déjà; une nouvelle préparation doit utiliser un successeur explicite.')]);
            }
            foreach ($weightedRows->groupBy('contact_id') as $contactRows) {
                $first = $contactRows->first();
                $name = $first->type === 'company' ? $first->company_name : trim($first->first_name.' '.$first->last_name);
                $weight = (int) $contactRows->sum('calculated_weight');
                AssemblyElectorate::create([
                    'organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id,
                    'assembly_id' => $assembly->id, 'contact_id' => $first->contact_id,
                    'eligibility_snapshot_id' => $snapshot->id, 'entitlement_key' => 'contact:'.$first->contact_id,
                    'lot_ids' => $contactRows->pluck('lot_id')->map(fn ($id) => (int) $id)->all(),
                    'ownership_fractions' => $contactRows->map(fn ($item) => [
                        'lot_id' => (int) $item->lot_id, 'reference' => (string) $item->reference,
                        'percentage' => (string) $item->ownership_percentage,
                    ])->all(),
                    'voting_weight_numerator' => $weight, 'voting_weight_denominator' => $denominator,
                    'original_weight_numerator' => $weight,
                    'contact_name_snapshot' => $name, 'email_snapshot' => $first->primary_email,
                    'phone_snapshot' => $first->primary_phone, 'address_snapshot' => $first->address,
                    'preferred_language' => in_array($first->preferred_language, ['fr', 'ar'], true) ? $first->preferred_language : 'fr',
                    'source_ownership_ids' => $contactRows->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'generated_after_cutoff' => now()->greaterThan($assembly->convocation_deadline_at),
                    'snapshotted_at' => now('UTC'), 'share_source_code' => $shareSource?->code,
                    'share_source_version' => $shareSource?->version,
                    'eligibility_status' => collect($findings)->contains('blocking', true) ? 'indeterminate' : 'eligible',
                    'inclusion_explanation' => $shareSource
                        ? 'Historical ownership multiplied by the explicitly selected governance share source.'
                        : 'Historical ownership interest preserved without inferred voting weight; authoritative share source missing.',
                ]);
            }
            $snapshot->update([
                'interest_count' => $assembly->electorate()->count(),
                'eligible_weight_numerator' => (int) $assembly->electorate()->where('eligibility_status', 'eligible')->sum('voting_weight_numerator'),
                'weight_denominator' => (int) ($assembly->electorate()->value('voting_weight_denominator') ?: 1),
            ]);
            $assembly->update(['eligibility_on' => $eligibilityOn, 'eligibility_snapshot_id' => $snapshot->id, 'legal_verification_status' => 'unverified']);
            activity()->performedOn($assembly)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'eligibility_snapshot_id' => $snapshot->id, 'status' => $snapshot->status, 'finding_count' => count($findings)])->log('governance.eligibility_snapshotted');

            return $snapshot->fresh('interests');
        });
    }

    public function review(AssemblyEligibilitySnapshot $snapshot, User $actor): AssemblyEligibilitySnapshot
    {
        return DB::transaction(function () use ($snapshot, $actor) {
            $snapshot = AssemblyEligibilitySnapshot::query()->whereKey($snapshot->id)->with('assembly')->lockForUpdate()->firstOrFail();
            if ($snapshot->status !== 'ready_for_review' || $snapshot->stale_at || collect($snapshot->findings)->contains('blocking', true)) {
                throw ValidationException::withMessages(['snapshot' => __('Le snapshot est incomplet, bloqué ou périmé.')]);
            }
            $snapshot->update(['status' => 'reviewed', 'reviewed_by' => $actor->id, 'reviewed_at' => now('UTC')]);
            activity()->performedOn($snapshot->assembly)->causedBy($actor)->withProperties(['organization_id' => $snapshot->organization_id, 'residence_id' => $snapshot->residence_id, 'eligibility_snapshot_id' => $snapshot->id])->log('governance.eligibility_reviewed');

            return $snapshot->fresh();
        });
    }

    public function overrideInterest(AssemblyElectorate $interest, array $changes, string $reason, GovernanceDocumentVersion $evidence, User $actor): AssemblyElectorate
    {
        return DB::transaction(function () use ($interest, $changes, $reason, $evidence, $actor) {
            $interest = AssemblyElectorate::query()->whereKey($interest->id)->with('assembly')->lockForUpdate()->firstOrFail();
            abort_unless(
                $evidence->document->organization_id === $interest->organization_id
                && $evidence->document->residence_id === $interest->residence_id
                && $evidence->document->assembly_id === $interest->assembly_id,
                404,
            );
            if ($interest->assembly->finalized_at || $interest->ballots()->whereNotNull('finalized_at')->exists() || mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['override' => __('Un intérêt finalisé est immuable et toute dérogation exige un motif détaillé.')]);
            }
            $allowed = collect($changes)->only(['eligibility_status', 'restriction_reason', 'voting_weight_numerator'])->all();
            if ($allowed === [] || (isset($allowed['voting_weight_numerator']) && (int) $allowed['voting_weight_numerator'] < 0)) {
                throw ValidationException::withMessages(['override' => __('Une modification explicite et non négative est obligatoire.')]);
            }
            $before = $interest->only(array_keys($allowed));
            $interest->update($allowed + ['snapshot_version' => $interest->snapshot_version + 1]);
            $interest->corrections()->create([
                'actor_id' => $actor->id, 'before_payload' => $before,
                'after_payload' => $interest->fresh()->only(array_keys($allowed)),
                'reason' => trim($reason), 'corrected_at' => now('UTC'),
                'correction_type' => 'evidenced_eligibility_override', 'evidence_version_id' => $evidence->id,
            ]);
            $interest->assembly->eligibilitySnapshot?->update([
                'status' => 'stale', 'stale_at' => now('UTC'),
                'stale_reason' => 'An evidenced manual eligibility override requires renewed preparation review.',
            ]);
            activity()->performedOn($interest)->causedBy($actor)->withProperties([
                'organization_id' => $interest->organization_id, 'residence_id' => $interest->residence_id,
                'evidence_version_id' => $evidence->id, 'reason' => trim($reason),
            ])->log('governance.eligibility_overridden');

            return $interest->fresh();
        });
    }

    public function refreshStaleness(AssemblyEligibilitySnapshot $snapshot): bool
    {
        $changed = DB::table('lot_ownerships')->join('lots', 'lots.id', '=', 'lot_ownerships.lot_id')
            ->where('lots.residence_id', $snapshot->residence_id)
            ->when($snapshot->ownership_boundary_at, fn ($q) => $q->where('lot_ownerships.updated_at', '>', $snapshot->ownership_boundary_at))
            ->exists();
        if ($changed && ! $snapshot->stale_at) {
            $snapshot->update(['status' => 'stale', 'stale_at' => now('UTC'), 'stale_reason' => 'Ownership history changed after snapshot generation.']);
        }

        return $changed;
    }

    private function decimalInteger(string $value, int $scale): int
    {
        $value = trim($value);
        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return -1;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * (10 ** $scale)) + (int) str_pad(substr($fraction, 0, $scale), $scale, '0');
    }
}
