<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { ref } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import Pagination from "@/Components/Pagination.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
import { useI18n } from "@/i18n";
const props = defineProps<{ invoices: any; filters: any }>();
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const q = ref(props.filters.q || "");
const status = ref(props.filters.status || "");
const date = (value?: string) =>
    value ? new Date(value).toLocaleDateString("fr-MA") : "—";
const search = () =>
    router.get(
        route("supplier-invoices.index"),
        { q: q.value, status: status.value },
        { preserveState: true, replace: true },
    );
</script>
<template>
    <AuthenticatedLayout
        :title="text('Factures fournisseurs', 'فواتير الموردين')"
        :subtitle="
            text(
                'Recherche, validation et échéances',
                'البحث والاعتماد والاستحقاقات',
            )
        "
        ><ExpenseNavigation />
        <div class="mb-4 flex flex-wrap gap-2">
            <input
                v-model="q"
                class="min-h-11 flex-1 rounded-lg border-slate-300"
                :placeholder="text('Numéro ou fournisseur', 'رقم أو مورد')"
                @keyup.enter="search"
            /><select
                v-model="status"
                class="min-h-11 rounded-lg border-slate-300"
                :aria-label="text('Statut', 'الحالة')"
            >
                <option value="">
                    {{ text("Tous les statuts", "كل الحالات") }}
                </option>
                <option value="draft">{{ text("Brouillon", "مسودة") }}</option>
                <option value="validated">
                    {{ text("Validée", "معتمدة") }}
                </option>
                <option value="partial">
                    {{ text("Partielle", "جزئية") }}
                </option>
                <option value="paid">{{ text("Payée", "مدفوعة") }}</option>
                <option value="cancelled">
                    {{ text("Annulée", "ملغاة") }}
                </option></select
            ><button class="btn-secondary" @click="search">
                {{ text("Rechercher", "بحث") }}</button
            ><Link
                :href="route('supplier-invoices.create')"
                class="btn-primary"
                >{{ text("Saisir", "إدخال") }}</Link
            >
        </div>
        <div class="panel divide-y">
            <Link
                v-for="i in invoices.data"
                :key="i.id"
                :href="route('supplier-invoices.show', i.id)"
                class="flex min-h-16 items-center justify-between p-4"
                ><span
                    ><b>{{
                        i.number || i.supplier_invoice_number || "Brouillon"
                    }}</b
                    ><small class="block"
                        >{{ i.supplier?.legal_name }} ·
                        {{ date(i.due_date) }}</small
                    ></span
                ><FinancialStatusBadge :status="i.status"
            /></Link>
            <p
                v-if="!invoices.data.length"
                class="p-8 text-center text-slate-500"
            >
                {{ text("Aucune facture.", "لا توجد فواتير.") }}
            </p>
        </div>
        <Pagination :links="invoices.links"
    /></AuthenticatedLayout>
</template>
