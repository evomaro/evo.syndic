<?php

namespace App\Services;

use App\Models\AssemblyResolution;
use App\Models\FinancialExercise;
use App\Models\FundCall;
use App\Models\MaintenanceCategory;
use App\Models\MaintenanceRequest;
use App\Models\ResolutionExecutionAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolutionExecutionService
{
    public function create(AssemblyResolution $resolution, array $data, User $actor): ResolutionExecutionAction
    {
        return DB::transaction(function () use ($resolution, $data, $actor) {
            $resolution = AssemblyResolution::query()->whereKey($resolution->id)->with(['assembly', 'finalResult'])->lockForUpdate()->firstOrFail();
            if (! $resolution->finalResult?->adopted || $resolution->status !== 'adopted') {
                throw ValidationException::withMessages(['resolution' => __('Seule une résolution adoptée et finalisée peut être exécutée.')]);
            }
            $action = ResolutionExecutionAction::firstOrCreate(
                ['resolution_id' => $resolution->id, 'source_key' => $data['source_key']],
                ['organization_id' => $resolution->assembly->organization_id, 'residence_id' => $resolution->assembly->residence_id, 'action_type' => $data['action_type'], 'responsible_user_id' => $data['responsible_user_id'] ?? null, 'due_on' => $data['due_on'] ?? null, 'description' => $data['description'], 'status' => 'pending', 'created_by' => $actor->id],
            );
            activity()->performedOn($action)->causedBy($actor)->withProperties(['organization_id' => $action->organization_id, 'residence_id' => $action->residence_id, 'resolution_id' => $resolution->id])->log('governance.execution_created');
            if ($action->responsible_user_id && $user = User::find($action->responsible_user_id)) {
                app(GovernanceNotificationService::class)->userEvent($user, $resolution->assembly, 'execution_action_assigned', "execution:{$action->id}:assigned", ['title' => 'Action d’exécution assignée', 'message' => 'Une action issue d’une résolution vous a été assignée.'], route('governance.show', $resolution->assembly));
            }

            return $action;
        });
    }

    public function createDraftFundCall(ResolutionExecutionAction $action, array $data, User $actor): FundCall
    {
        return DB::transaction(function () use ($action, $data, $actor) {
            $action = ResolutionExecutionAction::query()->whereKey($action->id)->with('resolution.finalResult')->lockForUpdate()->firstOrFail();
            $this->assertExecutable($action, 'fund_call_preparation');
            if ($action->related_type) {
                return FundCall::findOrFail($action->related_id);
            }
            foreach (['organization_id', 'residence_id'] as $field) {
                if ((int) $data[$field] !== (int) $action->$field) {
                    throw ValidationException::withMessages(['scope' => __('La portée financière ne correspond pas à la résolution.')]);
                }
            }
            $exercise = FinancialExercise::whereKey($data['financial_exercise_id'])->where('residence_id', $action->residence_id)->first();
            if (! $exercise) {
                throw ValidationException::withMessages(['financial_exercise_id' => __('L’exercice financier ne correspond pas à la résidence.')]);
            }
            $snapshot = $action->resolution->financial_snapshot ?? [];
            if (isset($snapshot['financial_exercise_id']) && (int) $snapshot['financial_exercise_id'] !== $exercise->id) {
                throw ValidationException::withMessages(['financial_exercise_id' => __('L’exercice demandé diffère de la version financière votée.')]);
            }
            $call = FundCall::create(collect($data)->only(['organization_id', 'residence_id', 'financial_exercise_id', 'title', 'description', 'issue_date', 'due_date'])->all() + ['status' => 'draft', 'total_cents' => 0, 'metadata' => ['governance_resolution_id' => $action->resolution_id, 'execution_action_id' => $action->id, 'financial_snapshot_checksum' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))]]);
            $action->update(['status' => 'in_progress', 'related_type' => FundCall::class, 'related_id' => $call->id]);
            activity()->performedOn($action)->causedBy($actor)->withProperties(['organization_id' => $action->organization_id, 'residence_id' => $action->residence_id, 'fund_call_id' => $call->id])->log('governance.execution_linked');

            return $call;
        });
    }

    public function createMaintenanceRequest(ResolutionExecutionAction $action, array $data, User $actor): MaintenanceRequest
    {
        return DB::transaction(function () use ($action, $data, $actor) {
            $action = ResolutionExecutionAction::query()->whereKey($action->id)->with('resolution.finalResult')->lockForUpdate()->firstOrFail();
            $this->assertExecutable($action, 'maintenance_request');
            if ($action->related_type) {
                return MaintenanceRequest::findOrFail($action->related_id);
            }
            $category = MaintenanceCategory::whereKey($data['maintenance_category_id'])->where('organization_id', $action->organization_id)->where('active', true)->first();
            if (! $category) {
                throw ValidationException::withMessages(['maintenance_category_id' => __('La catégorie de maintenance ne correspond pas à l’organisation.')]);
            }
            $payload = collect($data)->only(['maintenance_category_id', 'reference', 'title', 'description', 'location', 'priority', 'observed_on', 'sla_snapshot'])->all();
            $request = MaintenanceRequest::create(['organization_id' => $action->organization_id, 'residence_id' => $action->residence_id, 'reporter_user_id' => $actor->id, 'reporter_role' => 'manager', 'status' => 'draft'] + $payload);
            $action->update(['status' => 'in_progress', 'related_type' => MaintenanceRequest::class, 'related_id' => $request->id]);
            activity()->performedOn($action)->causedBy($actor)->withProperties(['organization_id' => $action->organization_id, 'residence_id' => $action->residence_id, 'maintenance_request_id' => $request->id])->log('governance.execution_linked');

            return $request;
        });
    }

    public function complete(ResolutionExecutionAction $action, string $result, User $actor): ResolutionExecutionAction
    {
        return DB::transaction(function () use ($action, $result, $actor) {
            $action = ResolutionExecutionAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
            if (! in_array($action->status, ['pending', 'in_progress', 'blocked'], true)) {
                throw ValidationException::withMessages(['execution' => __('Cette action ne peut pas être clôturée.')]);
            }
            $action->update(['status' => 'completed', 'completion_result' => $result, 'completed_at' => now('UTC'), 'completed_by' => $actor->id]);
            activity()->performedOn($action)->causedBy($actor)->withProperties(['organization_id' => $action->organization_id, 'residence_id' => $action->residence_id])->log('governance.execution_completed');

            return $action;
        });
    }

    private function assertExecutable(ResolutionExecutionAction $action, string $type): void
    {
        if (! $action->resolution->finalResult?->adopted || $action->action_type !== $type) {
            throw ValidationException::withMessages(['execution' => __('L’action ne correspond pas à une décision adoptée.')]);
        }
    }
}
