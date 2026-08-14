<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import axios from "axios";
import Modal from "@/Components/Modal.vue";
import PeriodPicker from "@/Components/PeriodPicker.vue";
import InputError from "@/Components/InputError.vue";
import { formatMADCents } from "@/Support/money";
import { useI18n } from "@/i18n";

const props = defineProps<{
    selected: any | null;
    accounts: any[];
    exercises: any[];
    activeResidenceId: number | null;
}>();
const emit = defineEmits<{ close: [] }>();
const { t, locale } = useI18n();
const preview = ref<any>(null);
const previewing = ref(false);
const previewError = ref("");
const form = useForm({
    residence_id: props.activeResidenceId,
    lot_charge_id: null as number | null,
    lot_id: null as number | null,
    allocation_mode: "fifo",
    from_period: "",
    to_period: "",
    financial_exercise_id: props.exercises[0]?.id ?? null,
    financial_account_id: props.accounts[0]?.id ?? null,
    payment_date: new Date().toISOString().slice(0, 10),
    amount: "",
    method: "bank_transfer",
    bank_reference: "",
    cheque_number: "",
    notes: "",
    idempotency_key: crypto.randomUUID(),
});

watch(
    () => props.selected,
    (selected) => {
        if (!selected) return;
        form.lot_charge_id = selected.id;
        form.lot_id = selected.lot_id;
        form.allocation_mode = "fifo";
        form.from_period = selected.period;
        form.to_period = selected.period;
        form.amount = (selected.remaining_cents / 100).toFixed(2);
        preview.value = null;
        previewError.value = "";
        form.clearErrors();
    },
);
const selectedContext = computed(() =>
    [props.selected?.building, props.selected?.lot, props.selected?.resident]
        .filter((value) => String(value ?? "").trim())
        .join(" · "),
);
const submitError = computed(() => Object.values(form.errors).flat().join(" "));
const invalidate = () => {
    preview.value = null;
    previewError.value = "";
};
const loadPreview = async () => {
    previewing.value = true;
    previewError.value = "";
    try {
        const response = await axios.post(route("essential.payments.preview"), {
            residence_id: form.residence_id,
            lot_id: form.lot_id,
            allocation_mode: form.allocation_mode,
            amount: form.allocation_mode === "fifo" ? form.amount : null,
            from_period: form.from_period,
            to_period: form.to_period,
        });
        preview.value = response.data;
        if (form.allocation_mode === "range") {
            form.amount = (response.data.total_cents / 100).toFixed(2);
        }
    } catch (error: any) {
        const errors = error.response?.data?.errors;
        previewError.value = errors
            ? Object.values(errors).flat().join(" ")
            : t("essentialAllocationError");
    } finally {
        previewing.value = false;
    }
};
const submit = () =>
    form.post(route("essential.payments.store"), {
        preserveScroll: true,
        onSuccess: () => {
            emit("close");
            form.reset("amount", "notes", "bank_reference", "cheque_number");
            form.idempotency_key = crypto.randomUUID();
        },
    });
const status = (value: string) =>
    value === "paid" ? t("essentialPaid") : t("essentialPartialPaid");
</script>

<template>
    <Modal :show="!!selected" max-width="2xl" @close="emit('close')">
        <form
            v-if="selected"
            class="space-y-5 p-6"
            @submit.prevent="preview ? submit() : loadPreview()"
        >
            <div>
                <h2 class="text-xl font-bold">
                    {{ t("essentialRecordPayment") }}
                </h2>
                <p v-if="selectedContext" class="text-sm text-slate-500">
                    {{ selectedContext }}
                </p>
            </div>

            <template v-if="!preview">
                <div
                    class="grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1 text-sm"
                >
                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 font-semibold"
                        :class="
                            form.allocation_mode === 'fifo'
                                ? 'bg-white shadow'
                                : ''
                        "
                        @click="
                            form.allocation_mode = 'fifo';
                            invalidate();
                        "
                    >
                        {{ t("essentialFreeAmount") }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 font-semibold"
                        :class="
                            form.allocation_mode === 'range'
                                ? 'bg-white shadow'
                                : ''
                        "
                        @click="
                            form.allocation_mode = 'range';
                            invalidate();
                        "
                    >
                        {{ t("essentialMonthRange") }}
                    </button>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label
                        v-if="form.allocation_mode === 'fifo'"
                        class="text-sm font-medium sm:col-span-2"
                        >{{ t("essentialReceivedAmountMad")
                        }}<input
                            v-model="form.amount"
                            required
                            inputmode="decimal"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                            @input="invalidate"
                    /></label>
                    <template v-else>
                        <label class="text-sm font-medium"
                            >{{ t("essentialFromMonth")
                            }}<PeriodPicker
                                v-model="form.from_period"
                                class="mt-1 w-full"
                                @change="invalidate"
                        /></label>
                        <label class="text-sm font-medium"
                            >{{ t("essentialToMonth")
                            }}<PeriodPicker
                                v-model="form.to_period"
                                class="mt-1 w-full"
                                @change="invalidate"
                        /></label>
                    </template>
                    <label class="text-sm font-medium"
                        >{{ t("essentialDate")
                        }}<input
                            v-model="form.payment_date"
                            type="date"
                            :lang="locale"
                            required
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                    /></label>
                    <label class="text-sm font-medium"
                        >{{ t("essentialTowards")
                        }}<select
                            v-model="form.financial_account_id"
                            required
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                        >
                            <option
                                v-for="account in accounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{
                                    account.type === "bank"
                                        ? t("bank")
                                        : t("cash")
                                }}
                                — {{ account.name }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm font-medium"
                        >{{ t("essentialMode")
                        }}<select
                            v-model="form.method"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                        >
                            <option value="bank_transfer">
                                {{ t("bank_transfer") }}
                            </option>
                            <option value="cash">
                                {{ t("essentialCash") }}
                            </option>
                            <option value="cheque">{{ t("cheque") }}</option>
                            <option value="card">{{ t("card") }}</option>
                            <option value="other">
                                {{ t("essentialOther") }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm font-medium"
                        >{{ t("essentialReferenceOptional")
                        }}<input
                            v-model="form.bank_reference"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                    /></label>
                </div>
                <p class="rounded-xl bg-teal-50 p-3 text-sm text-teal-900">
                    {{ t("essentialAdvanceHelp") }}
                </p>
            </template>

            <template v-else>
                <div class="rounded-xl bg-teal-50 p-4 text-sm text-teal-950">
                    {{
                        t("essentialMovementPreview", {
                            total: formatMADCents(preview.total_cents),
                            allocated: formatMADCents(preview.allocated_cents),
                        })
                    }}
                </div>
                <div class="overflow-hidden rounded-xl border border-slate-200">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-start">
                            <tr>
                                <th class="p-3">{{ t("essentialMonth") }}</th>
                                <th class="p-3 text-end">
                                    {{ t("essentialAllocated") }}
                                </th>
                                <th class="p-3">
                                    {{ t("essentialAfterPayment") }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="row in preview.allocations"
                                :key="row.lot_charge_id"
                            >
                                <td class="p-3 font-medium">
                                    {{ row.period }}
                                </td>
                                <td class="p-3 text-end">
                                    {{ formatMADCents(row.amount_cents) }}
                                </td>
                                <td class="p-3">
                                    {{ status(row.projected_status)
                                    }}<span v-if="row.remaining_cents">
                                        ·
                                        {{
                                            t("essentialRemainingInline", {
                                                amount: formatMADCents(
                                                    row.remaining_cents,
                                                ),
                                            })
                                        }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="preview.credit_cents > 0"
                    class="rounded-xl bg-amber-50 p-3 text-sm text-amber-950"
                >
                    {{
                        t("essentialCreditHelp", {
                            amount: formatMADCents(preview.credit_cents),
                        })
                    }}
                </p>
                <p
                    v-if="!preview.allocations.length && !preview.credit_cents"
                    class="text-sm text-slate-500"
                >
                    {{ t("essentialNoUnpaidInRange") }}
                </p>
            </template>

            <p v-if="previewError" class="text-sm text-rose-700">
                {{ previewError }}
            </p>
            <InputError :message="submitError" />
            <div class="flex justify-end gap-2">
                <button
                    v-if="preview"
                    type="button"
                    class="min-h-11 rounded-xl border px-4"
                    @click="preview = null"
                >
                    {{ t("essentialModify") }}
                </button>
                <button
                    :disabled="
                        previewing ||
                        form.processing ||
                        (preview && preview.total_cents <= 0)
                    "
                    class="min-h-11 rounded-xl bg-teal-700 px-4 font-semibold text-white disabled:opacity-60"
                >
                    {{
                        previewing
                            ? t("essentialCalculating")
                            : form.processing
                              ? t("essentialRecording")
                              : preview
                                ? t("essentialConfirmPayment")
                                : t("essentialViewAllocation")
                    }}
                </button>
            </div>
        </form>
    </Modal>
</template>
