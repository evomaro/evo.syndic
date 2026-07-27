<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyMinutes;
use App\Models\AssemblyMinuteVersion;
use App\Models\User;
use App\Support\ArabicPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MinutesService
{
    public function prepare(Assembly $assembly, array $notes, User $actor): AssemblyMinuteVersion
    {
        return DB::transaction(function () use ($assembly, $notes, $actor) {
            $assembly = Assembly::query()->whereKey($assembly->id)->with(['residence', 'resolutions.finalResult', 'attendanceRecords.electorate', 'quorumSnapshots', 'chairpersonContact', 'secretaryUser'])->lockForUpdate()->firstOrFail();
            if ($assembly->status !== 'deliberations_completed' || $assembly->resolutions->isEmpty() || $assembly->resolutions->contains(fn ($r) => ! $r->finalResult) || ! $assembly->quorumSnapshots->last() || ! $assembly->chairpersonContact || ! $assembly->secretaryUser) {
                throw ValidationException::withMessages(['minutes' => __('Quorum, résultats finalisés, président et secrétaire sont obligatoires.')]);
            }
            $minutes = AssemblyMinutes::firstOrCreate(['assembly_id' => $assembly->id], ['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'status' => 'draft'] + collect($notes)->only(['reservations_fr', 'reservations_ar', 'incidents_fr', 'incidents_ar'])->all());
            if ($minutes->status === 'signed') {
                throw ValidationException::withMessages(['minutes' => __('Le procès-verbal signé est immuable.')]);
            }
            $payload = $this->payload($assembly, $minutes);
            $payloadChecksum = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $version = (int) $minutes->versions()->max('version') + 1;
            $html = view('pdf.governance-minutes', compact('payload'))->render();
            $pdf = Pdf::loadHTML(ArabicPdf::shapeHtml($html, 'ar'))->setPaper('a4')->output();
            $path = "governance/{$assembly->residence_id}/{$assembly->id}/minutes-v{$version}.pdf";
            Storage::disk('local')->put($path, $pdf);
            $model = $minutes->versions()->create(['version' => $version, 'status' => 'draft', 'path' => $path, 'checksum' => hash('sha256', $pdf), 'frozen_payload' => $payload, 'payload_checksum' => $payloadChecksum, 'created_by' => $actor->id]);
            $storedPayload = $model->fresh()->frozen_payload;
            $storedChecksum = hash('sha256', json_encode($storedPayload, JSON_THROW_ON_ERROR));
            if (! hash_equals($model->payload_checksum, $storedChecksum)) {
                $model->update(['payload_checksum' => $storedChecksum]);
            }
            activity()->performedOn($minutes)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'version' => $version, 'checksum' => $model->checksum])->log('governance.minutes_prepared');

            return $model;
        });
    }

    public function review(AssemblyMinuteVersion $version, User $actor): AssemblyMinutes
    {
        return DB::transaction(function () use ($version, $actor) {
            $version = AssemblyMinuteVersion::query()->whereKey($version->id)->with('minutes.assembly')->lockForUpdate()->firstOrFail();
            $minutes = $version->minutes;
            if ($minutes->status === 'signed') {
                throw ValidationException::withMessages(['minutes' => __('Le procès-verbal signé est immuable.')]);
            }$version->update(['status' => 'reviewed']);
            $minutes->update(['status' => 'reviewed', 'reviewed_version_id' => $version->id, 'reviewed_at' => now('UTC'), 'reviewed_by' => $actor->id]);
            activity()->performedOn($minutes)->causedBy($actor)->withProperties(['organization_id' => $minutes->organization_id, 'residence_id' => $minutes->residence_id, 'version' => $version->version])->log('governance.minutes_reviewed');

            return $minutes->fresh();
        });
    }

    public function sign(AssemblyMinutes $minutes, array $signatures, User $actor): AssemblyMinuteVersion
    {
        return DB::transaction(function () use ($minutes, $signatures, $actor) {
            $minutes = AssemblyMinutes::query()->whereKey($minutes->id)->with(['assembly.resolutions.finalResult', 'reviewedVersion'])->lockForUpdate()->firstOrFail();
            if ($minutes->status === 'signed') {
                return $minutes->signedVersion;
            }if ($minutes->status !== 'reviewed' || ! $minutes->reviewedVersion || $minutes->assembly->resolutions->contains(fn ($r) => ! $r->finalResult) || empty($signatures['chairperson']) || empty($signatures['secretary']) || empty($signatures['method'])) {
                throw ValidationException::withMessages(['signatures' => __('La version relue, tous les résultats et les signatures requises sont obligatoires.')]);
            }$currentChecksum = hash('sha256', json_encode($minutes->reviewedVersion->frozen_payload, JSON_THROW_ON_ERROR));
            if (! hash_equals($minutes->reviewedVersion->payload_checksum, $currentChecksum)) {
                throw ValidationException::withMessages(['minutes' => __('Le payload figé diffère de la version relue.')]);
            }$minutes->reviewedVersion->update(['status' => 'signed', 'signed_at' => now('UTC'), 'signatures' => ['chairperson' => $signatures['chairperson'], 'secretary' => $signatures['secretary'], 'method' => $signatures['method'], 'signed_at' => now('UTC')->toIso8601String()]]);
            $minutes->update(['status' => 'signed', 'signed_version_id' => $minutes->reviewedVersion->id, 'signed_at' => now('UTC'), 'signed_by' => $actor->id]);
            activity()->performedOn($minutes)->causedBy($actor)->withProperties(['organization_id' => $minutes->organization_id, 'residence_id' => $minutes->residence_id, 'version' => $minutes->reviewedVersion->version, 'checksum' => $minutes->reviewedVersion->checksum])->log('governance.minutes_signed');

            return $minutes->reviewedVersion->fresh();
        });
    }

    public function correctiveAnnex(AssemblyMinutes $minutes, string $reason, string $textFr, ?string $textAr, User $actor): AssemblyMinuteVersion
    {
        return DB::transaction(function () use ($minutes, $reason, $textFr, $textAr, $actor) {
            $minutes = AssemblyMinutes::query()->whereKey($minutes->id)->with('signedVersion')->lockForUpdate()->firstOrFail();
            if ($minutes->status !== 'signed' || mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['annex' => __('Un procès-verbal signé et un motif détaillé sont requis.')]);
            }$payload = ['parent_checksum' => $minutes->signedVersion->checksum, 'reason' => trim($reason), 'text_fr' => $textFr, 'text_ar' => $textAr, 'created_at' => now('UTC')->toIso8601String()];
            $html = '<html><meta charset="utf-8"><style>body{font-family:DejaVu Sans;padding:30px}.ar{direction:rtl;text-align:right}</style><body><h1>Annexe corrective / ملحق تصحيحي</h1><p>'.e($textFr).'</p><p class="ar">'.e($textAr).'</p><small>Original SHA-256: '.e($minutes->signedVersion->checksum).'</small></body></html>';
            $pdf = Pdf::loadHTML(ArabicPdf::shapeHtml($html, 'ar'))->output();
            $version = (int) $minutes->versions()->max('version') + 1;
            $path = "governance/{$minutes->residence_id}/{$minutes->assembly_id}/minutes-annex-v{$version}.pdf";
            Storage::disk('local')->put($path, $pdf);
            $annex = $minutes->versions()->create(['version' => $version, 'kind' => 'corrective_annex', 'status' => 'signed', 'path' => $path, 'checksum' => hash('sha256', $pdf), 'frozen_payload' => $payload, 'payload_checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'created_by' => $actor->id, 'parent_version_id' => $minutes->signed_version_id, 'correction_reason' => trim($reason), 'signed_at' => now('UTC'), 'signatures' => ['actor_id' => $actor->id, 'method' => 'recorded_annex']]);
            activity()->performedOn($minutes)->causedBy($actor)->withProperties(['organization_id' => $minutes->organization_id, 'residence_id' => $minutes->residence_id, 'annex_version' => $version, 'reason' => trim($reason)])->log('governance.minutes_corrective_annex');

            return $annex;
        });
    }

    public function download(AssemblyMinuteVersion $version)
    {
        $disk = Storage::disk($version->disk);
        abort_unless($disk->exists($version->path) && hash_equals($version->checksum, hash('sha256', $disk->get($version->path))), 409);

        return $disk->download($version->path, 'proces-verbal-'.$version->version.'.pdf', ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function payload(Assembly $a, AssemblyMinutes $m): array
    {
        $q = $a->quorumSnapshots->last();

        return ['assembly' => ['id' => $a->id, 'reference' => $a->reference, 'meeting_date' => $a->meeting_date->toDateString(), 'opened_at' => $a->opened_at?->toIso8601String(), 'closed_at' => $a->closed_at?->toIso8601String(), 'chairperson' => $a->chairpersonContact->display_name, 'secretary' => $a->secretaryUser->name], 'residence' => ['id' => $a->residence_id, 'name' => $a->residence->name], 'quorum' => $q->only(['eligible_headcount', 'present_or_represented_headcount', 'eligible_weight_numerator', 'represented_weight_numerator', 'quorum_met', 'checksum']), 'attendance' => $a->attendanceRecords->map(fn ($r) => ['electorate_id' => $r->electorate_id, 'name' => $r->electorate->contact_name_snapshot, 'status' => $r->status, 'weight' => (int) $r->active_weight_numerator])->all(), 'resolutions' => $a->resolutions->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'text_fr' => $r->final_text_fr, 'text_ar' => $r->final_text_ar, 'for' => (int) $r->finalResult->for_weight, 'against' => (int) $r->finalResult->against_weight, 'abstention' => (int) $r->finalResult->abstention_weight, 'adopted' => $r->finalResult->adopted, 'rule_identifier' => $r->finalResult->rule_identifier, 'rule_version' => $r->finalResult->rule_version, 'comparison' => $r->finalResult->comparison, 'threshold_numerator' => (int) $r->finalResult->threshold_numerator, 'threshold_denominator' => (int) $r->finalResult->threshold_denominator, 'checksum' => $r->finalResult->checksum])->all(), 'reservations_fr' => $m->reservations_fr, 'reservations_ar' => $m->reservations_ar, 'incidents_fr' => $m->incidents_fr, 'incidents_ar' => $m->incidents_ar];
    }
}
