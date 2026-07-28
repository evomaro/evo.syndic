<script setup lang="ts">
import { computed, ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import { useI18n } from "@/i18n";
import { formatMAD } from "@/Support/money";
const p = defineProps<{
    payment?: any;
    exercises: any[];
    accounts: any[];
    lots: any[];
    contacts: any[];
}>();
const { t, locale } = useI18n();
const form = useForm({
    financial_exercise_id:
        p.payment?.financial_exercise_id ?? p.exercises[0]?.id ?? "",
    payer_contact_id: p.payment?.payer_contact_id ?? (null as number | null),
    received_from: p.payment?.received_from ?? "",
    payment_date:
        p.payment?.payment_date?.slice(0, 10) ??
        new Date().toISOString().slice(0, 10),
    amount: p.payment ? (p.payment.amount_cents / 100).toFixed(2) : "",
    method: p.payment?.method ?? "bank_transfer",
    financial_account_id:
        p.payment?.financial_account_id ??
        p.accounts.find((a) => a.default_slot)?.id ??
        p.accounts[0]?.id ??
        "",
    bank_reference: p.payment?.bank_reference ?? "",
    cheque_number: p.payment?.cheque_number ?? "",
    notes: p.payment?.notes ?? "",
    allocation_mode: "fifo",
    lot_ids: [] as number[],
    validate_now: true,
    idempotency_key: crypto.randomUUID(),
});
const money = computed(() => {
    const n = Number(String(form.amount).replace(",", ".")) || 0;
    return formatMAD(n);
});
const confirming = ref(false);
const payerName = computed(
    () =>
        p.contacts.find((contact) => contact.id === form.payer_contact_id)
            ?.name ||
        form.received_from ||
        "Payeur non identifié",
);
const save = () =>
    p.payment
        ? form.put(route("payments.update", p.payment.id), {
              onSuccess: () => (confirming.value = false),
          })
        : form.post(route("payments.store"), {
              onSuccess: () => (confirming.value = false),
          });
</script>
<template>
    <AuthenticatedLayout :title="t('recordPayment')"
        ><FinanceNav />
        <form
            class="mx-auto grid max-w-4xl gap-5"
            @submit.prevent="confirming = true"
        >
            <section
                v-if="!confirming"
                class="panel grid gap-4 p-5 md:grid-cols-2"
            >
                <label class="field"
                    ><span class="field-label">{{ t("exercises") }}</span
                    ><select v-model="form.financial_exercise_id" required>
                        <option v-for="e in exercises" :value="e.id">
                            {{ e.name }}
                        </option>
                    </select></label
                ><label class="field"
                    ><span class="field-label">{{ t("paymentDate") }}</span
                    ><input
                        v-model="form.payment_date"
                        type="date"
                        required /></label
                ><label class="field"
                    ><span class="field-label">{{ t("payer") }}</span
                    ><select v-model="form.payer_contact_id">
                        <option :value="null">{{ t("receivedFrom") }}</option>
                        <option v-for="c in contacts" :value="c.id">
                            {{ c.name }} · {{ c.phone }}
                        </option>
                    </select></label
                ><label v-if="!form.payer_contact_id" class="field"
                    ><span class="field-label">{{ t("receivedFrom") }}</span
                    ><input v-model="form.received_from" required /></label
                ><label class="field"
                    ><span class="field-label">{{ t("amount") }}</span
                    ><input
                        v-model="form.amount"
                        inputmode="decimal"
                        required /></label
                ><label class="field"
                    ><span class="field-label">{{ t("method") }}</span
                    ><select v-model="form.method">
                        <option
                            v-for="m in [
                                'cash',
                                'bank_transfer',
                                'cheque',
                                'card',
                                'other',
                            ]"
                            :value="m"
                        >
                            {{ t(m === "other" ? "otherMethod" : m) }}
                        </option>
                    </select></label
                ><label class="field"
                    ><span class="field-label">{{ t("account") }}</span
                    ><select v-model="form.financial_account_id" required>
                        <option v-for="a in accounts" :value="a.id">
                            {{ a.name }}
                        </option>
                    </select></label
                ><label v-if="form.method === 'bank_transfer'" class="field"
                    ><span class="field-label">{{ t("reference") }}</span
                    ><input v-model="form.bank_reference" /></label
                ><label v-if="form.method === 'cheque'" class="field"
                    ><span class="field-label">{{ t("cheque") }}</span
                    ><input v-model="form.cheque_number"
                /></label>
            </section>
            <section v-if="!confirming" class="panel p-5">
                <h2 class="font-bold">{{ t("distribution") }}</h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label
                        class="flex min-h-12 items-center gap-2 rounded-xl border p-3"
                        ><input
                            v-model="form.allocation_mode"
                            type="radio"
                            value="fifo"
                        />FIFO · {{ t("allLots") }}</label
                    ><label
                        class="flex min-h-12 items-center gap-2 rounded-xl border p-3"
                        ><input
                            v-model="form.allocation_mode"
                            type="radio"
                            value="selected_lots"
                        />{{ t("selectedLots") }}</label
                    >
                </div>
                <div
                    v-if="form.allocation_mode === 'selected_lots'"
                    class="mt-3 grid max-h-56 gap-2 overflow-auto sm:grid-cols-3"
                >
                    <label
                        v-for="lot in lots"
                        class="flex min-h-11 items-center gap-2"
                        ><input
                            v-model="form.lot_ids"
                            type="checkbox"
                            :value="lot.id"
                        />{{ lot.reference }}</label
                    >
                </div>
            </section>
            <section v-else class="panel p-6 sm:p-8">
                <p
                    class="text-xs font-bold uppercase tracking-wider text-teal-700"
                >
                    Confirmation du paiement
                </p>
                <h2 class="mt-2 text-2xl font-bold">
                    Vérifiez avant d’enregistrer
                </h2>
                <p class="mt-4 text-lg leading-8 text-slate-700">
                    Un paiement de <strong>{{ money }}</strong> reçu de
                    <strong>{{ payerName }}</strong> le
                    <strong>{{ form.payment_date }}</strong> sera enregistré
                    dans
                    <strong>{{
                        accounts.find(
                            (account) =>
                                account.id === form.financial_account_id,
                        )?.name
                    }}</strong
                    >.
                </p>
                <p
                    class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900"
                >
                    {{
                        form.validate_now
                            ? "Le paiement sera validé immédiatement et affecté selon le mode choisi."
                            : "Le paiement restera en brouillon jusqu’à sa validation."
                    }}
                </p>
            </section>
            <div
                class="sticky bottom-20 z-10 flex items-center justify-between rounded-2xl border bg-white p-4 shadow-xl lg:bottom-4"
            >
                <button
                    v-if="confirming"
                    type="button"
                    class="btn-secondary"
                    @click="confirming = false"
                >
                    Modifier
                </button>
                <div v-else>
                    <small class="text-slate-500">{{ t("amount") }}</small
                    ><b class="block text-xl">{{ money }}</b>
                </div>
                <button
                    v-if="!confirming"
                    class="btn-primary"
                    :disabled="
                        form.processing || !accounts.length || !exercises.length
                    "
                >
                    {{ t("validateNow") }}
                </button>
                <button
                    v-else
                    type="button"
                    class="btn-primary"
                    :disabled="form.processing"
                    @click="save"
                >
                    {{
                        form.processing
                            ? "Enregistrement…"
                            : "Confirmer le paiement"
                    }}
                </button>
            </div>
            <p v-for="e in form.errors" class="text-sm text-red-600">{{ e }}</p>
        </form></AuthenticatedLayout
    >
</template>
