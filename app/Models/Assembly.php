<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class Assembly extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'meeting_date' => 'date', 'eligibility_on' => 'date',
            'convocation_deadline_at' => 'datetime', 'documents_available_at' => 'datetime',
            'opened_at' => 'datetime', 'closed_at' => 'datetime', 'finalized_at' => 'datetime',
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function exercise()
    {
        return $this->belongsTo(FinancialExercise::class, 'financial_exercise_id');
    }

    public function parentAssembly()
    {
        return $this->belongsTo(self::class, 'parent_assembly_id');
    }

    public function secondConvocation()
    {
        return $this->hasOne(self::class, 'parent_assembly_id');
    }

    public function mandate()
    {
        return $this->belongsTo(GovernanceMandate::class, 'governance_mandate_id');
    }

    public function transitions()
    {
        return $this->hasMany(AssemblyTransition::class);
    }

    public function agendaItems()
    {
        return $this->hasMany(AssemblyAgendaItem::class);
    }

    public function agendaVersions()
    {
        return $this->hasMany(AssemblyAgendaVersion::class);
    }

    public function activeAgendaVersion()
    {
        return $this->belongsTo(AssemblyAgendaVersion::class, 'active_agenda_version_id');
    }

    public function eligibilitySnapshots()
    {
        return $this->hasMany(AssemblyEligibilitySnapshot::class);
    }

    public function eligibilitySnapshot()
    {
        return $this->belongsTo(AssemblyEligibilitySnapshot::class);
    }

    public function participants()
    {
        return $this->hasMany(AssemblyParticipant::class);
    }

    public function resolutions()
    {
        return $this->hasMany(AssemblyResolution::class);
    }

    public function electorate()
    {
        return $this->hasMany(AssemblyElectorate::class);
    }

    public function convocations()
    {
        return $this->hasMany(Convocation::class);
    }

    public function documents()
    {
        return $this->hasMany(GovernanceDocument::class);
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AssemblyAttendanceRecord::class);
    }

    public function proxies()
    {
        return $this->hasMany(AssemblyProxy::class);
    }

    public function quorumSnapshots()
    {
        return $this->hasMany(AssemblyQuorumSnapshot::class);
    }

    public function minutes()
    {
        return $this->hasOne(AssemblyMinutes::class);
    }

    public function chairpersonContact()
    {
        return $this->belongsTo(Contact::class, 'chairperson_contact_id');
    }

    public function secretaryUser()
    {
        return $this->belongsTo(User::class, 'secretary_user_id');
    }

    public function agendaQuestions()
    {
        return $this->hasMany(AgendaQuestionSubmission::class);
    }

    public function decisionNotifications()
    {
        return $this->hasMany(DecisionNotification::class);
    }
}
