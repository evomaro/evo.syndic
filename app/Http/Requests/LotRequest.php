<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $r = app(TenantContext::class)->residence();

        return ['reference' => ['required', 'max:80', Rule::unique('lots')->where('residence_id', $r->id)->ignore($this->route('lot'))], 'lot_number' => 'required|string|max:80', 'type' => ['required', Rule::in(['apartment', 'villa', 'shop', 'office', 'garage', 'parking', 'storage', 'other'])], 'building_id' => ['nullable', Rule::exists('buildings', 'id')->where('residence_id', $r->id)], 'entrance_id' => ['nullable', Rule::exists('entrances', 'id')->where(fn ($q) => $q->whereIn('building_id', $r->buildings()->select('id')))], 'floor_id' => ['nullable', Rule::exists('floors', 'id')->where(fn ($q) => $q->whereIn('building_id', $r->buildings()->select('id')))], 'title' => 'nullable|string|max:255', 'surface' => 'nullable|numeric|min:0', 'property_title_number' => 'nullable|string|max:120', 'notes' => 'nullable|string', 'active' => 'boolean'];
    }
}
