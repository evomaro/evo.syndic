<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class AgendaQuestionSubmission extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['submission_deadline_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function electorate()
    {
        return $this->belongsTo(AssemblyElectorate::class);
    }
}
