<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import BudgetMetricGrid from "@/Components/Expenses/BudgetMetricGrid.vue";
import { useI18n } from "@/i18n";
defineProps<{ metrics: any; activeBudget?: any; budgetMetrics: any }>();
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const money = (v: number) =>
    new Intl.NumberFormat(locale.value === "ar" ? "ar-MA" : "fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format((v || 0) / 100);
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
            <p v-else class="panel p-6 text-slate-500">
                {{
                    text(
                        "Aucun budget approuvé pour cette résidence.",
                        "لا توجد ميزانية معتمدة لهذه الإقامة.",
                    )
                }}
            </p>
        </section></AuthenticatedLayout
    >
</template>
