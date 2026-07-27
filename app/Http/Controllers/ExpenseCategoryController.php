<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expenses\ExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use App\Support\TenantContext;
use Inertia\Inertia;

class ExpenseCategoryController extends Controller
{
    public function index(TenantContext $context)
    {
        return Inertia::render('ExpenseCategories/Index', ['categories' => ExpenseCategory::query()->where('residence_id', $context->residence()->id)->orderBy('sort_order')->orderBy('name')->paginate(30)]);
    }

    public function store(ExpenseCategoryRequest $request, TenantContext $context)
    {
        ExpenseCategory::create($request->validated() + ['organization_id' => $context->organization()->id, 'residence_id' => $context->residence()->id]);

        return back()->with('success', __('Catégorie créée.'));
    }

    public function seed(TenantContext $context)
    {
        foreach (['Nettoyage', 'Gardiennage', 'Eau', 'Électricité', 'Ascenseur', 'Assurance', 'Jardinage', 'Entretien et réparations', 'Honoraires', 'Frais bancaires', 'Fournitures', 'Travaux exceptionnels', 'Taxes et redevances', 'Autres charges'] as $index => $name) {
            ExpenseCategory::firstOrCreate(['residence_id' => $context->residence()->id, 'code' => 'EXP-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)], ['organization_id' => $context->organization()->id, 'name' => $name, 'type' => $name === 'Travaux exceptionnels' ? 'exceptional' : 'ordinary', 'default_visibility' => 'private', 'sort_order' => $index]);
        }

        return back()->with('success', __('Catégories par défaut ajoutées.'));
    }
}
