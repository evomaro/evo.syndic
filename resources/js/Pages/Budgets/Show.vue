<script setup lang="ts">
import { router, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
import BudgetMetricGrid from "@/Components/Expenses/BudgetMetricGrid.vue";
import { useI18n } from "@/i18n";
const props = defineProps<{ budget: any; metrics: any }>();
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const revision = useForm({ reason: "" });
</script>
<template>
    <AuthenticatedLayout
        :title="budget.title"
        :subtitle="
            text(
                `Budget version ${budget.version}`,
                `نسخة الميزانية ${budget.version}`,
            )
        "
        ><ExpenseNavigation />
        <section class="mb-5 flex items-center justify-between panel p-5">
            <FinancialStatusBadge :status="budget.status" />
            <div class="flex gap-2">
                <button
                    v-if="budget.status === 'draft'"
                    class="btn-primary"
                    @click="router.post(route('budgets.approve', budget.id))"
                >
                    {{ text("Approuver", "اعتماد") }}
                </button>
                <form
                    v-if="budget.status === 'approved'"
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="
                        revision.post(route('budgets.revise', budget.id))
                    "
                >
                    <label class="grid gap-1 text-sm"
                        >{{
                            text(
                                "Motif détaillé de la révision",
                                "السبب المفصل للمراجعة",
                            )
                        }}<input
                            v-model="revision.reason"
                            required
                            minlength="5"
                            class="rounded-lg border-slate-300" /></label
                    ><button
                        class="btn-secondary"
                        :disabled="revision.processing"
                    >
                        {{ text("Créer une révision", "إنشاء مراجعة") }}
                    </button>
                </form>
            </div>
        </section>
        <BudgetMetricGrid :metrics="metrics"
    /></AuthenticatedLayout>
</template>
