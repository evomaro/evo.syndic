<?php

namespace App\Services;

use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceRequestWorkflow
{
    private const TRANSITIONS = [
        'draft' => ['submitted'], 'submitted' => ['under_review', 'cancelled'],
        'under_review' => ['approved', 'rejected', 'cancelled'], 'approved' => ['in_progress', 'rejected'],
        'in_progress' => ['resolved'], 'resolved' => ['closed', 'in_progress'], 'closed' => ['in_progress'],
    ];

    public function transition(MaintenanceRequest $request, string $to, User $actor, ?string $reason, string $idempotencyKey): MaintenanceRequest
    {
        return DB::transaction(function () use ($request, $to, $actor, $reason, $idempotencyKey) {
            $request = MaintenanceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->transitions()->where('idempotency_key', $idempotencyKey)->exists()) {
                return $request;
            }
            if (! in_array($to, self::TRANSITIONS[$request->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('Transition de demande invalide.')]);
            }
            if (in_array($to, ['rejected', 'cancelled'], true) && blank($reason)) {
                throw ValidationException::withMessages(['reason' => __('Un motif est obligatoire.')]);
            }
            if ($to === 'resolved' && blank($reason)) {
                throw ValidationException::withMessages(['reason' => __('Un résumé de résolution est obligatoire.')]);
            }
            if ($to === 'rejected' && $request->status === 'approved' && $request->workOrders()->whereNotIn('status', ['draft', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['status' => __('Une demande avec travaux actifs ne peut pas être rejetée.')]);
            }
            if ($to === 'in_progress' && in_array($request->status, ['resolved', 'closed'], true)) {
                if ($request->reopen_deadline_at?->isPast()) {
                    throw ValidationException::withMessages(['status' => __('La période de réouverture est expirée.')]);
                }
                if (blank($reason)) {
                    throw ValidationException::withMessages(['reason' => __('Un motif de réouverture est obligatoire.')]);
                }
            }

            $from = $request->status;
            $changes = ['status' => $to];
            $timestamp = match ($to) {
                'submitted' => 'submitted_at', 'under_review' => 'acknowledged_at', 'approved' => 'approved_at',
                'rejected' => 'rejected_at', 'in_progress' => 'started_at', 'resolved' => 'resolved_at',
                'closed' => 'closed_at', 'cancelled' => 'cancelled_at', default => null,
            };
            if ($timestamp && ! ($to === 'in_progress' && $request->started_at)) {
                $changes[$timestamp] = now('UTC');
            }
            if ($to === 'submitted') {
                $snapshot = $request->sla_snapshot;
                $changes += [
                    'ack_deadline_at' => now('UTC')->addMinutes($snapshot['ack_target_minutes']),
                    'schedule_deadline_at' => now('UTC')->addMinutes($snapshot['schedule_target_minutes']),
                    'resolution_deadline_at' => now('UTC')->addMinutes($snapshot['resolution_target_minutes']),
                ];
            }
            if ($to === 'resolved') {
                $changes += ['resolution_summary' => $reason, 'reopen_deadline_at' => now('UTC')->addDays((int) config('evosyndic.maintenance.reopen_days', 7))];
            } elseif ($to === 'rejected') {
                $changes['rejection_reason'] = $reason;
            } elseif ($to === 'closed') {
                $changes['closure_reason'] = $reason;
            } elseif ($to === 'cancelled') {
                $changes['cancellation_reason'] = $reason;
            } elseif ($to === 'in_progress' && in_array($from, ['resolved', 'closed'], true)) {
                $changes['reopen_count'] = $request->reopen_count + 1;
            }
            $request->update($changes);
            $request->transitions()->create(['from_status' => $from, 'to_status' => $to, 'actor_id' => $actor->id, 'reason' => $reason, 'idempotency_key' => $idempotencyKey, 'transitioned_at' => now('UTC')]);
            activity()->performedOn($request)->causedBy($actor)->withProperties(['organization_id' => $request->organization_id, 'residence_id' => $request->residence_id, 'from' => $from, 'to' => $to, 'reason' => $reason])->log('maintenance_request.transitioned');
            $event = match ($to) {
                'submitted' => 'request_submitted', 'under_review' => 'request_acknowledged', 'approved' => 'request_approved',
                'rejected' => 'request_rejected', 'resolved' => 'request_resolved', 'closed' => 'request_closed',
                'cancelled' => 'request_cancelled', 'in_progress' => in_array($from, ['resolved', 'closed'], true) ? 'request_reopened' : 'work_started', default => null,
            };
            if ($event) {
                app(MaintenanceNotificationService::class)->requestEvent($request->fresh('organization'), $event, $idempotencyKey);
            }

            return $request->fresh();
        });
    }
}
