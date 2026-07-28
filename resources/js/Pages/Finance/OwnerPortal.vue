<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
import { formatMADCents as money } from "@/Support/money";
defineProps<{
    lots: any[];
    combinedBalanceCents: number;
    payments: any[];
    availableCreditCents: number;
}>();
const { t, locale } = useI18n();
</script>
<template>
    <AuthenticatedLayout :title="t('finance')"
        ><div class="grid gap-4 sm:grid-cols-2">
            <div class="stat">
                <p class="text-sm text-slate-500">
                    {{ t("outstandingAmount") }}
                </p>
                <b class="mt-2 block text-2xl">{{
                    money(combinedBalanceCents)
                }}</b>
            </div>
            <div class="stat">
                <p class="text-sm text-slate-500">{{ t("availableCredit") }}</p>
                <b class="mt-2 block text-2xl text-teal-700">{{
                    money(availableCreditCents)
                }}</b>
            </div>
        </div>
        <section class="mt-5 grid gap-3 sm:grid-cols-2">
            <article v-for="lot in lots" class="panel p-5">
                <div class="flex justify-between">
                    <b>{{ lot.reference }}</b
                    ><b>{{ money(lot.balance_cents) }}</b>
                </div>
                <Link
                    :href="route('owner-finance.statement', lot.id)"
                    class="mt-2 inline-flex text-sm font-semibold text-teal-700"
                    >{{ t("statements") }} · PDF</Link
                >
                <div class="mt-3 space-y-2">
                    <p
                        v-if="lot.inherited_debt_cents"
                        class="rounded-lg bg-amber-50 p-2 text-xs text-amber-800"
                    >
                        {{ t("outstandingAmount") }} ·
                        {{ money(lot.inherited_debt_cents) }}
                    </p>
                    <div
                        v-for="charge in lot.charges"
                        class="flex justify-between text-sm"
                    >
                        <span
                            >{{ charge.fund_call.number }} ·
                            {{ charge.due_date }}</span
                        ><span>{{ money(charge.outstanding_cents) }}</span>
                    </div>
                </div>
            </article>
        </section>
        <section class="panel mt-5">
            <div class="panel-head">
                <h2 class="font-bold">{{ t("payments") }}</h2>
            </div>
            <div class="divide-y">
                <div
                    v-for="payment in payments"
                    class="flex min-h-16 items-center justify-between px-5"
                >
                    <span
                        >{{ payment.number }} · {{ payment.payment_date }}</span
                    >
                    <div>
                        <b>{{ money(payment.amount_cents) }}</b
                        ><Link
                            v-for="doc in payment.documents"
                            :href="route('receipts.download', doc.id)"
                            class="ms-3 text-sm text-teal-700"
                            >{{ t("receipt") }}</Link
                        >
                    </div>
                </div>
            </div>
        </section></AuthenticatedLayout
    >
</template>
