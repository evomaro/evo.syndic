<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['type' => ['required', Rule::in(['individual', 'company'])], 'first_name' => 'required_if:type,individual|nullable|string|max:120', 'last_name' => 'required_if:type,individual|nullable|string|max:120', 'company_name' => 'required_if:type,company|nullable|string|max:255', 'cin' => 'nullable|string|max:30', 'passport_number' => 'nullable|string|max:30', 'ice' => 'nullable|string|max:30', 'primary_email' => 'nullable|email|max:255', 'primary_phone' => 'nullable|string|max:40', 'whatsapp_phone' => 'nullable|string|max:40', 'address' => 'nullable|string', 'city' => 'nullable|string|max:120', 'preferred_language' => ['required', Rule::in(['fr', 'ar'])], 'notification_channel' => ['required', Rule::in(['email', 'sms', 'whatsapp', 'in_app', 'none'])], 'notes' => 'nullable|string', 'active' => 'boolean'];
    }
}
