<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { reactive } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import { useI18n } from "@/i18n";
const p = defineProps<{
    lots: any[];
    selectedLot: number;
    transactions: any[];
    openingBalanceCents: number;
    closingBalanceCents: number;
    filters: any;
}>();
const filters = reactive({
    lot: p.selectedLot || "",
    from: p.filters.from || "",
    to: p.filters.to || "",
});
const { t, locale } = useI18n();
const money = (c: number) =>
    new Intl.NumberFormat(locale.value === "ar" ? "ar-MA" : "fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(c / 100);
</script>
<template>
    <AuthenticatedLayout :title="t('statements')"
        ><FinanceNav />
        <section class="panel overflow-hidden">
            <form
                class="panel-head flex-wrap"
                @submit.prevent="
                    router.get(route('finance.statements'), filters)
                "
            >
                <select v-model="filters.lot">
                    <option value="">{{ t("lots") }}</option>
                    <option v-for="lot in lots" :value="lot.id">
                        {{ lot.reference }}
                    </option>
                </select>
                <input v-model="filters.from" type="date" />
                <input v-model="filters.to" type="date" />
                <button class="btn-secondary">{{ t("filters") }}</button>
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        v-if="selectedLot"
                        :href="
                            route('finance.statements.pdf', {
                                ...filters,
                            })
                        "
                        class="btn-secondary"
                        >PDF</a
                    ><a
                        v-if="selectedLot"
                        :href="
                            route('finance.statements.csv', {
                                ...filters,
                            })
                        "
                        class="btn-secondary"
                        >CSV</a
                    ><span class="text-xs text-slate-500"
                        >{{ money(openingBalanceCents) }} →</span
                    ><b
                        >{{ t("currentBalance") }}:
                        {{ money(closingBalanceCents) }}</b
                    >
                </div>
            </form>
            <div class="divide-y">
                <div
                    v-for="row in transactions"
                    class="grid min-h-16 grid-cols-[100px_1fr_auto] items-center gap-3 px-5"
                >
                    <span class="text-sm text-slate-500">{{ row.date }}</span
                    ><span
                        ><b>{{ row.reference }}</b
                        ><small class="block text-slate-500"
                            >{{ row.type }} · {{ row.label
                            }}<span v-if="row.due_date">
                                · {{ t("dueDate") }} {{ row.due_date }}</span
                            ></small
                        ></span
                    ><b :class="row.credit_cents ? 'text-emerald-700' : ''"
                        >{{ row.debit_cents ? "+" : "-"
                        }}{{ money(row.debit_cents || row.credit_cents)
                        }}<small class="block text-slate-500"
                            >{{ t("runningBalance") }}
                            {{ money(row.balance_cents) }}</small
                        ></b
                    >
                </div>
                <p
                    v-if="!transactions.length"
                    class="p-8 text-center text-slate-500"
                >
                    {{ t("noFinancialData") }}
                </p>
            </div>
        </section></AuthenticatedLayout
    >
</template>
