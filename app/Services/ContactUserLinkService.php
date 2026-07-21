<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ContactUserLinkService
{
    public function link(Contact $contact, User $user, User $actor, Organization $organization): void
    {
        $this->authorize($contact, $user, $actor, $organization);

        DB::transaction(function () use ($contact, $user, $actor, $organization) {
            DB::table('contact_user')->updateOrInsert(
                ['contact_id' => $contact->id, 'user_id' => $user->id],
                ['organization_id' => $organization->id, 'linked_by' => $actor->id, 'linked_at' => now(), 'revoked_at' => null, 'revoked_by' => null],
            );
            activity()->performedOn($contact)->causedBy($actor)->withProperties([
                'organization_id' => $organization->id,
                'linked_user_id' => $user->id,
            ])->log('contact_user.linked');
        });
    }

    public function revoke(Contact $contact, User $user, User $actor, Organization $organization): void
    {
        $this->authorize($contact, $user, $actor, $organization);

        DB::transaction(function () use ($contact, $user, $actor, $organization) {
            DB::table('contact_user')->where('contact_id', $contact->id)->where('user_id', $user->id)
                ->where('organization_id', $organization->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
            activity()->performedOn($contact)->causedBy($actor)->withProperties([
                'organization_id' => $organization->id,
                'linked_user_id' => $user->id,
            ])->log('contact_user.revoked');
        });
    }

    private function authorize(Contact $contact, User $user, User $actor, Organization $organization): void
    {
        abort_unless($contact->organization_id === $organization->id && $user->belongsToOrganization($organization), 404);
        $role = $actor->organizations()->whereKey($organization->id)->value('role');
        abort_unless(in_array($role, ['owner', 'administrator'], true), 403);
    }
}
