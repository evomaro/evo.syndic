<script setup lang="ts">
import { computed } from "vue";
import { router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import PeriodPicker from "@/Components/PeriodPicker.vue";
import { formatMADCents } from "@/Support/money";
import { useI18n } from "@/i18n";

const props = defineProps<{
    reportType: string;
    rows: any[];
    totalCents: number;
    filters: any;
    period: any;
    residences: any[];
    activeResidenceId: number | null;
    buildings: any[];
    accounts: any[];
    periodHasCharges: boolean;
}>();
const {
    reportType,
    rows,
    totalCents,
    filters,
    period,
    residences,
    activeResidenceId,
    buildings,
    accounts,
} = props;
const { t } = useI18n();
const filter = (event: Event) =>
    router.get(
        route("essential.reports"),
        Object.fromEntries(
            new FormData(event.currentTarget as HTMLFormElement) as any,
        ),
        { preserveState: true, replace: true },
    );
const exportUrl = computed(() =>
    route("essential.reports.export", {
        ...props.filters,
        type: props.reportType,
        period: props.period.value,
        residence_id: props.activeResidenceId,
    }),
);
const labels = computed<Record<string, string>>(() => ({
    unpaid: t("essentialUnpaidCotisations"),
    collections: t("essentialCollections"),
    expenses: t("expenses"),
    movements: t("essentialBankCashMovements"),
}));
const statuses = computed<Record<string, string>>(() => ({
    unpaid: t("essentialUnpaid"),
    partial: t("essentialPartialPaid"),
    paid: t("essentialPaid"),
    bank: t("bank"),
    cash: t("cash"),
    credit: t("essentialEntry"),
    debit: t("essentialExit"),
    validated: t("validated"),
}));
</script>

<template>
    <AuthenticatedLayout
        :title="t('reports')"
        :subtitle="t('essentialReportsSubtitle')"
    >
        <div class="space-y-5">
            <form
                class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5"
                @change="filter"
            >
                <label class="text-sm font-medium"
                    >{{ t("essentialReport")
                    }}<select
                        name="type"
                        :value="reportType"
                        class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                    >
                        <option value="unpaid">
                            {{ t("essentialUnpaidCotisations") }}
                        </option>
                        <option value="collections">
                            {{ t("essentialCollections") }}
                        </option>
                        <option value="expenses">{{ t("expenses") }}</option>
                        <option value="movements">
                            {{ t("essentialBankCashMovements") }}
                        </option>
                    </select></label
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
                    >{{ t("essentialMonth")
                    }}<PeriodPicker
                        name="period"
                        :model-value="period.value"
                        class="mt-1 w-full"
                /></label>
                <label
                    v-if="reportType === 'unpaid'"
                    class="text-sm font-medium"
                    >{{ t("building")
                    }}<select
                        name="building_id"
                        :value="filters.building_id"
                        class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                    >
                        <option value="">{{ t("all") }}</option>
                        <option
                            v-for="item in buildings"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }}
                        </option>
                    </select></label
                >
                <label
                    v-if="reportType === 'movements'"
                    class="text-sm font-medium"
                    >{{ t("account")
                    }}<select
                        name="account_id"
                        :value="filters.account_id"
                        class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                    >
                        <option value="">{{ t("all") }}</option>
                        <option
                            v-for="item in accounts"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }}
                        </option>
                    </select></label
                >
            </form>
            <section
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4"
                >
                    <div>
                        <h2 class="font-bold">{{ labels[reportType] }}</h2>
                        <p class="text-sm text-slate-500">{{ period.value }}</p>
                    </div>
                    <a
                        :href="exportUrl"
                        class="inline-flex min-h-11 items-center rounded-xl bg-teal-700 px-4 font-semibold text-white"
                        >{{ t("essentialExportCsv") }}</a
                    >
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-[680px] w-full text-sm">
                        <thead class="bg-slate-50 text-start">
                            <tr>
                                <th class="p-4">{{ t("essentialDate") }}</th>
                                <th class="p-4">{{ t("building") }}</th>
                                <th class="p-4">
                                    {{ t("essentialLotDescription") }}
                                </th>
                                <th class="p-4">
                                    {{ t("essentialResidentAccount") }}
                                </th>
                                <th class="p-4">{{ t("status") }}</th>
                                <th class="p-4 text-end">{{ t("amount") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(row, index) in rows" :key="index">
                                <td class="p-4">{{ row.date }}</td>
                                <td class="p-4">{{ row.building || "—" }}</td>
                                <td class="p-4 font-medium">
                                    {{ row.description }}
                                </td>
                                <td class="p-4">{{ row.party || "—" }}</td>
                                <td class="p-4">
                                    {{ statuses[row.status] || row.status }}
                                </td>
                                <td class="p-4 text-end font-semibold">
                                    {{ formatMADCents(row.amount_cents) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot
                            v-if="rows.length"
                            class="border-t-2 border-slate-200 bg-slate-50"
                        >
                            <tr>
                                <td colspan="5" class="p-4 text-end font-bold">
                                    {{ t("essentialTotal") }}
                                </td>
                                <td class="p-4 text-end text-lg font-black">
                                    {{ formatMADCents(totalCents) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div v-if="!rows.length" class="p-8 text-center" role="status">
                    <h3 class="font-bold">{{ t("noResults") }}</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        {{
                            reportType === "unpaid" && !periodHasCharges
                                ? t("essentialNoReportForPeriod")
                                : t("essentialNoReportData")
                        }}
                    </p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
