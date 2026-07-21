<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $org = app(TenantContext::class)->organization;

        return ['name' => 'required|string|max:255', 'code' => ['required', 'alpha_dash', 'max:50', Rule::unique('residences')->where('organization_id', $org?->id)->ignore($this->route('residence'))], 'address_line_1' => 'required|string|max:255', 'address_line_2' => 'nullable|string|max:255', 'city' => 'required|string|max:120', 'postal_code' => 'nullable|string|max:20', 'description' => 'nullable|string', 'default_language' => ['required', Rule::in(['fr', 'ar'])], 'fiscal_year_start_month' => 'required|integer|between:1,12', 'fiscal_year_start_day' => 'required|integer|between:1,31', 'logo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:4096'];
    }
}
