<?php

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;

class InvitationController extends Controller
{
    public function show(Request $request, string $token)
    {
        $invitation = $this->find($token);
        if (! $invitation || ! $invitation->isUsable()) {
            return Inertia::render('Invitations/Invalid', ['reason' => $this->reason($invitation)])->toResponse($request)->setStatusCode(410);
        }
        session(['locale' => $invitation->preferred_language]);
        if (! Auth::check()) {
            session(['invitation_intended' => route('invitations.show', $token)]);
        }

        return Inertia::render('Invitations/Show', [
            'invitation' => ['email' => $invitation->email, 'organization' => $invitation->organization->name, 'role' => $invitation->role, 'expires_at' => $invitation->expires_at],
            'token' => $token,
            'authenticated' => Auth::check(),
            'email_matches' => Auth::check() && strcasecmp(Auth::user()->email, $invitation->email) === 0,
        ]);
    }

    public function register(Request $request, string $token)
    {
        $invitation = $this->find($token);
        abort_unless($invitation?->isUsable(), 410);

        return Inertia::render('Invitations/Register', ['token' => $token, 'email' => $invitation->email]);
    }

    public function store(Request $request, string $token)
    {
        $invitation = $this->find($token);
        abort_unless($invitation?->isUsable(), 410);
        $data = $request->validate(['name' => 'required|string|max:255', 'password' => ['required', 'confirmed', Rules\Password::defaults()]]);
        if (User::whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->exists()) {
            return redirect()->route('login')->with('status', __('Veuillez vous connecter pour accepter cette invitation.'));
        }
        $user = DB::transaction(function () use ($invitation, $data) {
            $user = User::create(['name' => $data['name'], 'email' => strtolower($invitation->email), 'password' => Hash::make($data['password']), 'preferred_language' => $invitation->preferred_language, 'email_verified_at' => now()]);
            $this->acceptInvitation($invitation, $user);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function accept(Request $request, string $token)
    {
        $invitation = $this->find($token);
        abort_unless($invitation?->isUsable(), 410);
        abort_unless(strcasecmp($request->user()->email, $invitation->email) === 0, 403);
        DB::transaction(fn () => $this->acceptInvitation($invitation, $request->user()));

        return redirect()->route('dashboard');
    }

    private function acceptInvitation(TeamInvitation $invitation, User $user): void
    {
        $invitation = TeamInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
        abort_unless($invitation->isUsable(), 410);
        $invitation->organization->users()->syncWithoutDetaching([$user->id => ['role' => $invitation->role, 'all_residences' => true]]);
        $invitation->update(['accepted_at' => now()]);
        $user->update(['current_organization_id' => $invitation->organization_id]);
        activity()->performedOn($invitation)->causedBy($user)->withProperties(['organization_id' => $invitation->organization_id])->log('invitation.accepted');
    }

    private function find(string $token): ?TeamInvitation
    {
        return TeamInvitation::with('organization')->where('token_hash', hash('sha256', $token))->first();
    }

    private function reason(?TeamInvitation $invitation): string
    {
        if (! $invitation) {
            return 'invalid';
        }
        if ($invitation->accepted_at) {
            return 'used';
        }
        if ($invitation->cancelled_at) {
            return 'cancelled';
        }

        return 'expired';
    }
}
