<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import SupplierPicker from "@/Components/Expenses/SupplierPicker.vue";
import MoneyInput from "@/Components/Expenses/MoneyInput.vue";
import { useI18n } from "@/i18n";
defineProps<{ exercises: any[]; accounts: any[] }>();
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const form = useForm<any>({
    financial_exercise_id: "",
    supplier_id: "",
    financial_account_id: "",
    settlement_date: new Date().toISOString().slice(0, 10),
    amount_cents: 0,
    method: "bank_transfer",
    reference: "",
    idempotency_key: crypto.randomUUID(),
});
</script>
<template>
    <AuthenticatedLayout
        :title="text('Nouveau règlement', 'تسديد جديد')"
        :subtitle="
            text(
                'La saisie reste modifiable jusqu’à validation',
                'يبقى الإدخال قابلاً للتعديل حتى الاعتماد',
            )
        "
        ><ExpenseNavigation />
        <form
            class="panel grid max-w-3xl gap-4 p-5 md:grid-cols-2"
            @submit.prevent="form.post(route('supplier-settlements.store'))"
        >
            <SupplierPicker v-model="form.supplier_id" /><select
                v-model="form.financial_exercise_id"
                class="rounded-lg border-slate-300"
            >
                <option value="">
                    {{ text("Exercice", "السنة المالية") }}
                </option>
                <option v-for="e in exercises" :key="e.id" :value="e.id">
                    {{ e.name }}
                </option></select
            ><select
                v-model="form.financial_account_id"
                class="rounded-lg border-slate-300"
            >
                <option value="">{{ text("Compte", "الحساب") }}</option>
                <option v-for="a in accounts" :key="a.id" :value="a.id">
                    {{ a.name }}
                </option></select
            ><input
                v-model="form.settlement_date"
                type="date"
                class="rounded-lg border-slate-300"
            /><MoneyInput v-model="form.amount_cents" /><input
                v-model="form.reference"
                class="rounded-lg border-slate-300"
                :placeholder="text('Référence', 'المرجع')"
            /><button class="btn-primary md:col-span-2">
                {{ text("Enregistrer", "حفظ") }}
            </button>
        </form></AuthenticatedLayout
    >
</template>
