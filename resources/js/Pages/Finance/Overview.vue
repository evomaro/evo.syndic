<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import { useI18n } from "@/i18n";
import { formatMADCents as money } from "@/Support/money";
const props = defineProps<{
    metrics: any;
    accounts: any[];
    recentPayments: any[];
    upcomingCalls: any[];
    draftCalls: any[];
    filters: { from: string; to: string };
}>();
const cards = [
    ["amountCalled", "called_cents", "fund-calls.index", () => props.filters],
    [
        "amountCollected",
        "collected_cents",
        "payments.index",
        () => ({
            charge_from: props.filters.from,
            charge_to: props.filters.to,
        }),
    ],
    [
        "outstandingAmount",
        "outstanding_cents",
        "finance.outstanding",
        () => props.filters,
    ],
    [
        "overdueAmount",
        "overdue_cents",
        "finance.outstanding",
        () => ({ ...props.filters, overdue: 1 }),
    ],
    [
        "availableCredit",
        "credit_cents",
        "payments.index",
        () => ({ ...props.filters, credit: 1 }),
    ],
] as const;
const { t, locale } = useI18n();
</script>
<template>
    <AuthenticatedLayout :title="t('finance')" :subtitle="t('financeOverview')">
        <template #actions
            ><Link :href="route('payments.create')" class="btn-primary">{{
                t("recordPayment")
            }}</Link></template
        >
        <FinanceNav />
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <Link
                v-for="card in cards"
                :key="card[0]"
                :href="route(card[2], card[3]())"
                class="stat"
                ><p class="text-xs font-semibold text-slate-500">
                    {{ t(card[0] as string) }}
                </p>
                <p class="mt-2 text-xl font-bold">
                    {{ money(metrics[card[1]]) }}
                </p></Link
            >
            <div class="stat">
                <p class="text-xs font-semibold text-slate-500">
                    {{ t("collectionRate") }}
                </p>
                <p class="mt-2 text-xl font-bold">
                    {{ metrics.collection_rate }}%
                </p>
            </div>
        </div>
        <div class="mt-5 grid gap-5 xl:grid-cols-3">
            <section class="panel">
                <div class="panel-head">
                    <h2 class="font-bold">{{ t("currentBalance") }}</h2>
                </div>
                <div class="divide-y">
                    <div
                        v-for="account in accounts"
                        :key="account.id"
                        class="flex min-h-16 items-center justify-between px-5"
                    >
                        <span
                            >{{ account.name }}
                            <small class="text-slate-400">{{
                                t(account.type)
                            }}</small></span
                        ><b>{{ money(account.balance_cents) }}</b>
                    </div>
                    <p
                        v-if="!accounts.length"
                        class="p-5 text-sm text-slate-500"
                    >
                        {{ t("noFinancialData") }}
                    </p>
                </div>
            </section>
            <section class="panel">
                <div class="panel-head">
                    <h2 class="font-bold">{{ t("recentPayments") }}</h2>
                    <Link
                        :href="route('payments.index')"
                        class="text-sm text-teal-700"
                        >{{ t("all") }}</Link
                    >
                </div>
                <div class="divide-y">
                    <Link
                        v-for="payment in recentPayments"
                        :key="payment.id"
                        :href="route('payments.show', payment.id)"
                        class="flex min-h-16 items-center justify-between px-5"
                        ><span
                            ><b class="block">{{
                                payment.number || t("draft")
                            }}</b
                            ><small>{{
                                payment.payer?.display_name ||
                                payment.received_from
                            }}</small></span
                        ><b>{{ money(payment.amount_cents) }}</b></Link
                    >
                </div>
            </section>
            <section class="panel">
                <div class="panel-head">
                    <h2 class="font-bold">{{ t("draftsToValidate") }}</h2>
                </div>
                <div class="divide-y">
                    <Link
                        v-for="call in draftCalls"
                        :key="call.id"
                        :href="route('fund-calls.show', call.id)"
                        class="block min-h-16 px-5 py-3"
                        ><b>{{ call.title }}</b
                        ><small class="block text-slate-500">{{
                            call.issue_date
                        }}</small></Link
                    >
                    <p
                        v-if="!draftCalls.length"
                        class="p-5 text-sm text-slate-500"
                    >
                        {{ t("noResults") }}
                    </p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
