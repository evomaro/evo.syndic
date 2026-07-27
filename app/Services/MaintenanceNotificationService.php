<?php

namespace App\Services;

use App\Models\MaintenanceRequest;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\PortalNotification;
use Illuminate\Support\Facades\DB;

class MaintenanceNotificationService
{
    private const COPY = [
        'request_submitted' => ['fr' => ['Demande soumise', 'Une nouvelle demande de maintenance a été soumise.'], 'ar' => ['تم إرسال الطلب', 'تم إرسال طلب صيانة جديد.']],
        'request_acknowledged' => ['fr' => ['Demande prise en charge', 'Votre demande est en cours d’examen.'], 'ar' => ['تم استلام الطلب', 'طلبكم قيد المراجعة.']],
        'request_approved' => ['fr' => ['Demande approuvée', 'Votre demande de maintenance a été approuvée.'], 'ar' => ['تمت الموافقة', 'تمت الموافقة على طلب الصيانة.']],
        'request_rejected' => ['fr' => ['Demande refusée', 'Une décision a été prise sur votre demande.'], 'ar' => ['تم رفض الطلب', 'تم اتخاذ قرار بشأن طلبكم.']],
        'work_started' => ['fr' => ['Travaux commencés', 'Les travaux liés à votre demande ont commencé.'], 'ar' => ['بدء الأشغال', 'بدأت الأشغال المرتبطة بطلبكم.']],
        'request_resolved' => ['fr' => ['Demande résolue', 'La demande est prête pour votre confirmation.'], 'ar' => ['تم حل الطلب', 'الطلب جاهز لتأكيدكم.']],
        'request_closed' => ['fr' => ['Demande clôturée', 'La demande de maintenance a été clôturée.'], 'ar' => ['تم إغلاق الطلب', 'تم إغلاق طلب الصيانة.']],
        'request_reopened' => ['fr' => ['Demande réouverte', 'La demande de maintenance a été réouverte.'], 'ar' => ['أعيد فتح الطلب', 'تمت إعادة فتح طلب الصيانة.']],
        'request_cancelled' => ['fr' => ['Demande annulée', 'La demande de maintenance a été annulée.'], 'ar' => ['تم إلغاء الطلب', 'تم إلغاء طلب الصيانة.']],
        'assignment_changed' => ['fr' => ['Affectation mise à jour', 'La responsabilité de la demande a été mise à jour.'], 'ar' => ['تم تحديث التعيين', 'تم تحديث مسؤولية طلب الصيانة.']],
        'intervention_scheduled' => ['fr' => ['Intervention planifiée', 'Une intervention de maintenance a été planifiée.'], 'ar' => ['تمت جدولة التدخل', 'تمت جدولة تدخل للصيانة.']],
        'intervention_rescheduled' => ['fr' => ['Intervention replanifiée', 'La date d’une intervention de maintenance a changé.'], 'ar' => ['تمت إعادة جدولة التدخل', 'تم تغيير موعد تدخل الصيانة.']],
        'work_completed' => ['fr' => ['Travaux terminés', 'Les travaux sont terminés et attendent validation.'], 'ar' => ['اكتملت الأشغال', 'اكتملت الأشغال وهي في انتظار المصادقة.']],
        'work_validated' => ['fr' => ['Travaux validés', 'Les travaux de maintenance ont été validés.'], 'ar' => ['تمت المصادقة على الأشغال', 'تمت المصادقة على أشغال الصيانة.']],
        'preventive_due' => ['fr' => ['Maintenance préventive à réaliser', 'Une intervention préventive est arrivée à échéance.'], 'ar' => ['صيانة وقائية مستحقة', 'حان موعد تدخل للصيانة الوقائية.']],
        'sla_exceeded' => ['fr' => ['SLA de maintenance dépassé', 'Un délai de traitement de maintenance a été dépassé.'], 'ar' => ['تم تجاوز مهلة الصيانة', 'تم تجاوز إحدى مهل معالجة الصيانة.']],
    ];

    public function requestEvent(MaintenanceRequest $request, string $event, string $cycle): int
    {
        return $this->scopeEvent($request->organization_id, $request->residence_id, $event, $cycle, "/maintenance/requests/{$request->id}", $request->reporter_user_id, "/resident/maintenance/{$request->id}");
    }

    public function scopeEvent(int $organizationId, int $residenceId, string $event, string $cycle, string $managerUrl, ?int $residentId = null, ?string $residentUrl = null): int
    {
        $organization = Organization::findOrFail($organizationId);
        $recipients = User::query()->whereHas('organizations', fn ($q) => $q->where('organizations.id', $organizationId))
            ->where(fn ($q) => $residentId ? $q->whereKey($residentId)->orWhereHas('organizations', fn ($m) => $m->where('organizations.id', $organizationId)->whereIn('role', ['owner', 'administrator', 'manager', 'maintenance_agent'])) : $q->whereHas('organizations', fn ($m) => $m->where('organizations.id', $organizationId)->whereIn('role', ['owner', 'administrator', 'manager', 'maintenance_agent'])))
            ->get()->filter(function (User $user) use ($organization, $organizationId, $residenceId, $residentId) {
                $membership = $user->organizations()->whereKey($organizationId)->first()?->pivot;

                return $membership && (($residentId && $user->id === $residentId && $user->residences()->whereKey($residenceId)->exists()) || ($user->canInOrganization('view_maintenance_requests', $organization) && ($membership->all_residences || $user->residences()->whereKey($residenceId)->exists())));
            });
        $sent = 0;
        foreach ($recipients as $user) {
            $preference = NotificationPreference::where('user_id', $user->id)->where('organization_id', $organizationId)->first();
            if (in_array($event, $preference?->muted_events ?? [], true)) {
                continue;
            }
            foreach (['database' => $preference?->database_enabled ?? true, 'mail' => $preference?->email_enabled ?? true] as $channel => $enabled) {
                if (! $enabled) {
                    continue;
                }
                $key = "maintenance:{$organizationId}:{$residenceId}:{$event}:{$cycle}";
                if (! DB::table('notification_dispatches')->insertOrIgnore(['user_id' => $user->id, 'organization_id' => $organizationId, 'residence_id' => $residenceId, 'event_key' => $key, 'event_type' => $event, 'channel' => $channel, 'status' => 'queued', 'attempt_count' => 1, 'last_attempted_at' => now(), 'dispatched_at' => now(), 'created_at' => now(), 'updated_at' => now()])) {
                    continue;
                }
                $locale = $user->preferred_language === 'ar' ? 'ar' : 'fr';
                [$title, $message] = self::COPY[$event][$locale] ?? [$event, $event];
                $user->notify(new PortalNotification(['type' => $event, 'organization_id' => $organizationId, 'residence_id' => $residenceId, 'language' => $locale, 'title' => $title, 'message' => $message, 'url' => $residentId && $user->id === $residentId ? ($residentUrl ?? $managerUrl) : $managerUrl], [$channel], $key));
                $sent++;
            }
        }

        return $sent;
    }
}
