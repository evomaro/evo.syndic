<script setup lang="ts">
import { computed } from "vue";
import MoneyInput from "./MoneyInput.vue";
defineProps<{ residences: any[] }>();
const allocations = defineModel<any[]>({ required: true });
const add = () => allocations.value.push({ residence_id: "", amount_cents: 0 });
const total = computed(() =>
    allocations.value.reduce(
        (sum, row) => sum + Number(row.amount_cents || 0),
        0,
    ),
);
</script>
<template>
    <div class="grid gap-2">
        <div
            v-for="(row, i) in allocations"
            :key="i"
            class="grid items-end gap-2 md:grid-cols-[1fr_14rem_auto]"
        >
            <select
                v-model="row.residence_id"
                class="rounded-lg border-slate-300"
            >
                <option v-for="r in residences" :key="r.id" :value="r.id">
                    {{ r.name }}
                </option></select
            ><MoneyInput
                v-model="row.amount_cents"
                label="Montant"
                :min="0.01"
            /><button
                type="button"
                class="btn-secondary"
                @click="allocations.splice(i, 1)"
            >
                Supprimer
            </button>
        </div>
        <div class="flex items-center justify-between border-t pt-3 text-sm">
            <span>Total</span
            ><b>{{
                (total / 100).toLocaleString("fr-MA", {
                    style: "currency",
                    currency: "MAD",
                })
            }}</b>
        </div>
        <button type="button" class="btn-secondary w-fit" @click="add">
            Ajouter une résidence
        </button>
    </div>
</template>
