<?php

namespace App\Services;

use App\Jobs\DispatchConvocationAvailability;
use App\Models\ConvocationRecipient;
use App\Models\User;
use Carbon\CarbonImmutable;

class GovernanceDeliveryScheduler
{
    public function dispatch(CarbonImmutable $date, User $actor, bool $apply = false, int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        $counts = ['checked' => 0, 'queued' => 0, 'missing_recipient' => 0, 'already_successful' => 0];

        $recipients = ConvocationRecipient::query()
            ->with(['convocation.assembly', 'electorate'])
            ->whereHas('convocation', fn ($query) => $query->where('issued_at', '<=', $date->endOfDay()))
            ->whereIn('status', ['pending', 'failed', 'returned'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($recipients as $recipient) {
            $counts['checked']++;
            $assembly = $recipient->convocation?->assembly;
            if (
                ! $assembly
                || ! $recipient->electorate
                || $recipient->electorate->organization_id !== $assembly->organization_id
                || $recipient->electorate->residence_id !== $assembly->residence_id
                || ! $actor->belongsToOrganization($assembly->organization_id)
            ) {
                $counts['missing_recipient']++;

                continue;
            }
            if ($recipient->status === 'successful') {
                $counts['already_successful']++;

                continue;
            }

            $eventKey = "convocation:{$recipient->convocation_id}:recipient:{$recipient->id}:availability";
            if ($apply) {
                DispatchConvocationAvailability::dispatch($recipient->id, $actor->id, $eventKey);
            }
            $counts['queued']++;
        }

        return $counts + ['applied' => $apply, 'limit' => $limit, 'legal_delivery_status_changed' => false];
    }
}
