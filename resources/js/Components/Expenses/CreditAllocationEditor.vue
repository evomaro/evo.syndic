<script setup lang="ts">
import { computed } from "vue";
import MoneyInput from "./MoneyInput.vue";
const props = defineProps<{ invoices: any[]; creditAmount: number }>();
const allocations = defineModel<any[]>({ required: true });
const add = () =>
    allocations.value.push({ supplier_invoice_id: "", amount_cents: 0 });
const total = computed(() =>
    allocations.value.reduce(
        (sum, row) => sum + Number(row.amount_cents || 0),
        0,
    ),
);
const money = (cents: number) =>
    new Intl.NumberFormat("fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(cents / 100);
</script>
<template>
    <div class="grid gap-2">
        <p
            v-if="!invoices.length"
            class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900"
        >
            Aucune facture validée avec un solde disponible.
        </p>
        <div
            v-for="(row, i) in allocations"
            :key="i"
            class="grid items-end gap-2 rounded-lg bg-slate-50 p-3 md:grid-cols-[1fr_14rem_auto]"
        >
            <label class="grid gap-1 text-sm"
                >Facture
                <select
                    v-model="row.supplier_invoice_id"
                    class="rounded-lg border-slate-300"
                >
                    <option value="">Sélectionner</option>
                    <option
                        v-for="invoice in invoices"
                        :key="invoice.id"
                        :value="invoice.id"
                    >
                        {{ invoice.number || invoice.supplier_invoice_number }}
                        · {{ money(invoice.outstanding_cents) }}
                    </option>
                </select>
            </label>
            <MoneyInput
                v-model="row.amount_cents"
                label="Montant affecté"
                :min="0.01"
            />
            <button
                type="button"
                class="btn-secondary"
                aria-label="Supprimer l’affectation"
                @click="allocations.splice(i, 1)"
            >
                Supprimer
            </button>
        </div>
        <div class="flex flex-wrap justify-between gap-2 border-t pt-3 text-sm">
            <span
                >Total affecté <b>{{ money(total) }}</b></span
            ><span
                :class="
                    total === creditAmount
                        ? 'text-emerald-700'
                        : 'text-amber-700'
                "
                >Reste <b>{{ money(creditAmount - total) }}</b></span
            >
        </div>
        <button
            type="button"
            class="btn-secondary w-fit"
            :disabled="!invoices.length"
            @click="add"
        >
            Ajouter une affectation
        </button>
    </div>
</template>
