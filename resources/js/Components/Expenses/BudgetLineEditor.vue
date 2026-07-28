<script setup lang="ts">
import { computed } from "vue";
import { formatMADCents as money } from "@/Support/money";
import ExpenseCategoryPicker from "./ExpenseCategoryPicker.vue";
import MoneyInput from "./MoneyInput.vue";
defineProps<{ categories: any[] }>();
const lines = defineModel<any[]>({ required: true });
const add = () =>
    lines.value.push({
        expense_category_id: "",
        planned_cents: 0,
        description: "",
    });
const total = computed(() =>
    lines.value.reduce((sum, line) => sum + Number(line.planned_cents || 0), 0),
);
</script>
<template>
    <div class="grid gap-3">
        <div
            v-for="(line, i) in lines"
            :key="i"
            class="panel grid items-end gap-2 p-4 md:grid-cols-[1fr_14rem_1fr_auto]"
        >
            <ExpenseCategoryPicker
                v-model="line.expense_category_id"
                :categories="categories"
            /><MoneyInput v-model="line.planned_cents" /><input
                v-model="line.description"
                class="rounded-lg border-slate-300"
                placeholder="Description"
            /><button
                type="button"
                class="btn-secondary"
                aria-label="Supprimer la ligne"
                @click="lines.splice(i, 1)"
            >
                Supprimer
            </button>
        </div>
        <div class="flex justify-between rounded-lg bg-slate-50 p-3">
            <span>Total planifié</span><b>{{ money(total) }}</b>
        </div>
        <button type="button" class="btn-secondary w-fit" @click="add">
            Ajouter une ligne
        </button>
    </div>
</template>
