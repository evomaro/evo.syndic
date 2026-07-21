<?php

namespace App\Http\Controllers;

use App\Services\ActivityScope;
use App\Support\TenantContext;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class ActivityController extends Controller
{
    public function __invoke(TenantContext $c, ActivityScope $activityScope)
    {
        $org = $c->organization();
        $activities = $activityScope->apply(Activity::query(), $org)->latest()->paginate(30);

        return Inertia::render('Activity/Index', ['activities' => $activities]);
    }
}
