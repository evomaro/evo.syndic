<?php

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use App\Notifications\TeamInvitationNotification;
use App\Services\MembershipAuthorization;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TeamController extends Controller
{
    public function index(TenantContext $context)
    {
        $organization = $context->organization();

        return Inertia::render('Team/Index', [
            'members' => $organization->users()->get(),
            'invitations' => $organization->invitations()->latest()->get(),
            'permissions' => config('evosyndic.permissions'),
        ]);
    }

    public function invite(Request $request, TenantContext $context)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'role' => ['required', Rule::in(array_keys(config('evosyndic.roles')))],
            'preferred_language' => ['nullable', Rule::in(['fr', 'ar'])],
        ]);
        abort_unless(app(MembershipAuthorization::class)->mayAssign($request->user(), $context->organization(), $data['role'], []), 403);
        $token = bin2hex(random_bytes(32));
        $invitation = $context->organization()->invitations()->updateOrCreate(
            ['email' => strtolower($data['email']), 'accepted_at' => null],
            ['role' => $data['role'], 'preferred_language' => $data['preferred_language'] ?? 'fr', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'cancelled_at' => null, 'invited_by' => $request->user()->id],
        );
        $this->notify($invitation, $token);
        $this->log($invitation, $request, 'invitation.created');

        return back()->with('success', __('Invitation envoyée.'));
    }

    public function resend(Request $request, TeamInvitation $invitation, TenantContext $context)
    {
        abort_unless($invitation->organization_id === $context->organization()->id, 404);
        abort_if($invitation->accepted_at, 409);
        $token = bin2hex(random_bytes(32));
        $invitation->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'cancelled_at' => null]);
        $this->notify($invitation, $token);
        $this->log($invitation, $request, 'invitation.resent');

        return back()->with('success', __('Invitation renvoyée.'));
    }

    public function cancel(Request $request, TeamInvitation $invitation, TenantContext $context)
    {
        abort_unless($invitation->organization_id === $context->organization()->id, 404);
        abort_if($invitation->accepted_at, 409);
        $invitation->update(['cancelled_at' => now()]);
        $this->log($invitation, $request, 'invitation.cancelled');

        return back()->with('success', __('Invitation annulée.'));
    }

    public function role(Request $request, int $user, TenantContext $context)
    {
        $organization = $context->organization();
        $actor = $organization->users()->whereKey($request->user()->id)->first()?->pivot;
        $target = $organization->users()->whereKey($user)->firstOrFail();
        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(config('evosyndic.roles')))],
            'permissions' => 'array',
            'permissions.*' => [Rule::in(config('evosyndic.permissions'))],
        ]);
        abort_unless(in_array($actor->role, ['owner', 'administrator'], true), 403);
        if ($target->pivot->role === 'owner' && $data['role'] !== 'owner' && $organization->users()->wherePivot('role', 'owner')->count() === 1) {
            abort(422, __('Le dernier propriétaire ne peut pas être retiré.'));
        }
        abort_unless(app(MembershipAuthorization::class)->mayAssign($request->user(), $organization, $data['role'], $data['permissions'] ?? []), 403);
        $organization->users()->updateExistingPivot($user, ['role' => $data['role'], 'permissions' => json_encode($data['permissions'] ?? [])]);

        return back()->with('success', __('Autorisations mises à jour.'));
    }

    private function notify(TeamInvitation $invitation, string $token): void
    {
        Notification::route('mail', $invitation->email)->notify((new TeamInvitationNotification($invitation->load('organization'), $token))->locale($invitation->preferred_language));
    }

    private function log(TeamInvitation $invitation, Request $request, string $event): void
    {
        activity()->performedOn($invitation)->causedBy($request->user())->withProperties(['organization_id' => $invitation->organization_id, 'email' => $invitation->email, 'role' => $invitation->role])->log($event);
    }
}
