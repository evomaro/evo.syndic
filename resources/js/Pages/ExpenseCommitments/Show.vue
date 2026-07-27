<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
const props = defineProps<{ commitment: any }>();
const act = (action: string) =>
    router.post(route("expense-commitments.transition", props.commitment.id), {
        action,
    });
</script>
<template>
    <AuthenticatedLayout :title="commitment.title" subtitle="Engagement"
        ><ExpenseNavigation />
        <section class="panel max-w-3xl p-5">
            <FinancialStatusBadge :status="commitment.status" />
            <p class="mt-4">
                {{ commitment.category?.name }} ·
                {{ commitment.amount_cents }} c
            </p>
            <div class="mt-5 flex gap-2">
                <button
                    v-if="commitment.status === 'draft'"
                    class="btn-primary"
                    @click="act('submit')"
                >
                    Soumettre</button
                ><button
                    v-if="commitment.status === 'submitted'"
                    class="btn-primary"
                    @click="act('approve')"
                >
                    Approuver
                </button>
            </div>
        </section></AuthenticatedLayout
    >
</template>
