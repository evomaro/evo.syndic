<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import SupplierPicker from "@/Components/Expenses/SupplierPicker.vue";
import ExpenseCategoryPicker from "@/Components/Expenses/ExpenseCategoryPicker.vue";
import MoneyInput from "@/Components/Expenses/MoneyInput.vue";
defineProps<{ exercises: any[]; categories: any[]; contracts: any[] }>();
const form = useForm<any>({
    financial_exercise_id: "",
    supplier_id: "",
    expense_category_id: "",
    supplier_contract_id: "",
    title: "",
    description: "",
    committed_on: new Date().toISOString().slice(0, 10),
    expected_invoice_date: "",
    amount_cents: 0,
});
</script>
<template>
    <AuthenticatedLayout
        title="Nouvel engagement"
        subtitle="Réservation budgétaire"
        ><ExpenseNavigation />
        <form
            class="panel grid max-w-3xl gap-4 p-5 md:grid-cols-2"
            @submit.prevent="form.post(route('expense-commitments.store'))"
        >
            <select
                v-model="form.financial_exercise_id"
                class="rounded-lg border-slate-300"
            >
                <option value="">Exercice</option>
                <option v-for="e in exercises" :key="e.id" :value="e.id">
                    {{ e.name }}
                </option></select
            ><SupplierPicker v-model="form.supplier_id" /><ExpenseCategoryPicker
                v-model="form.expense_category_id"
                :categories="categories"
            /><select
                v-model="form.supplier_contract_id"
                class="rounded-lg border-slate-300"
            >
                <option value="">Sans contrat</option>
                <option v-for="c in contracts" :key="c.id" :value="c.id">
                    {{ c.title }}
                </option></select
            ><input
                v-model="form.title"
                class="rounded-lg border-slate-300 md:col-span-2"
                placeholder="Titre"
            /><input
                v-model="form.committed_on"
                type="date"
                class="rounded-lg border-slate-300"
            /><MoneyInput v-model="form.amount_cents" /><button
                class="btn-primary md:col-span-2"
            >
                Enregistrer
            </button>
        </form></AuthenticatedLayout
    >
</template>
