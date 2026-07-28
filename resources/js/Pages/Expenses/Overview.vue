<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import BudgetMetricGrid from "@/Components/Expenses/BudgetMetricGrid.vue";
import EmptyState from "@/Components/EmptyState.vue";
import { formatMADCents as money } from "@/Support/money";
import { useI18n } from "@/i18n";
defineProps<{ metrics: any; activeBudget?: any; budgetMetrics: any }>();
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
</script>
<template>
    <AuthenticatedLayout
        :title="text('Dépenses & fournisseurs', 'المصاريف والموردون')"
        :subtitle="
            text(
                'Vue opérationnelle de la résidence',
                'نظرة تشغيلية على الإقامة',
            )
        "
        ><ExpenseNavigation />
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="item in [
                    [text('Dettes', 'الذمم المستحقة'), metrics.payable_cents],
                    [text('Échues', 'المتأخرة'), metrics.overdue_cents],
                    [
                        text('Budget disponible', 'الميزانية المتاحة'),
                        metrics.budget_remaining_cents,
                    ],
                    [
                        text('Payé ce mois', 'المدفوع هذا الشهر'),
                        metrics.settled_this_month_cents,
                    ],
                ]"
                :key="item[0]"
                class="stat"
            >
                <small>{{ item[0] }}</small
                ><b class="mt-2 block text-xl">{{ money(Number(item[1])) }}</b>
            </article>
        </div>
        <section class="mt-6">
            <h2 class="mb-3 text-lg font-bold">
                {{ text("Budget actif", "الميزانية النشطة") }}
            </h2>
            <BudgetMetricGrid v-if="activeBudget" :metrics="budgetMetrics" />
            <EmptyState
                v-else
                :title="text('Aucun budget approuvé', 'لا توجد ميزانية معتمدة')"
                :message="
                    text(
                        'Créez un budget pour suivre les engagements, les dépenses réelles et le disponible.',
                        'أنشئوا ميزانية لتتبع الالتزامات والمصاريف الفعلية والمتاح.',
                    )
                "
                :primary-label="text('Créer un budget', 'إنشاء ميزانية')"
                :primary-href="route('budgets.create')"
            >
                <template #icon>▤</template>
            </EmptyState>
        </section></AuthenticatedLayout
    >
</template>
