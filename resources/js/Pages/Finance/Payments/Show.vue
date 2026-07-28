<script setup lang="ts">
import { Link, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import AccountingPostingStatus from "@/Components/AccountingPostingStatus.vue";
import { useI18n } from "@/i18n";
import { formatMADCents as money } from "@/Support/money";
const p = defineProps<{
    payment: any;
    availableCharges: any[];
    contacts: any[];
    accountingPosting: any;
}>();
const { t, locale } = useI18n();
const reason = ref("");
const reversalForm = useForm({ reason: "" });
const identifyForm = useForm({ contact_id: null as number | null });
const allocationMode = ref("fifo");
const manualChargeId = ref<number | null>(null);
const manualAmount = ref("");
const validatePayment = () =>
    useForm({
        allocation_mode: allocationMode.value,
        lot_ids: [],
        manual: manualChargeId.value
            ? [
                  {
                      lot_charge_id: manualChargeId.value,
                      amount: manualAmount.value,
                  },
              ]
            : [],
    }).post(route("payments.validate", p.payment.id));
const allocateCredit = () =>
    useForm({
        manual: [
            { lot_charge_id: manualChargeId.value, amount: manualAmount.value },
        ],
    }).post(route("payments.allocate", p.payment.id));
const reversePayment = () => {
    if (window.confirm(t("confirm"))) {
        reversalForm.reason = reason.value;
        reversalForm.post(route("payments.reverse", p.payment.id));
    }
};
const identifyPayer = () =>
    identifyForm.post(route("payments.identify-payer", p.payment.id));
</script>
<template>
    <AuthenticatedLayout
        :title="payment.number || t('draft')"
        :subtitle="payment.payer?.display_name || payment.received_from"
        ><template #actions
            ><Link
                v-if="payment.status === 'draft'"
                :href="route('payments.edit', payment.id)"
                class="btn-secondary"
                >{{ t("edit") }}</Link
            ></template
        ><FinanceNav />
        <div class="grid gap-5 xl:grid-cols-[1fr_340px]">
            <section class="panel">
                <AccountingPostingStatus
                    :posting="accountingPosting"
                    class="m-4"
                />
                <div class="panel-head">
                    <div>
                        <b>{{ money(payment.amount_cents) }}</b>
                        <p class="text-sm text-slate-500">
                            {{ payment.payment_date }} ·
                            {{ t(payment.method) }} · {{ payment.account.name }}
                        </p>
                    </div>
                    <span class="badge">{{ t(payment.status) }}</span>
                </div>
                <div class="divide-y">
                    <div
                        v-for="a in payment.allocations"
                        class="flex min-h-16 items-center justify-between px-5"
                        :class="a.reversed_at ? 'line-through opacity-50' : ''"
                    >
                        <span
                            >{{ a.lot.reference }} ·
                            {{ a.charge.fund_call.number }}
                            <small class="block text-slate-500">{{
                                a.allocated_on
                            }}</small>
                            <small v-if="a.reversed_at" class="block"
                                >{{ a.reversed_at }} ·
                                {{ a.reversed_by?.name }} ·
                                {{ a.reversal_reason }}</small
                            ></span
                        ><b>{{ money(a.amount_cents) }}</b>
                    </div>
                    <div
                        v-if="payment.credit_cents"
                        class="flex min-h-16 items-center justify-between bg-teal-50 px-5 text-teal-900"
                    >
                        <span>{{ t("availableCredit") }}</span
                        ><b>{{ money(payment.credit_cents) }}</b>
                    </div>
                    <div
                        v-else-if="
                            payment.status === 'validated' &&
                            payment.unallocated_cents
                        "
                        class="flex min-h-16 items-center justify-between bg-amber-50 px-5 text-amber-900"
                    >
                        <span>{{ t("unallocated") }} · {{ t("payer") }}</span
                        ><b>{{ money(payment.unallocated_cents) }}</b>
                    </div>
                </div>
            </section>
            <aside class="space-y-3">
                <div
                    v-if="payment.status === 'draft'"
                    class="panel grid gap-3 p-4"
                >
                    <select v-model="allocationMode">
                        <option value="fifo">FIFO</option>
                        <option value="manual">{{ t("manual") }}</option>
                        <option value="manual_then_fifo">
                            {{ t("manual") }} + FIFO
                        </option>
                    </select>
                    <template v-if="allocationMode !== 'fifo'">
                        <select v-model="manualChargeId" required>
                            <option :value="null">
                                {{ t("outstanding") }}
                            </option>
                            <option
                                v-for="charge in availableCharges"
                                :value="charge.id"
                            >
                                {{ charge.lot }} · {{ charge.reference }} ·
                                {{ money(charge.outstanding_cents) }}
                            </option>
                        </select>
                        <input
                            v-model="manualAmount"
                            inputmode="decimal"
                            :placeholder="t('amount')"
                        />
                    </template>
                    <button class="btn-primary w-full" @click="validatePayment">
                        {{ t("validate") }}
                    </button>
                </div>
                <Link
                    v-for="doc in payment.documents"
                    :key="doc.id"
                    :href="route('receipts.download', doc.id)"
                    class="btn-secondary w-full"
                    >{{ t("download") }} · {{ doc.number }}</Link
                >
                <form
                    v-if="
                        payment.status === 'validated' &&
                        !payment.payer_contact_id
                    "
                    class="panel grid gap-3 p-4"
                    @submit.prevent="identifyPayer"
                >
                    <b>{{ t("payer") }}</b>
                    <select v-model="identifyForm.contact_id" required>
                        <option :value="null">{{ t("payer") }}</option>
                        <option v-for="contact in contacts" :value="contact.id">
                            {{ contact.name }}
                        </option>
                    </select>
                    <button class="btn-primary">{{ t("validate") }}</button>
                    <p
                        v-for="error in identifyForm.errors"
                        class="text-sm text-red-600"
                    >
                        {{ error }}
                    </p>
                </form>
                <form
                    v-if="payment.status === 'validated'"
                    class="panel grid gap-3 p-4"
                    @submit.prevent="reversePayment"
                >
                    <textarea
                        v-model="reason"
                        :placeholder="t('reason')"
                        required
                    /><button class="btn-secondary">{{ t("reverse") }}</button>
                    <p
                        v-for="error in reversalForm.errors"
                        class="text-sm text-red-600"
                    >
                        {{ error }}
                    </p>
                </form>
                <form
                    v-if="
                        payment.status === 'validated' && payment.credit_cents
                    "
                    class="panel grid gap-3 p-4"
                    @submit.prevent="allocateCredit"
                >
                    <b
                        >{{ t("availableCredit") }} ·
                        {{ money(payment.credit_cents) }}</b
                    >
                    <select v-model="manualChargeId" required>
                        <option :value="null">{{ t("outstanding") }}</option>
                        <option
                            v-for="charge in availableCharges"
                            :value="charge.id"
                        >
                            {{ charge.lot }} · {{ charge.reference }} ·
                            {{ money(charge.outstanding_cents) }}
                        </option>
                    </select>
                    <input
                        v-model="manualAmount"
                        inputmode="decimal"
                        :placeholder="t('amount')"
                        required
                    />
                    <button class="btn-primary">{{ t("distribution") }}</button>
                </form>
            </aside>
        </div></AuthenticatedLayout
    >
</template>
