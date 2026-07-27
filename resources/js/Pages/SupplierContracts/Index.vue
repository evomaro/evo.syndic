<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import Pagination from "@/Components/Pagination.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
defineProps<{ contracts: any; filters: any }>();
</script>
<template>
    <AuthenticatedLayout
        title="Contrats fournisseurs"
        subtitle="Périodes historiques et renouvellements"
        ><ExpenseNavigation />
        <div class="mb-4 flex justify-end">
            <Link :href="route('supplier-contracts.create')" class="btn-primary"
                >Nouveau contrat</Link
            >
        </div>
        <div class="panel divide-y">
            <Link
                v-for="c in contracts.data"
                :key="c.id"
                :href="route('supplier-contracts.show', c.id)"
                class="flex min-h-16 items-center justify-between p-4"
                ><span
                    ><b>{{ c.title }}</b
                    ><small class="block">{{
                        c.supplier?.legal_name
                    }}</small></span
                ><FinancialStatusBadge :status="c.status"
            /></Link>
        </div>
        <Pagination :links="contracts.links"
    /></AuthenticatedLayout>
</template>
