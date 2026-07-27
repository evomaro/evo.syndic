<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import Pagination from "@/Components/Pagination.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
defineProps<{ creditNotes: any; filters: any }>();
</script>
<template>
    <AuthenticatedLayout
        title="Avoirs fournisseurs"
        subtitle="Réduction des dettes validées"
        ><ExpenseNavigation />
        <div class="mb-4 flex justify-end">
            <Link
                :href="route('supplier-credit-notes.create')"
                class="btn-primary"
                >Nouvel avoir</Link
            >
        </div>
        <div class="panel divide-y">
            <Link
                v-for="c in creditNotes.data"
                :key="c.id"
                :href="route('supplier-credit-notes.show', c.id)"
                class="flex min-h-16 items-center justify-between p-4"
                ><span
                    ><b>{{
                        c.number || c.supplier_credit_number || "Brouillon"
                    }}</b
                    ><small class="block">{{
                        c.supplier?.legal_name
                    }}</small></span
                ><FinancialStatusBadge :status="c.status"
            /></Link>
        </div>
        <Pagination :links="creditNotes.links"
    /></AuthenticatedLayout>
</template>
