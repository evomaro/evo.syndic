<script setup lang="ts">
import ExpenseCategoryPicker from "./ExpenseCategoryPicker.vue";
import MoneyInput from "./MoneyInput.vue";
import { useI18n } from "@/i18n";
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
defineProps<{ categories: any[]; exercises: any[]; residences?: any[] }>();
const lines = defineModel<any[]>({ required: true });
const add = () =>
    lines.value.push({
        residence_id: "",
        financial_exercise_id: "",
        expense_category_id: "",
        description: "",
        quantity: "1.000",
        unit_price_cents: 0,
        tax_rate: "0.000",
        visibility: "private",
    });
</script>
<template>
    <fieldset class="grid gap-3">
        <legend class="mb-2 font-bold">
            {{ text("Lignes de facture", "سطور الفاتورة") }}
        </legend>
        <article
            v-for="(line, index) in lines"
            :key="index"
            class="panel grid gap-3 p-4 lg:grid-cols-3"
        >
            <select
                v-if="residences?.length"
                v-model="line.residence_id"
                class="rounded-lg border-slate-300"
            >
                <option value="">
                    {{ text("Résidence active", "الإقامة النشطة") }}
                </option>
                <option v-for="r in residences" :key="r.id" :value="r.id">
                    {{ r.name }}
                </option></select
            ><select
                v-model="line.financial_exercise_id"
                class="rounded-lg border-slate-300"
            >
                <option value="">
                    {{ text("Exercice", "السنة المالية") }}
                </option>
                <option v-for="e in exercises" :key="e.id" :value="e.id">
                    {{ e.name }}
                </option></select
            ><ExpenseCategoryPicker
                v-model="line.expense_category_id"
                :categories="categories"
            /><input
                v-model="line.description"
                class="rounded-lg border-slate-300 lg:col-span-2"
                :placeholder="text('Description', 'الوصف')"
            /><input
                v-model="line.quantity"
                type="number"
                min="0.001"
                step="0.001"
                class="rounded-lg border-slate-300"
            /><MoneyInput v-model="line.unit_price_cents" /><input
                v-model="line.tax_rate"
                type="number"
                min="0"
                max="100"
                step="0.001"
                class="rounded-lg border-slate-300"
                :placeholder="text('TVA %', 'الضريبة %')"
            /><button
                v-if="lines.length > 1"
                type="button"
                class="btn-secondary"
                @click="lines.splice(index, 1)"
            >
                {{ text("Retirer", "إزالة") }}
            </button>
        </article>
        <button type="button" class="btn-secondary w-fit" @click="add">
            {{ text("Ajouter une ligne", "إضافة سطر") }}
        </button>
    </fieldset>
</template>
