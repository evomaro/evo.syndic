<?php

namespace App\Http\Requests\Governance;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssemblyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $c = app(TenantContext::class);

        return ['type' => ['required', Rule::in(['constitutive', 'ordinary', 'extraordinary'])], 'financial_exercise_id' => ['nullable', Rule::exists('financial_exercises', 'id')->where(fn ($q) => $q->where('organization_id', $c->organization()->id)->where('residence_id', $c->residence()->id))], 'governance_mandate_id' => ['required', Rule::exists('governance_mandates', 'id')->where(fn ($q) => $q->where('organization_id', $c->organization()->id)->where('residence_id', $c->residence()->id)->where('status', 'active'))], 'convening_authority' => 'required|string|max:160', 'meeting_date' => 'required|date|after:today', 'starts_at' => 'required|date_format:H:i', 'expected_ends_at' => 'nullable|date_format:H:i|after:starts_at', 'location' => 'required|string|max:255'];
    }
}
