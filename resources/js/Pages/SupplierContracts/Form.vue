<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import SupplierPicker from "@/Components/Expenses/SupplierPicker.vue";
import MoneyInput from "@/Components/Expenses/MoneyInput.vue";
defineProps<{ serviceCategories: any[]; expenseCategories: any[] }>();
const form = useForm<any>({
    supplier_id: "",
    supplier_service_category_id: "",
    expense_category_id: "",
    reference: "",
    title: "",
    starts_on: "",
    ends_on: "",
    amount_cents: 0,
    billing_frequency: "monthly",
    renewal_type: "none",
    notice_days: 30,
    auto_renew: false,
});
</script>
<template>
    <AuthenticatedLayout
        title="Nouveau contrat"
        subtitle="Configuration du renouvellement"
        ><ExpenseNavigation />
        <form
            class="panel grid max-w-3xl gap-4 p-5 md:grid-cols-2"
            @submit.prevent="form.post(route('supplier-contracts.store'))"
        >
            <SupplierPicker v-model="form.supplier_id" /><input
                v-model="form.reference"
                class="rounded-lg border-slate-300"
                placeholder="Référence"
            /><input
                v-model="form.title"
                class="rounded-lg border-slate-300 md:col-span-2"
                placeholder="Titre"
            /><input
                v-model="form.starts_on"
                type="date"
                class="rounded-lg border-slate-300"
            /><input
                v-model="form.ends_on"
                type="date"
                class="rounded-lg border-slate-300"
            /><MoneyInput v-model="form.amount_cents" /><select
                v-model="form.billing_frequency"
                class="rounded-lg border-slate-300"
            >
                <option value="monthly">Mensuel</option>
                <option value="quarterly">Trimestriel</option>
                <option value="yearly">Annuel</option>
                <option value="one_off">Ponctuel</option></select
            ><select
                v-model="form.expense_category_id"
                class="rounded-lg border-slate-300"
            >
                <option value="">Catégorie de dépense</option>
                <option
                    v-for="category in expenseCategories"
                    :key="category.id"
                    :value="category.id"
                >
                    {{ category.name }}
                </option></select
            ><select
                v-model="form.renewal_type"
                class="rounded-lg border-slate-300"
            >
                <option value="none">Sans renouvellement</option>
                <option value="manual">Manuel</option>
                <option value="automatic">Automatique</option></select
            ><input
                v-model.number="form.notice_days"
                type="number"
                min="0"
                class="rounded-lg border-slate-300"
            /><button
                class="btn-primary md:col-span-2"
                :disabled="form.processing"
            >
                Enregistrer
            </button>
        </form></AuthenticatedLayout
    >
</template>
