<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
import { formatMADCents as money } from "@/Support/money";
const props = defineProps<{ statement: any }>();
const { locale } = useI18n();
</script>
<template>
    <AuthenticatedLayout
        :title="`Relevé · ${statement.supplier.legal_name}`"
        subtitle="Factures, avoirs, règlements et extournes"
        ><template #actions
            ><div class="flex gap-2">
                <a
                    :href="
                        route('suppliers.statement', {
                            supplier: statement.supplier.id,
                            csv: 1,
                        })
                    "
                    class="btn-secondary"
                    >CSV</a
                ><a
                    :href="
                        route('suppliers.statement', {
                            supplier: statement.supplier.id,
                            pdf: 1,
                        })
                    "
                    class="btn-primary"
                    >PDF</a
                >
            </div></template
        >
        <div class="panel overflow-x-auto">
            <table class="w-full min-w-[760px] text-sm">
                <thead>
                    <tr>
                        <th class="p-4 text-start">Date</th>
                        <th>Type</th>
                        <th>Numéro</th>
                        <th>Libellé</th>
                        <th>Débit</th>
                        <th>Crédit</th>
                        <th>Solde</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-t bg-slate-50 font-bold">
                        <td class="p-4" colspan="6">Solde d’ouverture</td>
                        <td>{{ money(statement.opening_cents) }}</td>
                    </tr>
                    <tr v-for="r in statement.rows" class="border-t">
                        <td class="p-4">{{ r.date }}</td>
                        <td>{{ r.type }}</td>
                        <td>{{ r.number }}</td>
                        <td>{{ r.label }}</td>
                        <td>{{ money(r.debit_cents) }}</td>
                        <td>{{ money(r.credit_cents) }}</td>
                        <td class="font-bold">{{ money(r.balance_cents) }}</td>
                    </tr>
                    <tr class="border-t bg-slate-950 font-bold text-white">
                        <td class="p-4" colspan="6">Solde de clôture</td>
                        <td>{{ money(statement.closing_cents) }}</td>
                    </tr>
                </tbody>
            </table>
        </div></AuthenticatedLayout
    >
</template>
