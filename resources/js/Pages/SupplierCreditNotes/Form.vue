<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import SupplierPicker from "@/Components/Expenses/SupplierPicker.vue";
import MoneyInput from "@/Components/Expenses/MoneyInput.vue";
const form = useForm<any>({
    supplier_id: "",
    supplier_credit_number: "",
    credit_date: new Date().toISOString().slice(0, 10),
    amount_cents: 0,
    reason: "",
    idempotency_key: crypto.randomUUID(),
});
</script>
<template>
    <AuthenticatedLayout
        title="Nouvel avoir"
        subtitle="Affectation après création"
        ><ExpenseNavigation />
        <form
            class="panel grid max-w-3xl gap-4 p-5 md:grid-cols-2"
            @submit.prevent="form.post(route('supplier-credit-notes.store'))"
        >
            <SupplierPicker v-model="form.supplier_id" /><input
                v-model="form.supplier_credit_number"
                class="rounded-lg border-slate-300"
                placeholder="Numéro fournisseur"
            /><input
                v-model="form.credit_date"
                type="date"
                class="rounded-lg border-slate-300"
            /><MoneyInput v-model="form.amount_cents" /><textarea
                v-model="form.reason"
                class="rounded-lg border-slate-300 md:col-span-2"
                placeholder="Motif"
            /><button class="btn-primary md:col-span-2">Enregistrer</button>
        </form></AuthenticatedLayout
    >
</template>
