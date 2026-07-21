<?php

namespace App\Http\Controllers;

use App\Models\FundCallSchedule;
use App\Rules\ValidMoney;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class FundCallScheduleController extends Controller
{
    public function index(TenantContext $context)
    {
        $r = $context->residence();

        return Inertia::render('Finance/Schedules', ['schedules' => $r->fundCallSchedules()->latest()->get(), 'categories' => $r->chargeCategories()->where('active', true)->get(['id', 'name']), 'allocationKeys' => $r->allocationKeys()->where('active', true)->get(['id', 'name'])]);
    }

    public function store(Request $request, TenantContext $context)
    {
        $r = $context->residence();
        $data = $request->validate(['name' => 'required|string|max:150', 'frequency' => ['required', Rule::in(['monthly', 'quarterly', 'semiannual', 'annual', 'custom'])], 'starts_on' => 'required|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on', 'generation_day' => 'integer|min:1|max:31', 'due_offset_days' => 'integer|min:0|max:365', 'custom_interval_months' => 'nullable|required_if:frequency,custom|integer|min:1|max:120', 'auto_validate' => 'boolean', 'template' => 'required|array', 'template.title' => 'required|string', 'template.lines' => 'required|array|min:1', 'template.lines.*.charge_category_id' => ['required', Rule::exists('charge_categories', 'id')->where('residence_id', $r->id)], 'template.lines.*.amount' => ['required', new ValidMoney]]);
        $schedule = $r->fundCallSchedules()->create($data + ['organization_id' => $r->organization_id, 'next_generation_on' => $data['starts_on'], 'created_by' => $request->user()->id]);

        return back()->with('success', __('Échéancier créé.'));
    }

    public function update(Request $request, FundCallSchedule $schedule, TenantContext $context)
    {
        abort_unless($schedule->organization_id === $context->organization()->id && $schedule->residence_id === $context->residence()->id, 404);
        $schedule->update($request->validate(['active' => 'required|boolean']));

        return back();
    }
}
