<script setup lang="ts">
import { ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import InputError from "@/Components/InputError.vue";
import Modal from "@/Components/Modal.vue";
import Pagination from "@/Components/Pagination.vue";
import InfoTooltip from "@/Components/InfoTooltip.vue";
import { formatMADCents } from "@/Support/money";
import { useI18n } from "@/i18n";

const { accounts, movements, balances, filters, activeResidenceId, exercises } =
    defineProps<{
        accounts: any[];
        movements: any;
        balances: any;
        filters: any;
        activeResidenceId: number | null;
        exercises: any[];
    }>();
const showTransfer = ref(false);
const { t, locale } = useI18n();
const form = useForm({
    residence_id: activeResidenceId,
    financial_exercise_id: exercises[0]?.id ?? null,
    source_account_id: accounts[0]?.id ?? null,
    destination_account_id: accounts[1]?.id ?? null,
    transferred_on: new Date().toISOString().slice(0, 10),
    amount: "",
    reference: "",
    notes: "",
    idempotency_key: crypto.randomUUID(),
});
const submit = () =>
    form.post(route("essential.transfers.store"), {
        preserveScroll: true,
        onSuccess: () => {
            showTransfer.value = false;
            form.reset("amount", "reference", "notes");
            form.idempotency_key = crypto.randomUUID();
        },
    });
const filter = (event: Event) =>
    router.get(
        route("essential.accounts"),
        Object.fromEntries(
            new FormData(event.currentTarget as HTMLFormElement) as any,
        ),
        { preserveState: true, replace: true },
    );
</script>

<template>
    <AuthenticatedLayout
        :title="t('essentialAccounts')"
        :subtitle="t('essentialAccountsSubtitle')"
    >
        <div class="space-y-5">
            <div class="flex flex-wrap items-stretch justify-between gap-4">
                <div class="grid flex-1 gap-4 sm:grid-cols-2">
                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <p class="text-sm text-slate-500">
                            {{ t("essentialBankBalance") }}
                            <InfoTooltip term="balance" />
                        </p>
                        <p class="mt-2 text-2xl font-black">
                            {{ formatMADCents(balances.bank) }}
                        </p>
                    </div>
                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <p class="text-sm text-slate-500">
                            {{ t("essentialCashBalance") }}
                            <InfoTooltip term="balance" />
                        </p>
                        <p class="mt-2 text-2xl font-black">
                            {{ formatMADCents(balances.cash) }}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    class="min-h-11 self-end rounded-xl bg-teal-700 px-4 font-semibold text-white"
                    :disabled="accounts.length < 2"
                    @click="showTransfer = true"
                >
                    {{ t("essentialTransfer") }}
                </button>
            </div>
            <form
                class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-3"
                @change="filter"
            >
                <label class="text-sm font-medium"
                    >{{ t("account")
                    }}<select
                        name="account_id"
                        :value="filters.account_id"
                        class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                    >
                        <option value="">
                            {{ t("essentialBankAndCash") }}
                        </option>
                        <option
                            v-for="item in accounts"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }}
                        </option>
                    </select></label
                ><label class="text-sm font-medium"
                    >{{ t("essentialFrom")
                    }}<input
                        name="from"
                        type="date"
                        :lang="locale"
                        :value="filters.from"
                        class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label
                ><label class="text-sm font-medium"
                    >{{ t("essentialTo")
                    }}<input
                        name="to"
                        type="date"
                        :lang="locale"
                        :value="filters.to"
                        class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                /></label>
            </form>
            <div
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <div class="overflow-x-auto">
                    <table class="min-w-[650px] w-full text-sm">
                        <thead class="bg-slate-50 text-start">
                            <tr>
                                <th class="p-4">{{ t("essentialDate") }}</th>
                                <th class="p-4">{{ t("description") }}</th>
                                <th class="p-4">{{ t("account") }}</th>
                                <th class="p-4">
                                    {{ t("essentialDirection") }}
                                </th>
                                <th class="p-4 text-end">{{ t("amount") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="item in movements.data" :key="item.id">
                                <td class="p-4">{{ item.date }}</td>
                                <td class="p-4 font-medium">
                                    <span
                                        v-if="item.is_correction"
                                        class="me-2 inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-bold text-amber-900"
                                        >{{ t("essentialCorrection") }}</span
                                    >{{ item.description }}
                                    <p
                                        v-if="item.is_correction"
                                        class="mt-1 text-xs font-normal text-slate-500"
                                    >
                                        {{
                                            t("essentialCorrectionHelp", {
                                                number: item.reversal_of_id
                                                    ? ` n° ${item.reversal_of_id}`
                                                    : "",
                                            })
                                        }}
                                    </p>
                                </td>
                                <td class="p-4">{{ item.account?.name }}</td>
                                <td class="p-4">
                                    {{
                                        item.direction === "credit"
                                            ? t("essentialEntry")
                                            : t("essentialExit")
                                    }}
                                </td>
                                <td
                                    class="p-4 text-end font-semibold"
                                    :class="
                                        item.direction === 'credit'
                                            ? 'text-emerald-700'
                                            : 'text-rose-700'
                                    "
                                >
                                    {{
                                        item.direction === "credit" ? "+" : "−"
                                    }}
                                    {{ formatMADCents(item.amount_cents) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="!movements.data.length"
                    class="p-8 text-center"
                    role="status"
                >
                    <h3 class="font-bold">{{ t("essentialNoMovement") }}</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ t("essentialNoMovementHelp") }}
                    </p>
                </div>
                <Pagination :links="movements.links" />
            </div>
        </div>
        <Modal :show="showTransfer" max-width="lg" @close="showTransfer = false"
            ><form class="space-y-4 p-6" @submit.prevent="submit">
                <div>
                    <h2 class="text-xl font-bold">
                        {{ t("essentialTransferTitle") }}
                    </h2>
                    <p class="text-sm text-slate-500">
                        {{ t("essentialTransferHelp") }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium"
                        >{{ t("essentialSince")
                        }}<select
                            v-model="form.source_account_id"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                        >
                            <option
                                v-for="item in accounts"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{
                                    item.type === "bank" ? t("bank") : t("cash")
                                }}
                                — {{ item.name }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm font-medium"
                        >{{ t("essentialTowards")
                        }}<select
                            v-model="form.destination_account_id"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                        >
                            <option
                                v-for="item in accounts"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{
                                    item.type === "bank" ? t("bank") : t("cash")
                                }}
                                — {{ item.name }}
                            </option></select
                        ><InputError
                            :message="form.errors.destination_account_id"
                    /></label>
                    <label class="text-sm font-medium"
                        >{{ t("essentialDate")
                        }}<input
                            v-model="form.transferred_on"
                            required
                            type="date"
                            :lang="locale"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label
                    ><label class="text-sm font-medium"
                        >{{ t("essentialAmountMad")
                        }}<input
                            v-model="form.amount"
                            required
                            inputmode="decimal"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /><InputError
                            :message="form.errors.amount"
                    /></label>
                    <label class="text-sm font-medium sm:col-span-2"
                        >{{ t("essentialReferenceOptional")
                        }}<input
                            v-model="form.reference"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                    /></label>
                </div>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="min-h-11 rounded-xl border px-4"
                        @click="showTransfer = false"
                    >
                        {{ t("cancel") }}</button
                    ><button
                        :disabled="form.processing"
                        class="min-h-11 rounded-xl bg-teal-700 px-4 font-semibold text-white disabled:opacity-60"
                    >
                        {{
                            form.processing
                                ? t("essentialTransferring")
                                : t("essentialTransferVerb")
                        }}
                    </button>
                </div>
            </form></Modal
        >
    </AuthenticatedLayout>
</template>
