<?php

namespace App\Jobs;

use App\Models\ConvocationRecipient;
use App\Models\User;
use App\Services\GovernanceNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchConvocationAvailability implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly int $recipientId,
        public readonly int $actorId,
        public readonly string $eventKey,
    ) {}

    public function uniqueId(): string
    {
        return $this->eventKey;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(GovernanceNotificationService $notifications): void
    {
        $recipient = ConvocationRecipient::query()
            ->with(['convocation.assembly', 'electorate'])
            ->find($this->recipientId);
        $actor = User::find($this->actorId);

        if (! $recipient || ! $actor || ! $recipient->electorate || ! $recipient->convocation?->assembly) {
            return;
        }

        $assembly = $recipient->convocation->assembly;
        if (
            $recipient->electorate->assembly_id !== $assembly->id
            || $recipient->electorate->organization_id !== $assembly->organization_id
            || $recipient->electorate->residence_id !== $assembly->residence_id
            || ! $actor->belongsToOrganization($assembly->organization_id)
        ) {
            return;
        }

        $notifications->electorateEvent(
            $recipient->electorate,
            'convocation_available',
            $this->eventKey,
            [
                'title' => 'Convocation disponible',
                'message' => 'Une convocation est disponible dans votre espace copropriétaire. La notification numérique ne remplace pas la remise légale.',
            ],
            route('owner-governance.show', $assembly),
        );
    }
}
