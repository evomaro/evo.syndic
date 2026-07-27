<?php

namespace App\Http\Requests\Expenses;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

abstract class ExpenseFormRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $organizationId = $this->context()->organization()->id;

        foreach ($this->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model && $parameter->getAttribute('organization_id') !== null) {
                abort_unless($parameter->getAttribute('organization_id') === $organizationId, 404);
            }
        }
    }

    protected function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function permits(string $permission): bool
    {
        return $this->user()?->canInOrganization($permission, $this->context()->organization()) ?? false;
    }
}
