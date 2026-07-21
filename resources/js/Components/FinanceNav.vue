<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";
const { t } = useI18n();
const page = usePage<any>();
const permissions = page.props.auth?.permissions ?? [];
const can = (permission: string) => permissions.includes(permission);
const active = (routeName: string) => {
    const pathname = new URL(route(routeName), window.location.origin).pathname;
    return page.url === pathname || page.url.startsWith(`${pathname}/`);
};
const items = [
    ["financeOverview", "finance.index", "view_finance"],
    ["exercises", "financial-exercises.index", "manage_financial_exercises"],
    ["fundCalls", "fund-calls.index", "view_finance"],
    ["payments", "payments.index", "view_finance"],
    ["outstanding", "finance.outstanding", "view_outstanding"],
    ["statements", "finance.statements", "view_statements"],
    [
        "financialAccounts",
        "financial-accounts.index",
        "manage_financial_accounts",
    ],
    ["chargeCategories", "charge-categories.index", "manage_charge_categories"],
    ["schedules", "fund-call-schedules.index", "create_fund_calls"],
].filter((item) => can(item[2]));
</script>
<template>
    <nav class="mb-5 flex gap-2 overflow-x-auto pb-1" aria-label="Finance">
        <Link
            v-for="item in items"
            :key="item[1]"
            :href="route(item[1])"
            :class="
                active(item[1])
                    ? 'bg-slate-950 text-white'
                    : 'border border-slate-200 bg-white text-slate-600'
            "
            class="inline-flex min-h-11 shrink-0 items-center rounded-xl px-4 text-sm font-semibold"
            >{{ t(item[0]) }}</Link
        >
    </nav>
</template>
