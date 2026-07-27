<?php

namespace App\Http\Controllers;

use App\Models\ResidenceAnnouncement;
use App\Models\ResidenceDocument;
use App\Models\SupplierInvoiceLine;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ResidentPortalController extends Controller
{
    public function index(TenantContext $context)
    {
        $user = request()->user();
        $residence = $context->residence();
        $lotIds = $this->currentLotIds($user->id, $residence->id);
        $contactIds = $this->currentContactIds($user->id, $context->organization()->id);
        $buildingIds = $residence->lots()->whereIn('id', $lotIds)->pluck('building_id')->filter();
        $documents = $this->visibleDocuments($residence->id, $lotIds, $buildingIds, $contactIds)->with('publishedVersion')->latest('published_at')->take(12)->get();
        $announcements = $this->visibleAnnouncements($residence->id, $lotIds, $buildingIds, $contactIds)->latest('published_at')->take(12)->get()->map(function (ResidenceAnnouncement $announcement) use ($user) {
            $arabic = $user->preferred_language === 'ar';

            return [
                'id' => $announcement->id,
                'title' => $arabic ? ($announcement->title_ar ?: $announcement->title_fr ?: $announcement->title) : ($announcement->title_fr ?: $announcement->title_ar ?: $announcement->title),
                'body' => $arabic ? ($announcement->body_ar ?: $announcement->body_fr ?: $announcement->body) : ($announcement->body_fr ?: $announcement->body_ar ?: $announcement->body),
                'priority' => $announcement->priority,
                'published_at' => $announcement->published_at,
            ];
        });
        $expenses = SupplierInvoiceLine::query()->join('supplier_invoices', 'supplier_invoices.id', '=', 'supplier_invoice_lines.supplier_invoice_id')->join('expense_categories', 'expense_categories.id', '=', 'supplier_invoice_lines.expense_category_id')->where('supplier_invoice_lines.residence_id', $residence->id)->whereIn('supplier_invoice_lines.visibility', ['category_summary', 'invoice_summary'])->whereIn('supplier_invoices.status', ['validated', 'partial', 'paid'])->groupBy('expense_categories.id', 'expense_categories.name')->selectRaw('expense_categories.name category, SUM(supplier_invoice_lines.total_cents) total_cents')->orderByDesc('total_cents')->get()
            ->map(fn ($expense) => ['category' => $expense->category, 'total_cents' => (int) $expense->total_cents]);
        $expenseInvoices = SupplierInvoiceLine::query()->join('supplier_invoices', 'supplier_invoices.id', '=', 'supplier_invoice_lines.supplier_invoice_id')->join('expense_categories', 'expense_categories.id', '=', 'supplier_invoice_lines.expense_category_id')
            ->where('supplier_invoice_lines.residence_id', $residence->id)->where('supplier_invoice_lines.visibility', 'invoice_summary')->whereIn('supplier_invoices.status', ['validated', 'partial', 'paid'])
            ->orderByDesc('supplier_invoices.invoice_date')->limit(50)
            ->get(['supplier_invoice_lines.id', 'supplier_invoice_lines.description as public_description', 'expense_categories.name as category', 'supplier_invoices.invoice_date', 'supplier_invoice_lines.total_cents'])
            ->map(fn ($invoice) => [
                'id' => $invoice->id,
                'public_description' => $invoice->public_description,
                'category' => $invoice->category,
                'invoice_date' => $invoice->invoice_date,
                'total_cents' => (int) $invoice->total_cents,
            ]);

        return Inertia::render('Portal/Home', ['lots' => $residence->lots()->whereIn('id', $lotIds)->get(['id', 'reference']), 'documents' => $documents, 'announcements' => $announcements, 'expenses' => $expenses, 'expenseInvoices' => $expenseInvoices, 'unreadNotifications' => $user->unreadNotifications()->where('data->organization_id', $context->organization()->id)->where('data->residence_id', $residence->id)->count()]);
    }

    public function documents(Request $request, TenantContext $context)
    {
        $residence = $context->residence();
        $lotIds = $this->currentLotIds($request->user()->id, $residence->id);
        $contactIds = $this->currentContactIds($request->user()->id, $context->organization()->id);
        $buildingIds = $residence->lots()->whereIn('id', $lotIds)->pluck('building_id')->filter();
        $documents = $this->visibleDocuments($residence->id, $lotIds, $buildingIds, $contactIds)
            ->with('publishedVersion')
            ->latest('published_at')
            ->paginate(30);

        return Inertia::render('Portal/ResidentDocuments', ['documents' => $documents]);
    }

    public function announcements(Request $request, TenantContext $context)
    {
        $residence = $context->residence();
        $lotIds = $this->currentLotIds($request->user()->id, $residence->id);
        $contactIds = $this->currentContactIds($request->user()->id, $context->organization()->id);
        $buildingIds = $residence->lots()->whereIn('id', $lotIds)->pluck('building_id')->filter();
        $arabic = $request->user()->preferred_language === 'ar';
        $announcements = $this->visibleAnnouncements($residence->id, $lotIds, $buildingIds, $contactIds)
            ->latest('published_at')
            ->paginate(30)
            ->through(fn (ResidenceAnnouncement $announcement) => [
                'id' => $announcement->id,
                'title' => $arabic ? ($announcement->title_ar ?: $announcement->title_fr ?: $announcement->title) : ($announcement->title_fr ?: $announcement->title_ar ?: $announcement->title),
                'body' => $arabic ? ($announcement->body_ar ?: $announcement->body_fr ?: $announcement->body) : ($announcement->body_fr ?: $announcement->body_ar ?: $announcement->body),
                'priority' => $announcement->priority,
                'published_at' => $announcement->published_at,
            ]);

        return Inertia::render('Portal/ResidentAnnouncements', ['announcements' => $announcements]);
    }

    private function currentLotIds(int $userId, int $residenceId)
    {
        return DB::table('lot_ownerships')
            ->join('contact_user', 'contact_user.contact_id', '=', 'lot_ownerships.contact_id')
            ->join('lots', 'lots.id', '=', 'lot_ownerships.lot_id')
            ->where('contact_user.user_id', $userId)
            ->whereNull('contact_user.revoked_at')
            ->where('lots.residence_id', $residenceId)
            ->whereDate('lot_ownerships.starts_on', '<=', today())
            ->where(fn ($query) => $query->whereNull('lot_ownerships.ends_on')->orWhereDate('lot_ownerships.ends_on', '>=', today()))
            ->pluck('lots.id');
    }

    private function currentContactIds(int $userId, int $organizationId)
    {
        return DB::table('contact_user')->where('user_id', $userId)->where('organization_id', $organizationId)->whereNull('revoked_at')->pluck('contact_id');
    }

    private function visibleDocuments(int $residenceId, $lotIds, $buildingIds, $contactIds)
    {
        return ResidenceDocument::query()->where('residence_id', $residenceId)->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query
                ->where('audience', 'all_residents')
                ->orWhere(fn ($scope) => $scope->where('audience', 'selected_lots')->whereHas('lots', fn ($lots) => $lots->whereIn('lots.id', $lotIds)))
                ->orWhere(fn ($scope) => $scope->where('audience', 'selected_buildings')->whereHas('buildings', fn ($buildings) => $buildings->whereIn('buildings.id', $buildingIds)))
                ->orWhere(fn ($scope) => $scope->where('audience', 'selected_contacts')->whereHas('contacts', fn ($contacts) => $contacts->whereIn('contacts.id', $contactIds))));
    }

    private function visibleAnnouncements(int $residenceId, $lotIds, $buildingIds, $contactIds)
    {
        return ResidenceAnnouncement::query()->where('residence_id', $residenceId)->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query
                ->where('audience', 'all_residents')
                ->orWhere(fn ($scope) => $scope->where('audience', 'selected_lots')->whereHas('lots', fn ($lots) => $lots->whereIn('lots.id', $lotIds)))
                ->orWhere(fn ($scope) => $scope->where('audience', 'selected_buildings')->whereHas('buildings', fn ($buildings) => $buildings->whereIn('buildings.id', $buildingIds)))
                ->orWhere(fn ($scope) => $scope->where('audience', 'selected_contacts')->whereHas('contacts', fn ($contacts) => $contacts->whereIn('contacts.id', $contactIds))));
    }
}
