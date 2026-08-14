<script setup lang="ts">
import { computed, ref } from "vue";
import { Link, router, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import InputError from "@/Components/InputError.vue";
import Modal from "@/Components/Modal.vue";
import Pagination from "@/Components/Pagination.vue";
import { formatMADCents } from "@/Support/money";
import { useI18n } from "@/i18n";

const {
    expenses,
    filters,
    residences,
    activeResidenceId,
    categories,
    suppliers,
    accounts,
    exercises,
} = defineProps<{
    expenses: any;
    filters: any;
    residences: any[];
    activeResidenceId: number | null;
    categories: any[];
    suppliers: any[];
    accounts: any[];
    exercises: any[];
}>();
const { t, locale } = useI18n();
const showForm = ref(false);
const receiptInput = ref<HTMLInputElement | null>(null);
const form = useForm({
    residence_id: activeResidenceId,
    financial_exercise_id: exercises[0]?.id ?? null,
    expense_category_id: categories[0]?.id ?? null,
    supplier_id: null,
    financial_account_id: accounts[0]?.id ?? null,
    date: new Date().toISOString().slice(0, 10),
    description: "",
    amount: "",
    method: "cash",
    supplier_reference: "",
    notes: "",
    receipt: null as File | null,
    idempotency_key: crypto.randomUUID(),
});
const submit = () =>
    form.post(route("essential.expenses.store"), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            form.reset(
                "description",
                "amount",
                "supplier_reference",
                "notes",
                "receipt",
            );
            form.idempotency_key = crypto.randomUUID();
            if (receiptInput.value) receiptInput.value.value = "";
        },
    });
const receiptName = computed(() =>
    form.receipt
        ? form.receipt.name
        : locale.value === "ar"
          ? t("essentialNoFile")
          : t("essentialNoFile"),
);
const filter = (event: Event) =>
    router.get(
        route("essential.expenses"),
        Object.fromEntries(
            new FormData(event.currentTarget as HTMLFormElement) as any,
        ),
        { preserveState: true, replace: true },
    );
</script>

<template>
    <AuthenticatedLayout
        :title="t('expenses')"
        :subtitle="t('essentialExpensesSubtitle')"
    >
        <div class="space-y-5">
            <div class="flex justify-end">
                <button
                    type="button"
                    class="min-h-11 rounded-xl bg-teal-700 px-4 font-semibold text-white"
                    @click="showForm = true"
                >
                    {{ t("essentialAddExpense") }}
                </button>
            </div>
            <form
                class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4"
                @change="filter"
            >
                <label class="text-sm font-medium"
                    >{{ t("residence")
                    }}<select
                        name="residence_id"
                        :value="activeResidenceId"
                        class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                    >
                        <option
                            v-for="item in residences"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }}
                        </option>
                    </select></label
                >
                <label class="text-sm font-medium"
                    >{{ t("essentialFrom")
                    }}<input
                        name="from"
                        type="date"
                        :lang="locale"
                        :value="filters.from"
                        class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                /></label>
                <label class="text-sm font-medium"
                    >{{ t("essentialTo")
                    }}<input
                        name="to"
                        type="date"
                        :lang="locale"
                        :value="filters.to"
                        class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                /></label>
                <label class="text-sm font-medium"
                    >{{ t("category")
                    }}<select
                        name="category_id"
                        :value="filters.category_id"
                        class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                    >
                        <option value="">
                            {{ t("essentialAllFeminine") }}
                        </option>
                        <option
                            v-for="item in categories"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }}
                        </option>
                    </select></label
                >
            </form>
            <div
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <div class="overflow-x-auto">
                    <table class="min-w-[680px] w-full text-sm">
                        <thead class="bg-slate-50 text-start">
                            <tr>
                                <th class="p-4">{{ t("essentialDate") }}</th>
                                <th class="p-4">{{ t("description") }}</th>
                                <th class="p-4">
                                    {{ t("essentialSupplier") }}
                                </th>
                                <th class="p-4">
                                    {{ t("essentialPaidFrom") }}
                                </th>
                                <th class="p-4 text-end">{{ t("amount") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="expense in expenses.data"
                                :key="expense.id"
                            >
                                <td class="p-4">{{ expense.date }}</td>
                                <td class="p-4 font-semibold">
                                    {{ expense.description }}
                                </td>
                                <td class="p-4">{{ expense.supplier }}</td>
                                <td class="p-4">
                                    {{
                                        expense.account === "bank"
                                            ? t("bank")
                                            : expense.account === "cash"
                                              ? t("cash")
                                              : "—"
                                    }}
                                </td>
                                <td class="p-4 text-end font-semibold">
                                    {{ formatMADCents(expense.amount_cents) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="!expenses.data.length"
                    class="p-8 text-center"
                    role="status"
                >
                    <h3 class="font-bold">{{ t("essentialNoExpense") }}</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ t("essentialExpenseFilteredEmpty") }}
                    </p>
                </div>
                <Pagination :links="expenses.links" />
            </div>
        </div>
        <Modal :show="showForm" max-width="xl" @close="showForm = false"
            ><form
                class="space-y-4 p-6"
                enctype="multipart/form-data"
                @submit.prevent="submit"
            >
                <div>
                    <h2 class="text-xl font-bold">
                        {{ t("essentialAddExpense") }}
                    </h2>
                    <p class="text-sm text-slate-500">
                        {{ t("essentialExpenseMovementHelp") }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium"
                        >{{ t("essentialDate")
                        }}<input
                            v-model="form.date"
                            required
                            type="date"
                            :lang="locale"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /><InputError
                            :message="form.errors.date"
                    /></label>
                    <label class="text-sm font-medium"
                        >{{ t("category")
                        }}<select
                            v-model="form.expense_category_id"
                            required
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                            @change="form.supplier_id = null"
                        >
                            <option
                                v-for="item in categories"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option></select
                        ><InputError :message="form.errors.expense_category_id"
                    /></label>
                    <label class="text-sm font-medium sm:col-span-2"
                        >{{ t("description")
                        }}<input
                            v-model="form.description"
                            required
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /><InputError
                            :message="form.errors.description"
                    /></label>
                    <label class="text-sm font-medium"
                        >{{ t("essentialAmountMad")
                        }}<input
                            v-model="form.amount"
                            required
                            inputmode="decimal"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /><InputError
                            :message="form.errors.amount"
                    /></label>
                    <label class="text-sm font-medium"
                        >{{ t("essentialPaidFrom")
                        }}<select
                            v-model="form.financial_account_id"
                            required
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
                            :message="form.errors.financial_account_id"
                    /></label>
                    <label class="text-sm font-medium"
                        ><span class="flex items-center justify-between gap-2"
                            ><span>{{ t("essentialSupplier") }}</span
                            ><Link
                                :href="route('suppliers.create')"
                                target="_blank"
                                class="text-xs font-semibold text-teal-700 underline"
                                >＋ {{ t("essentialAddSupplier") }}</Link
                            ></span
                        ><select
                            v-model="form.supplier_id"
                            required
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                        >
                            <option value="" disabled>
                                {{ t("essentialSelectSupplier") }}
                            </option>
                            <option
                                v-for="item in suppliers"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.legal_name }}
                            </option></select
                        ><InputError :message="form.errors.supplier_id"
                    /></label>
                    <label class="text-sm font-medium"
                        >{{ t("essentialPaymentMethod")
                        }}<select
                            v-model="form.method"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                        >
                            <option value="cash">
                                {{ t("essentialCash") }}
                            </option>
                            <option value="bank_transfer">
                                {{ t("bank_transfer") }}
                            </option>
                            <option value="cheque">{{ t("cheque") }}</option>
                            <option value="direct_debit">
                                {{ t("essentialDirectDebit") }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm font-medium"
                        >{{ t("essentialSupplierReference")
                        }}<input
                            v-model="form.supplier_reference"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                    /></label>
                    <label class="text-sm font-medium"
                        >{{ t("essentialReceipt")
                        }}<span
                            class="mt-1 flex min-h-11 items-center gap-3 rounded-xl border border-slate-300 bg-white p-2"
                            ><span
                                class="shrink-0 rounded-lg bg-slate-100 px-3 py-2 font-semibold text-slate-800"
                                >{{ t("essentialChooseFile") }}</span
                            ><span class="min-w-0 truncate text-slate-500">{{
                                receiptName
                            }}</span
                            ><input
                                ref="receiptInput"
                                required
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png"
                                class="sr-only"
                                @change="
                                    form.receipt =
                                        ($event.target as HTMLInputElement)
                                            .files?.[0] ?? null
                                " /></span
                        ><InputError :message="form.errors.receipt"
                    /></label>
                </div>
                <label class="block text-sm font-medium"
                    >{{ t("essentialNotesOptional")
                    }}<textarea
                        v-model="form.notes"
                        rows="2"
                        class="mt-1 block w-full rounded-xl border-slate-300"
                    ></textarea>
                </label>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="min-h-11 rounded-xl border px-4"
                        @click="showForm = false"
                    >
                        {{ t("cancel") }}</button
                    ><button
                        :disabled="form.processing"
                        class="min-h-11 rounded-xl bg-teal-700 px-4 font-semibold text-white disabled:opacity-60"
                    >
                        {{ form.processing ? t("essentialSaving") : t("save") }}
                    </button>
                </div>
            </form></Modal
        >
    </AuthenticatedLayout>
</template>
