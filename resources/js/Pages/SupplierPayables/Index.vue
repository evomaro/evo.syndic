<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import { formatMADCents as money } from "@/Support/money";
defineProps<{ payables: any[]; filters: any }>();
</script>
<template>
    <AuthenticatedLayout
        title="Dettes fournisseurs"
        subtitle="Solde par facture et ancienneté"
        ><ExpenseNavigation />
        <div class="panel overflow-x-auto">
            <table class="w-full min-w-[680px] text-sm">
                <thead>
                    <tr>
                        <th>Facture</th>
                        <th>Fournisseur</th>
                        <th>Échéance</th>
                        <th>Ancienneté</th>
                        <th>Solde</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in payables" :key="p.id">
                        <td>{{ p.number }}</td>
                        <td>{{ p.supplier }}</td>
                        <td>{{ p.due_date }}</td>
                        <td>{{ p.aging }}</td>
                        <td class="font-bold">
                            {{ money(p.outstanding_cents) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!payables.length" class="p-8 text-center text-slate-500">
                Aucune dette fournisseur.
            </p>
        </div></AuthenticatedLayout
    >
</template>
