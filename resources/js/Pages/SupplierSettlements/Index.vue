<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import Pagination from "@/Components/Pagination.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
defineProps<{ settlements: any; filters: any }>();
</script>
<template>
    <AuthenticatedLayout
        title="Règlements fournisseurs"
        subtitle="Affectations et extournes"
        ><ExpenseNavigation />
        <div class="mb-4 flex justify-end">
            <Link
                :href="route('supplier-settlements.create')"
                class="btn-primary"
                >Nouveau règlement</Link
            >
        </div>
        <div class="panel divide-y">
            <Link
                v-for="s in settlements.data"
                :key="s.id"
                :href="route('supplier-settlements.show', s.id)"
                class="flex min-h-16 items-center justify-between p-4"
                ><span
                    ><b>{{ s.number || "Brouillon" }}</b
                    ><small class="block"
                        >{{ s.supplier?.legal_name }} ·
                        {{ s.amount_cents }} c</small
                    ></span
                ><FinancialStatusBadge :status="s.status"
            /></Link>
        </div>
        <Pagination :links="settlements.links"
    /></AuthenticatedLayout>
</template>
