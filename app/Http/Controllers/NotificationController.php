<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NotificationController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $preference = NotificationPreference::firstOrCreate(['user_id' => $request->user()->id, 'organization_id' => $context->organization()->id]);
        $scope = fn ($query) => $query->where('data->organization_id', $context->organization()->id)->where('data->residence_id', $context->residence()->id);

        return Inertia::render('Portal/Notifications', ['notifications' => $scope($request->user()->notifications())->paginate(30), 'unreadCount' => $scope($request->user()->unreadNotifications())->count(), 'preference' => $preference]);
    }

    public function read(Request $request, string $notification, TenantContext $context)
    {
        $item = $request->user()->notifications()->whereKey($notification)->where('data->organization_id', $context->organization()->id)->where('data->residence_id', $context->residence()->id)->firstOrFail();
        $item->markAsRead();

        return back();
    }

    public function readAll(Request $request, TenantContext $context)
    {
        $request->user()->unreadNotifications()->where('data->organization_id', $context->organization()->id)->where('data->residence_id', $context->residence()->id)->update(['read_at' => now()]);

        return back()->with('success', __('Notifications marquées comme lues.'));
    }

    public function preferences(Request $request, TenantContext $context)
    {
        $data = $request->validate(['database_enabled' => ['required', 'boolean'], 'email_enabled' => ['required', 'boolean'], 'muted_events' => ['array'], 'muted_events.*' => ['string', 'max:100']]);
        $preference = NotificationPreference::updateOrCreate(['user_id' => $request->user()->id, 'organization_id' => $context->organization()->id], $data);
        activity()->performedOn($preference)->causedBy($request->user())->withProperties(['organization_id' => $context->organization()->id, 'channels' => ['database' => $preference->database_enabled, 'email' => $preference->email_enabled], 'muted_events' => $preference->muted_events])->log('notification_preferences.updated');

        return back()->with('success', __('Préférences enregistrées.'));
    }
}
