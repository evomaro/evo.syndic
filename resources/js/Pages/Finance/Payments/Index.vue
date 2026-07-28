<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { reactive } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import Pagination from "@/Components/Pagination.vue";
import { useI18n } from "@/i18n";
import { formatMADCents as money } from "@/Support/money";
const p = defineProps<{ payments: any; accounts: any[]; filters: any }>();
const { t, locale } = useI18n();
const f = reactive({ ...p.filters });
</script>
<template>
    <AuthenticatedLayout :title="t('payments')"
        ><template #actions
            ><Link :href="route('payments.create')" class="btn-primary">{{
                t("recordPayment")
            }}</Link></template
        ><FinanceNav />
        <form
            class="panel mb-4 grid gap-3 p-4 sm:grid-cols-4"
            @submit.prevent="
                router.get(route('payments.index'), f, { preserveState: true })
            "
        >
            <input v-model="f.search" :placeholder="t('search')" /><select
                v-model="f.status"
            >
                <option value="">{{ t("all") }}</option>
                <option
                    v-for="s in ['draft', 'validated', 'reversed', 'cancelled']"
                    :value="s"
                >
                    {{ t(s) }}
                </option></select
            ><select v-model="f.financial_account_id">
                <option value="">{{ t("account") }}</option>
                <option v-for="a in accounts" :value="a.id">
                    {{ a.name }}
                </option></select
            ><button class="btn-secondary">{{ t("filters") }}</button>
        </form>
        <div class="grid gap-3">
            <Link
                v-for="p in payments.data"
                :key="p.id"
                :href="route('payments.show', p.id)"
                class="panel flex min-h-20 items-center justify-between gap-3 p-4"
                ><div>
                    <b>{{ p.number || t("draft") }}</b>
                    <p class="text-sm text-slate-500">
                        {{ p.payer?.display_name || p.received_from }} ·
                        {{ p.payment_date }} · {{ t(p.method) }}
                    </p>
                </div>
                <div class="text-end">
                    <b>{{ money(p.amount_cents) }}</b
                    ><span class="badge ms-2">{{ t(p.status) }}</span
                    ><small v-if="p.credit_cents" class="block text-teal-700"
                        >{{ t("availableCredit") }}
                        {{ money(p.credit_cents) }}</small
                    >
                </div></Link
            >
        </div>
        <Pagination :links="payments.links"
    /></AuthenticatedLayout>
</template>
