<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import Pagination from "@/Components/Pagination.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
defineProps<{ commitments: any; filters: any }>();
</script>
<template>
    <AuthenticatedLayout
        title="Engagements"
        subtitle="Approbations avant facturation"
        ><ExpenseNavigation />
        <div class="mb-4 flex justify-end">
            <Link
                :href="route('expense-commitments.create')"
                class="btn-primary"
                >Nouvel engagement</Link
            >
        </div>
        <div class="panel divide-y">
            <Link
                v-for="c in commitments.data"
                :key="c.id"
                :href="route('expense-commitments.show', c.id)"
                class="flex min-h-16 items-center justify-between p-4"
                ><span
                    ><b>{{ c.title }}</b
                    ><small class="block">{{ c.category?.name }}</small></span
                ><FinancialStatusBadge :status="c.status"
            /></Link>
        </div>
        <Pagination :links="commitments.links"
    /></AuthenticatedLayout>
</template>
