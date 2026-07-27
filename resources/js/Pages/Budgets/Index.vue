<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import Pagination from "@/Components/Pagination.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
defineProps<{ budgets: any }>();
</script>
<template>
    <AuthenticatedLayout
        title="Budgets"
        subtitle="Versions approuvées et révisions"
        ><ExpenseNavigation />
        <div class="mb-4 flex justify-end">
            <Link :href="route('budgets.create')" class="btn-primary"
                >Nouveau budget</Link
            >
        </div>
        <div class="panel divide-y">
            <Link
                v-for="b in budgets.data"
                :key="b.id"
                :href="route('budgets.show', b.id)"
                class="flex min-h-16 items-center justify-between p-4"
                ><span
                    ><b>{{ b.title }}</b
                    ><small class="block">Version {{ b.version }}</small></span
                ><FinancialStatusBadge :status="b.status"
            /></Link>
        </div>
        <Pagination :links="budgets.links"
    /></AuthenticatedLayout>
</template>
