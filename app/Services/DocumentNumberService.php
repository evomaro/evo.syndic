<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\Residence;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public function next(Residence $residence, string $kind, int $year): string
    {
        DB::table('document_sequences')->insertOrIgnore([
            'residence_id' => $residence->id, 'kind' => $kind, 'year' => $year,
            'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = DocumentSequence::query()->where(['residence_id' => $residence->id, 'kind' => $kind, 'year' => $year])->lockForUpdate()->firstOrFail();
        $value = $sequence->next_value;
        $sequence->increment('next_value');

        return sprintf('%s-%d-%04d', $kind, $year, $value);
    }
}
