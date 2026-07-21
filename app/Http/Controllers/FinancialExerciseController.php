<?php

namespace App\Http\Controllers;

use App\Models\FinancialExercise;
use App\Services\FinancialExerciseLifecycleService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FinancialExerciseController extends Controller
{
    public function index(TenantContext $context)
    {
        return Inertia::render('Finance/Exercises', ['exercises' => $context->residence()->financialExercises()->latest('starts_on')->get()]);
    }

    public function store(Request $request, TenantContext $context)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after:starts_on', 'notes' => 'nullable|string|max:2000']);
        $residence = $context->residence();
        $exercise = DB::transaction(function () use ($residence, $data) {
            $residence->financialExercises()->lockForUpdate()->get();
            if ($residence->financialExercises()->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => __('Cette période chevauche un exercice existant.')]);
            }

            return $residence->financialExercises()->create($data + ['organization_id' => $residence->organization_id]);
        });
        activity()->performedOn($exercise)->causedBy($request->user())->withProperties(['organization_id' => $residence->organization_id, 'residence_id' => $residence->id])->log('financial_exercise.created');

        return back()->with('success', __('Exercice créé.'));
    }

    public function update(Request $request, FinancialExercise $exercise, TenantContext $context)
    {
        $this->tenant($exercise, $context);
        if ($exercise->status !== 'draft') {
            abort(409);
        }
        $data = $request->validate(['name' => 'required|string|max:120', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after:starts_on', 'notes' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($context, $exercise, $data) {
            $context->residence()->financialExercises()->lockForUpdate()->get();
            if ($context->residence()->financialExercises()->whereKeyNot($exercise->id)->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => __('Cette période chevauche un exercice existant.')]);
            }
            $exercise->update($data);
        });

        return back()->with('success', __('Exercice mis à jour.'));
    }

    public function transition(Request $request, FinancialExercise $exercise, TenantContext $context, FinancialExerciseLifecycleService $lifecycle)
    {
        $this->tenant($exercise, $context);
        $data = $request->validate(['action' => ['required', Rule::in(['open', 'close', 'reopen'])], 'reason' => 'nullable|string|max:1000']);
        $lifecycle->transition($exercise, $data['action'], $request->user(), $data['reason'] ?? null);

        return back()->with('success', __('Statut de l’exercice mis à jour.'));
    }

    private function tenant(FinancialExercise $exercise, TenantContext $context): void
    {
        abort_unless($exercise->organization_id === $context->organization()->id && $exercise->residence_id === $context->residence()->id, 404);
    }
}
