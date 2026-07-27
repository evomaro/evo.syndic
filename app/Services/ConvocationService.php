<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\Convocation;
use App\Models\ConvocationRecipient;
use App\Models\User;
use App\Support\ArabicPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ConvocationService
{
    public function __construct(private AssemblyWorkflow $workflow) {}

    public function issue(Assembly $assembly, User $actor, bool $lateException = false, ?string $reason = null): Convocation
    {
        return DB::transaction(function () use ($assembly, $actor, $lateException, $reason) {
            $assembly = Assembly::query()->whereKey($assembly->id)->with(['residence', 'mandate', 'agendaItems.resolution.ruleSnapshot', 'electorate'])->lockForUpdate()->firstOrFail();
            if ($existing = $assembly->convocations()->where('version', 1)->first()) {
                return $existing;
            }
            if ($assembly->status !== 'preparing' || $assembly->electorate->isEmpty() || $assembly->agendaItems->where('status', 'frozen')->isEmpty()) {
                throw ValidationException::withMessages(['convocation' => __('Le corps électoral et l’ordre du jour figés sont requis.')]);
            }
            if (! $assembly->mandate || $assembly->mandate->status !== 'active' || $assembly->mandate->starts_on->gt(today()) || $assembly->mandate->ends_on->lt($assembly->meeting_date)) {
                throw ValidationException::withMessages(['mandate' => __('Un mandat de syndic actif couvrant la réunion est obligatoire.')]);
            }
            $latestOnTime = $assembly->meeting_date->copy()->startOfDay()->subDays(config('governance.notice_days'));
            if (now()->gt($latestOnTime) && (! $lateException || ! $actor->canInOrganization('override_late_convocation', $assembly->organization) || mb_strlen(trim((string) $reason)) < 15)) {
                throw ValidationException::withMessages(['deadline' => __('Le délai légal de quinze jours n’est pas respecté; une autorisation spéciale et un motif détaillé sont requis.')]);
            }
            $payload = $this->payload($assembly);
            $html = view('pdf.governance-convocation', compact('payload'))->render();
            $pdf = Pdf::loadHTML(ArabicPdf::shapeHtml($html, 'ar'))->setPaper('a4')->output();
            $path = "governance/{$assembly->residence_id}/{$assembly->id}/convocation-v1.pdf";
            Storage::disk('local')->put($path, $pdf);
            $convocation = Convocation::create(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'assembly_id' => $assembly->id, 'issued_at' => now('UTC'), 'issued_by' => $actor->id, 'legal_deadline_at' => $latestOnTime, 'late_exception' => $lateException, 'late_exception_reason' => $reason, 'path' => $path, 'checksum' => hash('sha256', $pdf), 'frozen_payload' => $payload]);
            foreach ($assembly->electorate as $electorate) {
                $convocation->recipients()->create(['electorate_id' => $electorate->id, 'recipient_name_snapshot' => $electorate->contact_name_snapshot, 'address_snapshot' => $electorate->address_snapshot]);
                app(GovernanceNotificationService::class)->electorateEvent($electorate, $assembly->convocation_number === 2 ? 'second_convocation_available' : 'convocation_available', "convocation:{$convocation->id}:electorate:{$electorate->id}", ['title' => $assembly->convocation_number === 2 ? 'Deuxième convocation disponible' : 'Convocation disponible', 'message' => 'Une convocation est disponible dans votre espace copropriétaire. La notification numérique ne remplace pas la remise légale.'], route('owner-governance.show', $assembly));
            }
            $this->workflow->transition($assembly, 'convocation_issued', $actor, $reason, 'convocation:'.$convocation->id);
            $assembly->agendaItems()->where('status', 'frozen')->update(['frozen_at' => now('UTC')]);
            $assembly->documents()->where('status', 'published')->whereNotNull('published_version_id')->with('publishedVersion')->get()->each(fn ($d) => $d->publishedVersion->update(['frozen_at' => now('UTC')]));
            activity()->performedOn($convocation)->causedBy($actor)->withProperties(['organization_id' => $assembly->organization_id, 'residence_id' => $assembly->residence_id, 'checksum' => $convocation->checksum, 'late_exception' => $lateException])->log('governance.convocation_issued');

            return $convocation->fresh('recipients');
        });
    }

    public function recordDelivery(ConvocationRecipient $recipient, string $method, string $status, User $actor, ?string $reason = null, ?UploadedFile $proof = null): ConvocationRecipient
    {
        return DB::transaction(function () use ($recipient, $method, $status, $actor, $reason, $proof) {
            $recipient = ConvocationRecipient::query()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();
            if (! in_array($method, ['registered_mail', 'bailiff', 'hand_delivery_with_receipt', 'other_legal_method'], true) || ! in_array($status, ['successful', 'failed', 'returned'], true)) {
                throw ValidationException::withMessages(['delivery' => __('Mode ou état de remise invalide.')]);
            }
            if ($status === 'successful' && $recipient->status === 'successful') {
                return $recipient;
            }
            $proofPath = null;
            $checksum = null;
            if ($proof) {
                $proofPath = $proof->store("governance/delivery/{$recipient->id}", 'local');
                $checksum = hash('sha256', Storage::disk('local')->get($proofPath));
            }
            $successKey = $status === 'successful' ? 'successful' : null;
            $recipient->attempts()->create(['method' => $method, 'status' => $status, 'attempted_at' => now('UTC'), 'actor_id' => $actor->id, 'proof_disk' => $proofPath ? 'local' : null, 'proof_path' => $proofPath, 'proof_checksum' => $checksum, 'failure_reason' => $reason, 'success_key' => $successKey]);
            $recipient->update(['delivery_method' => $method, 'status' => $status, 'notified_at' => $status === 'successful' ? now('UTC') : null, 'failure_reason' => $reason, 'attempt_count' => $recipient->attempt_count + 1]);

            return $recipient->fresh();
        });
    }

    private function payload(Assembly $assembly): array
    {
        return ['assembly' => ['id' => $assembly->id, 'reference' => $assembly->reference, 'type' => $assembly->type, 'convocation_number' => $assembly->convocation_number, 'convening_authority' => $assembly->convening_authority, 'meeting_date' => $assembly->meeting_date->toDateString(), 'starts_at' => substr($assembly->starts_at, 0, 5), 'location' => $assembly->location, 'timezone' => $assembly->timezone], 'residence' => ['id' => $assembly->residence_id, 'name' => $assembly->residence->name, 'address' => $assembly->residence->address_line_1, 'city' => $assembly->residence->city], 'agenda' => $assembly->agendaItems->where('status', 'frozen')->sortBy('display_order')->map(fn ($i) => ['id' => $i->id, 'version' => $i->version, 'order' => $i->display_order, 'title_fr' => $i->title_fr, 'title_ar' => $i->title_ar, 'proposed_text_fr' => $i->resolution?->proposed_text_fr, 'proposed_text_ar' => $i->resolution?->proposed_text_ar, 'rule_checksum' => $i->resolution?->ruleSnapshot?->checksum])->values()->all(), 'documents' => $assembly->documents->where('status', 'published')->map(fn ($d) => ['id' => $d->id, 'version_id' => $d->published_version_id, 'checksum' => $d->publishedVersion?->checksum])->values()->all(), 'legal' => ['official_source' => config('governance.legal_source.official_source'), 'source_url' => config('governance.legal_source.source_url'), 'review_status' => config('governance.legal_source.review_status'), 'articles' => ['16 quinquies', '16 septies', '16 decies', '18', '20', '21', '22']]];
    }
}
