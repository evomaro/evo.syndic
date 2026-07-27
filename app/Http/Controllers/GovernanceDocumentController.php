<?php

namespace App\Http\Controllers;

use App\Models\Assembly;
use App\Models\AssemblyMinuteVersion;
use App\Models\Convocation;
use App\Models\GovernanceDocument;
use App\Models\GovernanceDocumentVersion;
use App\Services\GovernanceDocumentService;
use App\Services\MinutesService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class GovernanceDocumentController extends Controller
{
    public function store(Request $r, Assembly $a, TenantContext $c, GovernanceDocumentService $s)
    {
        $this->scope($a, $c);
        $d = $r->validate(['category' => ['required', Rule::in(['financial_statements', 'general_management_account', 'proposed_budget', 'previous_budget', 'expense_evidence', 'supplier_contract', 'proposed_contract', 'quotation', 'major_work', 'resolution_project', 'other'])], 'title_fr' => 'required|string|max:255', 'title_ar' => 'nullable|string|max:255', 'audience' => ['required', Rule::in(['owners', 'internal'])], 'file' => 'required|file|max:'.config('governance.uploads.max_kilobytes').'|extensions:'.implode(',', config('governance.uploads.mimes')).'|mimes:'.implode(',', config('governance.uploads.mimes'))]);
        $document = GovernanceDocument::create(['organization_id' => $a->organization_id, 'residence_id' => $a->residence_id, 'assembly_id' => $a->id] + collect($d)->except('file')->all());
        $s->storeVersion($document, $r->file('file'), $r->user());

        return back();
    }

    public function version(Request $r, GovernanceDocument $document, TenantContext $c, GovernanceDocumentService $s)
    {
        $this->scope($document->assembly, $c);
        $r->validate(['file' => 'required|file|max:'.config('governance.uploads.max_kilobytes').'|extensions:'.implode(',', config('governance.uploads.mimes')).'|mimes:'.implode(',', config('governance.uploads.mimes'))]);
        $s->storeVersion($document, $r->file('file'), $r->user());

        return back();
    }

    public function publish(Request $r, GovernanceDocument $document, GovernanceDocumentVersion $version, TenantContext $c, GovernanceDocumentService $s)
    {
        $this->scope($document->assembly, $c);
        $s->publish($document, $version, $r->user());

        return back();
    }

    public function archive(Request $r, GovernanceDocument $document, TenantContext $c, GovernanceDocumentService $s)
    {
        $this->scope($document->assembly, $c);
        $d = $r->validate(['reason' => 'required|string|min:10|max:2000']);
        $s->archive($document, $r->user(), $d['reason']);

        return back();
    }

    public function download(Request $r, GovernanceDocumentVersion $version, TenantContext $c, GovernanceDocumentService $s)
    {
        $document = $version->document()->with('assembly')->firstOrFail();
        $this->scope($document->assembly, $c);
        $staff = $r->user()->canInOrganization('download_internal_governance_documents', $c->organization());
        if ($document->audience === 'internal' && ! $staff) {
            abort(404);
        }

return $s->download($version, $r->user(), $staff);
    }

    public function convocation(Request $r, Convocation $convocation, TenantContext $c)
    {
        $this->scope($convocation->assembly, $c);
        abort_unless($r->user()->canInOrganization('view_assemblies', $c->organization()), 403);
        $disk = Storage::disk($convocation->disk);
        abort_unless($disk->exists($convocation->path) && hash_equals($convocation->checksum, hash('sha256', $disk->get($convocation->path))), 409);

        return $disk->download($convocation->path, 'convocation-'.$convocation->version.'.pdf', ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function minutes(Request $r, AssemblyMinuteVersion $version, TenantContext $c, MinutesService $s)
    {
        $this->scope($version->minutes->assembly, $c);
        abort_unless($r->user()->canInOrganization('view_assemblies', $c->organization()) && $version->status === 'signed', 404);

        return $s->download($version);
    }

    private function scope(Assembly $a,TenantContext $c): void
    {
        abort_unless($a->organization_id === $c->organization()->id && $a->residence_id === $c->residence()->id,404);
    }
}
