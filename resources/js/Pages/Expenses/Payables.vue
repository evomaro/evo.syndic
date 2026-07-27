<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { router } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";
const props = defineProps<{
    rows: any[];
    totals: any;
    suppliers: any[];
    filters: any;
}>();
const { locale } = useI18n();
const money = (c: number) =>
    new Intl.NumberFormat(locale.value === "ar" ? "ar-MA" : "fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(c / 100);
const filter = (e: Event) =>
    router.get(
        route("supplier-payables.index"),
        { supplier_id: (e.target as HTMLSelectElement).value },
        { preserveState: true },
    );
</script>
<template>
    <AuthenticatedLayout
        title="Échéancier fournisseurs"
        subtitle="Soldes actifs et ancienneté des dettes"
        ><template #actions
            ><a
                :href="route('supplier-payables.index', { ...filters, csv: 1 })"
                class="btn-secondary"
                >CSV</a
            ></template
        >
        <div class="mb-4 flex items-center gap-3">
            <select :value="filters.supplier_id || ''" @change="filter">
                <option value="">Tous les fournisseurs</option>
                <option v-for="s in suppliers" :value="s.id">
                    {{ s.legal_name }}
                </option></select
            ><b>Total {{ money(totals.outstanding_cents) }}</b>
        </div>
        <div class="panel overflow-x-auto">
            <table class="w-full min-w-[760px] text-sm">
                <thead>
                    <tr>
                        <th class="p-4 text-start">Numéro</th>
                        <th>Fournisseur</th>
                        <th>Échéance</th>
                        <th>Total</th>
                        <th>Réglé</th>
                        <th>Avoir</th>
                        <th>Solde</th>
                        <th>Ancienneté</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" class="border-t">
                        <td class="p-4">{{ r.number }}</td>
                        <td>{{ r.supplier }}</td>
                        <td>{{ r.due_date }}</td>
                        <td>{{ money(r.total_cents) }}</td>
                        <td>{{ money(r.paid_cents) }}</td>
                        <td>{{ money(r.credited_cents) }}</td>
                        <td class="font-bold">
                            {{ money(r.outstanding_cents) }}
                        </td>
                        <td>
                            <span class="badge">{{ r.aging }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!rows.length" class="p-8 text-center text-slate-500">
                Aucune dette fournisseur.
            </p>
        </div></AuthenticatedLayout
    >
</template>
