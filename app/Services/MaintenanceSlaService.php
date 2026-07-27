<?php

namespace App\Services;

use App\Models\MaintenanceRequest;
use Illuminate\Support\Facades\DB;

class MaintenanceSlaService
{
    public function evaluate(bool $apply = true): array
    {
        $result = ['evaluated' => 0, 'created' => 0];
        $thresholds = ['acknowledgement' => ['ack_deadline_at', 'acknowledged_at'], 'scheduling' => ['schedule_deadline_at', 'scheduled_at'], 'resolution' => ['resolution_deadline_at', 'resolved_at']];
        MaintenanceRequest::query()->whereNotIn('status', ['draft', 'rejected', 'cancelled'])->orderBy('id')->chunkById(200, function ($requests) use (&$result, $thresholds, $apply) {
            foreach ($requests as $request) {
                $result['evaluated']++;
                foreach ($thresholds as $name => [$deadline, $completed]) {
                    if (! $request->{$deadline} || $request->{$deadline}->isFuture() || ($request->{$completed} && $request->{$completed}->lte($request->{$deadline}))) {
                        continue;
                    }
                    $cycle = hash('sha256', $request->{$deadline}->utc()->toIso8601String());
                    if ($apply) {
                        $created = DB::table('maintenance_sla_events')->insertOrIgnore(['maintenance_request_id' => $request->id, 'threshold' => $name, 'deadline_at' => $request->{$deadline}, 'exceeded_at' => now('UTC'), 'deadline_cycle' => $cycle, 'created_at' => now(), 'updated_at' => now()]);
                        $result['created'] += $created;
                        if ($created) {
                            app(MaintenanceNotificationService::class)->requestEvent($request, 'sla_exceeded', "{$name}:{$cycle}");
                        }
                    }
                }
            }
        });

        return $result;
    }
}
