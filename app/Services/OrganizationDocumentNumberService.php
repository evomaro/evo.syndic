<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class OrganizationDocumentNumberService
{
    public function next(Organization $organization, string $kind, int $year): string
    {
        DB::table('organization_document_sequences')->insertOrIgnore([
            'organization_id' => $organization->id, 'kind' => $kind, 'year' => $year,
            'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = DB::table('organization_document_sequences')->where([
            'organization_id' => $organization->id, 'kind' => $kind, 'year' => $year,
        ])->lockForUpdate()->first();
        DB::table('organization_document_sequences')->where('id', $sequence->id)->increment('next_value');

        return sprintf('%s-%d-%04d', $kind, $year, $sequence->next_value);
    }
}
