<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use App\Models\User;
use App\Services\ContactUserLinkService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ContactController extends Controller
{
    public function index(Request $r, TenantContext $c)
    {
        $contacts = $c->organization()->contacts()->when($r->search, fn ($q, $v) => $q->where(fn ($x) => $x->where('first_name', 'like', "%$v%")->orWhere('last_name', 'like', "%$v%")->orWhere('company_name', 'like', "%$v%")->orWhere('primary_phone', 'like', "%$v%")))->when($r->type, fn ($q, $v) => $q->where('type', $v))->paginate(20)->withQueryString();

        return Inertia::render('Contacts/Index', ['contacts' => $contacts, 'filters' => $r->only(['search', 'type'])]);
    }

    public function store(ContactRequest $r, TenantContext $c)
    {
        $data = $r->validated();
        $normalized = isset($data['primary_phone']) ? preg_replace('/[^0-9+]/', '', $data['primary_phone']) : null;
        $duplicates = $c->organization()->contacts()->where(function ($q) use ($data, $normalized) {
            foreach (['primary_email', 'cin', 'ice'] as $field) {
                if (! empty($data[$field])) {
                    $q->orWhere($field, $data[$field]);
                }
            }if ($normalized) {
                $q->orWhere('phone_normalized', $normalized);
            }
        })->get(['id', 'first_name', 'last_name', 'company_name']);
        if ($duplicates->isNotEmpty() && ! $r->boolean('confirm_duplicate')) {
            return back()->withErrors(['duplicate' => __('Possible duplicate found. Confirm to continue.')])->with('duplicates', $duplicates);
        }$c->organization()->contacts()->create($data);

        return back()->with('success', __('Contact created.'));
    }

    public function show(Contact $contact, TenantContext $c)
    {
        $this->guard($contact, $c);

        return Inertia::render('Contacts/Show', ['contact' => $contact->load(['ownerships.lot.residence:id,name', 'occupancies.lot.residence:id,name'])]);
    }

    public function update(ContactRequest $r, Contact $contact, TenantContext $c)
    {
        $this->guard($contact, $c);
        abort_unless($contact->active, 409);
        $contact->update($r->validated());

        return back()->with('success', __('Contact updated.'));
    }

    public function linkUser(Contact $contact, User $user, TenantContext $context, ContactUserLinkService $links)
    {
        $this->guard($contact, $context);
        $links->link($contact, $user, request()->user(), $context->organization());

        return back()->with('success', __('Accès financier lié.'));
    }

    public function unlinkUser(Contact $contact, User $user, TenantContext $context, ContactUserLinkService $links)
    {
        $this->guard($contact, $context);
        $links->revoke($contact, $user, request()->user(), $context->organization());

        return back()->with('success', __('Accès financier révoqué.'));
    }

    private function guard(Contact $contact, TenantContext $c)
    {
        abort_unless($contact->organization_id === $c->organization()->id, 404);
    }
}
