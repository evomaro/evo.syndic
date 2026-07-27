<?php

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgendaItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['display_order' => 'required|integer|min:1|max:999', 'title_fr' => 'required|string|max:255', 'title_ar' => 'nullable|string|max:255', 'explanation_fr' => 'nullable|string|max:5000', 'explanation_ar' => 'nullable|string|max:5000', 'proposed_text_fr' => 'nullable|string|max:10000', 'proposed_text_ar' => 'nullable|string|max:10000', 'category' => 'required|string|max:60', 'financial_impact_cents' => 'nullable|integer|min:0', 'resident_visible' => 'boolean', 'internal_notes' => 'nullable|string|max:5000', 'rule_identifier' => ['nullable', Rule::in(array_keys(config('governance.rules')))], 'resolution_code' => 'nullable|required_with:rule_identifier|string|max:40', 'resolution_category' => 'nullable|required_with:rule_identifier|string|max:60'];
    }
}
