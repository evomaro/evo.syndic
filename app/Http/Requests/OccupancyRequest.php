<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OccupancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $o = app(TenantContext::class)->organization();

        return ['contact_id' => ['required', Rule::exists('contacts', 'id')->where('organization_id', $o->id)], 'type' => ['required', Rule::in(['owner', 'tenant', 'family_member', 'employee', 'other'])], 'starts_on' => 'required|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on', 'is_primary_occupant' => 'boolean', 'notes' => 'nullable|string'];
    }
}
