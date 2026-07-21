<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import { useI18n } from "@/i18n";
defineProps<{ accounts: any[] }>();
const { t, locale } = useI18n();
const money = (c: number) =>
    new Intl.NumberFormat(locale.value === "ar" ? "ar-MA" : "fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(c / 100);
const form = useForm({
    name: "",
    code: "",
    type: "bank",
    bank_name: "",
    rib: "",
    iban: "",
    opening_balance: "0.00",
    opening_balance_on: "",
    active: true,
    is_default: false,
    notes: "",
});
</script>
<template>
    <AuthenticatedLayout :title="t('financialAccounts')"
        ><FinanceNav />
        <div class="grid gap-5 xl:grid-cols-[380px_1fr]">
            <form
                class="panel grid h-fit gap-3 p-5"
                @submit.prevent="
                    form.post(route('financial-accounts.store'), {
                        onSuccess: () => form.reset(),
                    })
                "
            >
                <input
                    v-model="form.name"
                    :placeholder="t('name')"
                    required
                /><input
                    v-model="form.code"
                    :placeholder="t('code')"
                    required
                /><select v-model="form.type">
                    <option value="bank">{{ t("bank") }}</option>
                    <option value="cash">{{ t("cash") }}</option></select
                ><input
                    v-if="form.type === 'bank'"
                    v-model="form.bank_name"
                    :placeholder="t('bank')"
                /><input
                    v-if="form.type === 'bank'"
                    v-model="form.rib"
                    placeholder="RIB"
                /><input
                    v-if="form.type === 'bank'"
                    v-model="form.iban"
                    placeholder="IBAN"
                /><label class="field"
                    ><span class="field-label">{{ t("openingBalance") }}</span
                    ><input
                        v-model="form.opening_balance"
                        inputmode="decimal"
                        required /></label
                ><label class="flex min-h-11 items-center gap-2"
                    ><input v-model="form.is_default" type="checkbox" />{{
                        t("defaultAccount")
                    }}</label
                ><button class="btn-primary">{{ t("create") }}</button>
                <p v-for="e in form.errors" class="text-sm text-red-600">
                    {{ e }}
                </p>
            </form>
            <div class="grid gap-3">
                <article
                    v-for="a in accounts"
                    :key="a.id"
                    class="panel flex items-center justify-between gap-4 p-5"
                >
                    <div>
                        <h2 class="font-bold">{{ a.name }}</h2>
                        <p class="text-sm text-slate-500">
                            {{ a.code }} · {{ t(a.type) }}
                            <span v-if="a.default_slot"
                                >· {{ t("defaultAccount") }}</span
                            >
                        </p>
                    </div>
                    <div class="text-end">
                        <b class="text-lg">{{
                            money(a.current_balance_cents)
                        }}</b
                        ><span class="block text-xs text-slate-500">{{
                            t(a.active ? "activeStatus" : "inactiveStatus")
                        }}</span>
                    </div>
                </article>
            </div>
        </div></AuthenticatedLayout
    >
</template>
