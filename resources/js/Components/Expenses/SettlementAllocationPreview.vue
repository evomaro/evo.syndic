<script setup lang="ts">
defineProps<{ allocations: any[] }>();
const money = (cents: number) =>
    new Intl.NumberFormat("fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(Number(cents || 0) / 100);
</script>
<template>
    <div class="grid gap-2">
        <p v-if="!allocations.length" class="text-sm text-slate-500">
            L’affectation FIFO sera calculée et verrouillée sur le serveur.
        </p>
        <div
            v-for="row in allocations"
            :key="row.supplier_invoice_id"
            class="flex justify-between rounded bg-slate-50 p-3"
        >
            <span
                ><b>{{
                    row.invoice?.number ||
                    row.invoice?.supplier_invoice_number ||
                    `Facture #${row.supplier_invoice_id}`
                }}</b
                ><small
                    v-if="row.line?.description"
                    class="mt-1 block text-slate-500"
                    >{{ row.line.description }}</small
                ></span
            ><b>{{ money(row.amount_cents) }}</b>
        </div>
        <div
            v-if="allocations.length"
            class="flex justify-between border-t pt-3"
        >
            <span>Total affecté</span
            ><b>{{
                money(
                    allocations.reduce(
                        (sum, row) => sum + Number(row.amount_cents || 0),
                        0,
                    ),
                )
            }}</b>
        </div>
    </div>
</template>
