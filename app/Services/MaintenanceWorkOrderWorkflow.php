<?php

namespace App\Services;

use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Models\PreventiveIntervention;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceWorkOrderWorkflow
{
    private const TRANSITIONS = ['draft' => ['scheduled', 'cancelled'], 'scheduled' => ['in_progress', 'cancelled'], 'in_progress' => ['completed', 'cancelled'], 'completed' => ['validated', 'in_progress']];

    public function create(array $data, User $actor): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            $request = ! empty($data['maintenance_request_id']) ? MaintenanceRequest::query()->whereKey($data['maintenance_request_id'])->lockForUpdate()->firstOrFail() : null;
            $intervention = ! empty($data['preventive_intervention_id']) ? PreventiveIntervention::query()->whereKey($data['preventive_intervention_id'])->lockForUpdate()->firstOrFail() : null;
            if (($request === null) === ($intervention === null)) {
                throw ValidationException::withMessages(['source' => __('Une seule source de bon de travail est obligatoire.')]);
            }
            if ($request && ! in_array($request->status, ['approved', 'in_progress'], true)) {
                throw ValidationException::withMessages(['maintenance_request_id' => __('La demande doit être approuvée.')]);
            }
            if (($data['is_primary'] ?? true) && MaintenanceWorkOrder::query()->where($request ? 'maintenance_request_id' : 'preventive_intervention_id', $request?->id ?? $intervention?->id)->where('is_primary', true)->whereNot('status', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['source' => __('Un bon de travail principal actif existe déjà.')]);
            }
            $source = $request ?? $intervention;
            $order = MaintenanceWorkOrder::create($data + ['organization_id' => $source->organization_id, 'residence_id' => $source->residence_id, 'reference' => 'BT-'.now()->format('Y').'-'.str()->upper(str()->random(8))]);
            activity()->performedOn($order)->causedBy($actor)->withProperties(['organization_id' => $order->organization_id, 'residence_id' => $order->residence_id, 'estimated_cost_cents' => $order->estimated_cost_cents, 'planned_start_at' => $order->planned_start_at, 'planned_end_at' => $order->planned_end_at])->log('maintenance_work_order.created');

            return $order;
        }, 3);
    }

    public function transition(MaintenanceWorkOrder $order, string $to, User $actor, ?string $report = null): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($order, $to, $actor, $report) {
            $order = MaintenanceWorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status === $to) {
                return $order;
            }
            if (! in_array($to, self::TRANSITIONS[$order->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('Transition de bon de travail invalide.')]);
            }
            if (in_array($to, ['completed', 'validated', 'cancelled'], true) && blank($report)) {
                throw ValidationException::withMessages(['report' => __('Un rapport ou motif est obligatoire.')]);
            }
            if ($to === 'cancelled' && $order->invoice()->whereNot('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['status' => __('Un bon lié à une facture validée ne peut pas être annulé.')]);
            }
            $changes = ['status' => $to];
            if ($to === 'in_progress') {
                $changes['actual_start_at'] = $order->actual_start_at ?? now('UTC');
            }
            if ($to === 'completed') {
                $changes += ['completed_at' => now('UTC'), 'completed_by' => $actor->id, 'completion_report' => $report];
            }
            if ($to === 'validated') {
                $changes += ['validated_at' => now('UTC'), 'validated_by' => $actor->id, 'validation_report' => $report];
            }
            if ($to === 'cancelled') {
                $changes['cancel_reason'] = $report;
            }
            if ($to === 'in_progress' && $order->status === 'completed') {
                $changes += ['completed_at' => null, 'completed_by' => null, 'completion_report' => null];
            }
            $from = $order->status;
            $order->update($changes);
            $this->syncSource($order->fresh(), $actor);
            activity()->performedOn($order)->causedBy($actor)->withProperties(['organization_id' => $order->organization_id, 'residence_id' => $order->residence_id, 'from' => $from, 'to' => $to])->log('maintenance_work_order.transitioned');
            $event = match ($to) {
                'scheduled' => 'intervention_scheduled', 'completed' => 'work_completed', 'validated' => 'work_validated', default => null,
            };
            if ($event && $order->request) {
                app(MaintenanceNotificationService::class)->requestEvent($order->request, $event, "work-order:{$order->id}:{$to}");
            }

            return $order->fresh();
        }, 3);
    }

    private function syncSource(MaintenanceWorkOrder $order, User $actor): void
    {
        if ($order->request && $order->status === 'scheduled' && ! $order->request->scheduled_at) {
            $order->request->update(['scheduled_at' => now('UTC')]);
        }
        if ($order->request && $order->status === 'in_progress' && $order->request->status === 'approved') {
            app(MaintenanceRequestWorkflow::class)->transition($order->request, 'in_progress', $actor, null, "work-order:{$order->id}:started");
        }
        if ($order->intervention && $order->status === 'validated') {
            $order->intervention->update(['status' => 'completed', 'completed_at' => now('UTC')]);
        }
    }
}
