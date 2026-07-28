<script setup lang="ts">
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
import { formatMADCents } from "@/Support/money";
const props = defineProps<{
    stats: Record<string, number>;
    isResidence: boolean;
    activity: any[];
    finance?: any[] | null;
    expenses?: any[] | null;
    helpProgress: { completed: number; total: number };
    nextSetupStep?: {
        id: string;
        title: string;
        purpose: string;
        href: string;
        help_href: string;
    } | null;
}>();
const { t } = useI18n();
type DashboardCard = {
    stat: string;
    label: string;
    routeName: string;
    params?: Record<string, string>;
};
const cards = computed<DashboardCard[]>(() =>
    props.isResidence
        ? [
              {
                  stat: "buildings",
                  label: "buildings",
                  routeName: "structure.index",
              },
              { stat: "lots", label: "lots", routeName: "structure.index" },
              { stat: "owners", label: "owners", routeName: "contacts.index" },
              {
                  stat: "occupants",
                  label: "occupants",
                  routeName: "contacts.index",
              },
              {
                  stat: "vacant",
                  label: "vacantLots",
                  routeName: "structure.index",
                  params: { status: "vacant" },
              },
              {
                  stat: "missing_owners",
                  label: "missingOwners",
                  routeName: "structure.index",
                  params: { status: "missing_owner" },
              },
              {
                  stat: "missing_allocations",
                  label: "missingAllocations",
                  routeName: "allocations.index",
              },
          ]
        : [
              {
                  stat: "residences",
                  label: "residences",
                  routeName: "residences.index",
              },
              { stat: "lots", label: "lots", routeName: "structure.index" },
              {
                  stat: "contacts",
                  label: "contacts",
                  routeName: "contacts.index",
              },
              { stat: "setup", label: "setup", routeName: "residences.index" },
              {
                  stat: "invitations",
                  label: "invitations",
                  routeName: "team.index",
              },
          ],
);
</script>
<template>
    <AuthenticatedLayout
        :title="t('dashboard')"
        :subtitle="isResidence ? t('setupChecklist') : t('organization')"
        ><section
            v-if="nextSetupStep"
            class="mb-6 overflow-hidden rounded-3xl bg-slate-950 p-6 text-white shadow-xl sm:p-8"
        >
            <div
                class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="max-w-2xl">
                    <p
                        class="text-xs font-bold uppercase tracking-[.18em] text-teal-300"
                    >
                        {{ t("nextStep") }}
                    </p>
                    <h2 class="mt-2 text-2xl font-bold">
                        {{ nextSetupStep.title }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-300">
                        {{ nextSetupStep.purpose }}
                    </p>
                </div>
                <Link
                    :href="nextSetupStep.href"
                    class="inline-flex min-h-12 shrink-0 items-center justify-center rounded-xl bg-teal-400 px-5 font-bold text-slate-950 hover:bg-teal-300"
                >
                    {{ t("startStep") }} →
                </Link>
            </div>
            <div
                class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-white/10 pt-4 text-sm"
            >
                <span class="text-slate-400">
                    {{ helpProgress.completed }}/{{ helpProgress.total }}
                    {{ t("stepsCompleted") }}
                </span>
                <Link
                    :href="route('help.index', 'first-use-checklist')"
                    class="font-semibold text-teal-300 hover:text-teal-200"
                >
                    {{ t("viewAllSteps") }}
                </Link>
            </div>
        </section>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Link
                v-for="card in cards"
                :key="card.stat"
                :href="route(card.routeName, card.params)"
                class="stat group relative"
                ><p
                    class="text-xs font-semibold uppercase tracking-wide text-slate-500"
                >
                    {{ t(card.label) }}
                </p>
                <p
                    class="mt-3 text-3xl font-bold tracking-tight text-slate-950"
                >
                    {{ stats[card.stat] ?? 0 }}
                </p>
                <span
                    class="absolute end-4 top-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-teal-700"
                    aria-hidden="true"
                    >→</span
                ></Link
            >
        </div>
        <section v-if="finance?.length" class="panel mt-6 overflow-hidden">
            <div class="panel-head">
                <h2 class="font-bold">{{ t("finance") }}</h2>
                <Link
                    :href="route('finance.index')"
                    class="text-sm font-semibold text-teal-700"
                    >{{ t("details") }} →</Link
                >
            </div>
            <div
                class="grid divide-y sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-3"
            >
                <Link
                    v-for="row in finance"
                    :key="row.id"
                    :href="route('finance.index')"
                    class="p-5"
                    ><b>{{ row.name }}</b>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <span class="text-slate-500"
                            >{{ t("amountCollected")
                            }}<b class="block text-slate-900"
                                >{{ formatMADCents(row.collected_cents) }}
                            </b></span
                        ><span class="text-slate-500"
                            >{{ t("outstandingAmount")
                            }}<b class="block text-red-700"
                                >{{ formatMADCents(row.outstanding_cents) }}
                            </b></span
                        >
                    </div></Link
                >
            </div>
        </section>
        <section v-if="expenses?.length" class="panel mt-6 overflow-hidden">
            <div class="panel-head">
                <h2 class="font-bold">{{ t("expenses") }}</h2>
                <Link
                    :href="route('expenses.index')"
                    class="text-sm font-semibold text-teal-700"
                    >{{ t("details") }} →</Link
                >
            </div>
            <div class="grid sm:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="row in expenses"
                    :key="row.id"
                    :href="route('expenses.index')"
                    class="border-b p-5 sm:border-e"
                    ><b>{{ row.name }}</b>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <span class="text-slate-500"
                            >Budget<b class="block text-slate-900"
                                >{{ formatMADCents(row.budget_cents) }}
                            </b></span
                        ><span class="text-slate-500"
                            >Réel<b
                                class="block"
                                :class="
                                    row.actual_cents > row.budget_cents
                                        ? 'text-red-700'
                                        : 'text-slate-900'
                                "
                                >{{ formatMADCents(row.actual_cents) }}
                            </b></span
                        ><span class="text-slate-500"
                            >À payer<b class="block text-slate-900"
                                >{{ formatMADCents(row.payable_cents) }}
                            </b></span
                        ><span class="text-slate-500"
                            >Contrats à renouveler<b
                                class="block text-slate-900"
                                >{{ row.expiring_contracts }}</b
                            ></span
                        >
                    </div></Link
                >
            </div>
        </section>
        <section class="panel mt-6">
            <div class="panel-head">
                <h2 class="font-bold">{{ t("recentActivity") }}</h2>
                <Link
                    :href="route('activity.index')"
                    class="text-sm font-semibold text-teal-700"
                    >{{ t("all") }} →</Link
                >
            </div>
            <div v-if="activity.length" class="divide-y divide-slate-100">
                <div
                    v-for="item in activity"
                    :key="item.id"
                    class="flex items-center gap-3 px-5 py-4 text-sm"
                >
                    <span
                        class="grid size-9 place-items-center rounded-full bg-slate-100"
                        >◷</span
                    >
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">
                            {{ item.description }}
                        </p>
                        <p class="text-xs text-slate-500">
                            {{ new Date(item.created_at).toLocaleString() }}
                        </p>
                    </div>
                </div>
            </div>
            <p v-else class="p-8 text-center text-sm text-slate-500">
                {{ t("noResults") }}
            </p>
        </section>
        <section
            v-if="
                isResidence &&
                (stats.missing_owners || stats.missing_allocations)
            "
            class="panel mt-6 p-5"
        >
            <h2 class="font-bold">{{ t("setupChecklist") }}</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <Link
                    :href="
                        route('structure.index', { status: 'missing_owner' })
                    "
                    class="flex min-h-14 items-center justify-between rounded-xl bg-slate-50 px-4 text-sm font-semibold"
                    ><span
                        >{{ stats.missing_owners ? "!" : "✓" }}
                        {{ t("missingOwners") }}</span
                    ><span>→</span></Link
                >
                <Link
                    :href="route('allocations.index')"
                    class="flex min-h-14 items-center justify-between rounded-xl bg-slate-50 px-4 text-sm font-semibold"
                    ><span
                        >{{ stats.missing_allocations ? "!" : "✓" }}
                        {{ t("missingAllocations") }}</span
                    ><span>→</span></Link
                >
            </div>
        </section></AuthenticatedLayout
    >
</template>
