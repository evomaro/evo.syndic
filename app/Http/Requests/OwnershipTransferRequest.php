<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OwnershipTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $o = app(TenantContext::class)->organization();

        return ['effective_date' => 'required|date', 'acknowledge_incomplete' => 'boolean', 'owners' => 'required|array|min:1', 'owners.*.contact_id' => ['required', 'distinct', Rule::exists('contacts', 'id')->where('organization_id', $o->id)], 'owners.*.percentage' => 'required|numeric|gt:0|max:100', 'owners.*.is_primary' => 'boolean'];
    }
}
