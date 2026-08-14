<script setup lang="ts">
import { computed, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Pagination from "@/Components/Pagination.vue";
import InfoTooltip from "@/Components/InfoTooltip.vue";
import PeriodPicker from "@/Components/PeriodPicker.vue";
import CotisationGenerator from "@/Components/Essential/CotisationGenerator.vue";
import MultiMonthPayment from "@/Components/Essential/MultiMonthPayment.vue";
import { formatMADCents } from "@/Support/money";
import { useI18n } from "@/i18n";

const {
    cotisations,
    filters,
    period,
    residences,
    activeResidenceId,
    buildings,
    accounts,
    exercises,
    periodHasCharges,
    generation,
    issuedCotisations,
    canManageLots,
} = defineProps<{
    cotisations: any;
    filters: any;
    period: any;
    residences: any[];
    activeResidenceId: number | null;
    buildings: any[];
    accounts: any[];
    exercises: any[];
    periodHasCharges: boolean;
    generation: any;
    issuedCotisations: any[];
    canManageLots: boolean;
}>();
const selected = ref<any>(null);
const { t } = useI18n();
const generationOpen = ref(!!generation.open);
const filter = (event: Event) => {
    const form = event.currentTarget as HTMLFormElement;
    router.get(
        route("essential.cotisations"),
        Object.fromEntries(new FormData(form) as any),
        { preserveState: true, replace: true },
    );
};
const cancelCotisation = (item: any) => {
    if (
        !item.can_cancel ||
        !window.confirm(t("essentialCancelConfirm", { period: item.period }))
    )
        return;
    router.post(route("essential.cotisations.cancel", item.id), {
        reason: "Annulation depuis le mode Essential",
    });
};
const statusLabel = computed(
    () =>
        ({
            paid: t("essentialPaid"),
            partial: t("essentialPartialPaid"),
            unpaid: t("essentialUnpaid"),
        }) as Record<string, string>,
);
</script>

<template>
    <AuthenticatedLayout
        :title="t('essentialCotisations')"
        :subtitle="t('essentialCotisationsSubtitle', { period: period.value })"
    >
        <template #actions>
            <button
                v-if="generation.can_generate"
                type="button"
                class="btn-primary"
                @click="generationOpen = true"
            >
                ＋ {{ t("essentialGenerateCotisation") }}
            </button>
        </template>
        <div class="space-y-5">
            <form
                class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5"
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
                <label class="text-sm font-medium"
                    >{{ t("essentialMonth")
                    }}<PeriodPicker
                        name="period"
                        :model-value="period.value"
                        class="mt-1 w-full"
                /></label>
                <label class="text-sm font-medium"
                    >{{ t("status")
                    }}<select
                        name="status"
                        :value="filters.status"
                        class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                    >
                        <option value="">{{ t("all") }}</option>
                        <option value="paid">{{ t("essentialPaid") }}</option>
                        <option value="partial">
                            {{ t("essentialPartialPaid") }}
                        </option>
                        <option value="unpaid">
                            {{ t("essentialUnpaid") }}
                        </option>
                    </select></label
                >
            </form>
            <div
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm sm:min-w-[760px]">
                        <thead class="bg-slate-50 text-start text-slate-600">
                            <tr>
                                <th class="p-2 sm:p-4">{{ t("lots") }}</th>
                                <th class="hidden p-4 sm:table-cell">
                                    {{ t("essentialBillingContact") }}
                                    <InfoTooltip term="billing_contact" />
                                </th>
                                <th class="hidden p-4 text-end sm:table-cell">
                                    {{ t("essentialExpected") }}
                                </th>
                                <th class="hidden p-4 text-end sm:table-cell">
                                    {{ t("essentialPaid") }}
                                </th>
                                <th class="p-2 text-end sm:p-4">
                                    {{ t("essentialRemaining") }}
                                    <InfoTooltip term="remaining" />
                                </th>
                                <th class="hidden p-4 sm:table-cell">
                                    {{ t("status") }}
                                    <InfoTooltip term="payment_status" />
                                </th>
                                <th class="p-2 sm:p-4">
                                    <span class="sr-only">{{
                                        t("essentialAction")
                                    }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in cotisations.data" :key="row.id">
                                <td class="p-2 font-semibold sm:p-4">
                                    {{ row.lot }}
                                    <span
                                        class="block text-xs font-normal text-slate-500"
                                        >{{ row.building }}</span
                                    >
                                    <span
                                        class="mt-1 block text-xs sm:hidden"
                                        >{{ statusLabel[row.status] }}</span
                                    >
                                </td>
                                <td class="hidden p-4 sm:table-cell">
                                    {{
                                        row.resident ||
                                        t("essentialNotProvided")
                                    }}
                                </td>
                                <td class="hidden p-4 text-end sm:table-cell">
                                    {{ formatMADCents(row.expected_cents) }}
                                </td>
                                <td class="hidden p-4 text-end sm:table-cell">
                                    {{ formatMADCents(row.paid_cents) }}
                                </td>
                                <td class="p-2 text-end font-semibold sm:p-4">
                                    {{ formatMADCents(row.remaining_cents) }}
                                </td>
                                <td class="hidden p-4 sm:table-cell">
                                    <span
                                        class="rounded-full px-2 py-1 text-xs font-bold"
                                        :class="
                                            row.status === 'paid'
                                                ? 'bg-emerald-100 text-emerald-800'
                                                : row.status === 'partial'
                                                  ? 'bg-amber-100 text-amber-800'
                                                  : 'bg-rose-100 text-rose-800'
                                        "
                                        >{{ statusLabel[row.status] }}</span
                                    >
                                </td>
                                <td class="p-2 sm:p-4">
                                    <button
                                        v-if="
                                            row.remaining_cents > 0 &&
                                            row.can_record_payment
                                        "
                                        type="button"
                                        class="min-h-11 rounded-xl bg-teal-700 px-3 font-semibold text-white"
                                        @click="selected = row"
                                    >
                                        {{ t("essentialPayment") }}
                                    </button>
                                    <Link
                                        v-else-if="
                                            row.remaining_cents > 0 &&
                                            canManageLots
                                        "
                                        :href="route('lots.show', row.lot_id)"
                                        class="inline-flex min-h-11 items-center rounded-xl border border-amber-300 bg-amber-50 px-3 text-xs font-semibold text-amber-900"
                                        :title="t('essentialOwnerRequiredHelp')"
                                    >
                                        {{ t("essentialAssignOwner") }}
                                    </Link>
                                    <span
                                        v-else-if="row.remaining_cents > 0"
                                        class="inline-block max-w-40 text-xs text-amber-800"
                                        :title="t('essentialOwnerRequiredHelp')"
                                    >
                                        {{ t("essentialOwnerRequired") }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="!cotisations.data.length"
                    class="p-8 text-center"
                    role="status"
                >
                    <h3 class="font-bold">{{ t("essentialNoCotisation") }}</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        {{
                            periodHasCharges
                                ? t("essentialCotisationFilteredEmpty")
                                : t("essentialCotisationPeriodEmpty")
                        }}
                    </p>
                    <button
                        v-if="!periodHasCharges && generation.can_generate"
                        type="button"
                        class="mt-4 min-h-11 rounded-xl bg-teal-700 px-4 font-semibold text-white"
                        @click="generationOpen = true"
                    >
                        {{ t("essentialGenerateForMonth") }}
                    </button>
                </div>
                <Pagination :links="cotisations.links" />
            </div>

            <section
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <div class="border-b border-slate-100 p-4">
                    <h2 class="font-bold">
                        {{ t("essentialIssuedCotisations") }}
                    </h2>
                    <p class="text-sm text-slate-500">
                        {{ t("essentialIssuedCotisationsHelp") }}
                    </p>
                </div>
                <div class="divide-y divide-slate-100">
                    <div
                        v-for="item in issuedCotisations"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-3 p-4 text-sm"
                    >
                        <div>
                            <strong>{{ item.period }}</strong>
                            <p class="text-slate-500">
                                {{ formatMADCents(item.total_cents) }} ·
                                {{
                                    t("essentialCreatedOn", {
                                        date: item.created_at,
                                    })
                                }}
                            </p>
                        </div>
                        <div class="text-end">
                            <button
                                v-if="
                                    generation.can_cancel &&
                                    item.status !== 'cancelled'
                                "
                                type="button"
                                class="min-h-11 rounded-xl border px-3 font-semibold disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!item.can_cancel"
                                :title="item.cancel_blocked_reason"
                                @click="cancelCotisation(item)"
                            >
                                {{ t("cancel") }}
                            </button>
                            <span
                                v-else-if="item.status === 'cancelled'"
                                class="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600"
                                >{{ t("essentialCancelled") }}</span
                            >
                            <p
                                v-if="item.cancel_blocked_reason"
                                class="mt-1 max-w-sm text-xs text-amber-800"
                            >
                                {{ item.cancel_blocked_reason }}
                            </p>
                        </div>
                    </div>
                    <p
                        v-if="!issuedCotisations.length"
                        class="p-6 text-center text-sm text-slate-500"
                    >
                        {{ t("essentialNoIssuedCotisation") }}
                    </p>
                </div>
            </section>
        </div>
        <CotisationGenerator
            :show="generationOpen"
            :period="period.value"
            :active-residence-id="activeResidenceId"
            :generation="generation"
            @close="generationOpen = false"
        />
        <MultiMonthPayment
            :selected="selected"
            :accounts="accounts"
            :exercises="exercises"
            :active-residence-id="activeResidenceId"
            @close="selected = null"
        />
    </AuthenticatedLayout>
</template>
