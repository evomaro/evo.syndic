<script setup lang="ts">
import { computed, ref } from "vue";
import { router, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import PeriodPicker from "@/Components/PeriodPicker.vue";
import { formatMADCents } from "@/Support/money";
import { useI18n } from "@/i18n";

const { range, summary, recentExpenses, canGenerateCotisation } = defineProps<{
    range: any;
    summary: any;
    recentExpenses: any[];
    canGenerateCotisation: boolean;
}>();
const fromPeriod = ref(range.from.value);
const { t, locale } = useI18n();
const toPeriod = ref(range.to.value);
const today = new Date();
const currentPeriod = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}`;
const rangeNotice = ref(range.normalized ? t("essentialRangeNormalized") : "");
const formatter = computed(
    () =>
        new Intl.DateTimeFormat(
            locale.value === "ar" ? "ar-MA-u-nu-latn" : "fr-MA",
            {
                month: "long",
                year: "numeric",
                timeZone: "UTC",
            },
        ),
);
const periodLabel = (value: string) => {
    const [year, month] = value.split("-").map(Number);
    return formatter.value.format(new Date(Date.UTC(year, month - 1, 1)));
};
const activeRangeLabel = computed(() =>
    fromPeriod.value === toPeriod.value
        ? periodLabel(fromPeriod.value)
        : `${periodLabel(fromPeriod.value)} – ${periodLabel(toPeriod.value)}`,
);
const balanceDate = computed(() =>
    new Intl.DateTimeFormat(
        locale.value === "ar" ? "ar-MA-u-nu-latn" : "fr-MA",
    ).format(new Date(`${summary.balance_as_of}T00:00:00`)),
);
const changeRange = () => {
    let nextFrom = fromPeriod.value || currentPeriod;
    let nextTo = toPeriod.value || currentPeriod;
    rangeNotice.value = "";

    if (nextFrom > nextTo) {
        [nextFrom, nextTo] = [nextTo, nextFrom];
        rangeNotice.value = t("essentialRangeReordered");
    }

    fromPeriod.value = nextFrom;
    toPeriod.value = nextTo;
    router.get(
        route("essential.dashboard"),
        { from_period: nextFrom, to_period: nextTo },
        { preserveState: true, replace: true },
    );
};
</script>

<template>
    <AuthenticatedLayout
        :title="t('essentialDashboard')"
        :subtitle="
            t('essentialDashboardSubtitle', { period: activeRangeLabel })
        "
    >
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <div class="flex flex-wrap gap-3">
                        <label class="text-sm font-semibold text-slate-700">
                            {{ t("essentialFromMonth") }}
                            <PeriodPicker
                                v-model="fromPeriod"
                                class="mt-1 w-52"
                                @change="changeRange"
                            />
                        </label>
                        <label class="text-sm font-semibold text-slate-700">
                            {{ t("essentialToMonth") }}
                            <PeriodPicker
                                v-model="toPeriod"
                                class="mt-1 w-52"
                                @change="changeRange"
                            />
                        </label>
                    </div>
                    <p
                        v-if="rangeNotice"
                        class="mt-2 text-sm font-medium text-amber-800"
                        role="status"
                    >
                        {{ rangeNotice }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-if="canGenerateCotisation"
                        :href="
                            route('essential.cotisations', {
                                period: toPeriod,
                                generate: 1,
                            })
                        "
                        class="inline-flex min-h-11 items-center rounded-xl bg-teal-700 px-4 font-semibold text-white"
                        >{{ t("essentialGenerateCotisation") }}</Link
                    >
                    <Link
                        :href="
                            route('essential.cotisations', {
                                period: toPeriod,
                            })
                        "
                        class="inline-flex min-h-11 items-center rounded-xl border border-teal-700 bg-white px-4 font-semibold text-teal-800"
                        >{{ t("essentialRecordPayment") }}</Link
                    >
                    <Link
                        :href="route('essential.expenses')"
                        class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 font-semibold"
                        >{{ t("essentialAddExpense") }}</Link
                    >
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <Link
                    v-for="card in [
                        [
                            t('essentialExpectedCotisations'),
                            summary.expected_cents,
                            'essential.cotisations',
                            false,
                        ],
                        [
                            t('essentialCollectedAmount'),
                            summary.collected_cents,
                            'essential.cotisations',
                            false,
                        ],
                        [
                            t('essentialRemainingToCollect'),
                            summary.remaining_cents,
                            'essential.cotisations',
                            false,
                        ],
                        [
                            t('essentialBankBalance'),
                            summary.bank_cents,
                            'essential.accounts',
                            true,
                        ],
                        [
                            t('essentialCashBalance'),
                            summary.cash_cents,
                            'essential.accounts',
                            true,
                        ],
                    ]"
                    :key="card[0]"
                    :href="route(card[2] as string, { period: toPeriod })"
                    class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-teal-300"
                >
                    <p class="text-sm text-slate-500">{{ card[0] }}</p>
                    <p class="mt-2 text-2xl font-black text-slate-950">
                        {{ formatMADCents(card[1] as number) }}
                    </p>
                    <p v-if="card[3]" class="mt-1 text-xs text-slate-500">
                        {{ t("essentialBalanceAsOf", { date: balanceDate }) }}
                    </p>
                </Link>
            </div>

            <section
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold">
                            {{ t("essentialRecentExpenses") }}
                        </h2>
                        <p class="text-xs text-slate-500">
                            {{ t("essentialRecentActivityCaption") }}
                        </p>
                    </div>
                    <Link
                        :href="route('essential.expenses')"
                        class="text-sm font-semibold text-teal-700"
                        >{{ t("essentialViewAll") }}</Link
                    >
                </div>
                <div
                    v-if="!recentExpenses.length"
                    class="rounded-xl bg-slate-50 p-8 text-center"
                    role="status"
                >
                    <h3 class="font-bold">
                        {{ t("essentialNoRecentExpense") }}
                    </h3>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ t("essentialRecentExpenseHelp") }}
                    </p>
                </div>
                <ul v-else class="divide-y divide-slate-100">
                    <li
                        v-for="expense in recentExpenses"
                        :key="expense.id"
                        class="flex items-center justify-between gap-4 py-3"
                    >
                        <div>
                            <p class="font-medium">{{ expense.description }}</p>
                            <p class="text-sm text-slate-500">
                                {{ expense.date }}
                            </p>
                        </div>
                        <strong>{{
                            formatMADCents(expense.amount_cents)
                        }}</strong>
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
