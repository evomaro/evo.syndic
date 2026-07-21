<script setup lang="ts">
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
const props = defineProps<{
    stats: Record<string, number>;
    isResidence: boolean;
    activity: any[];
    finance?: any[] | null;
}>();
const { t } = useI18n();
const cards = computed(() =>
    props.isResidence
        ? [
              ["buildings", "buildings", "structure.index"],
              ["lots", "lots", "structure.index"],
              ["owners", "owners", "contacts.index"],
              ["occupants", "occupants", "contacts.index"],
              ["vacant", "vacantLots", "structure.index"],
              ["missing_owners", "missingOwners", "structure.index"],
              [
                  "missing_allocations",
                  "missingAllocations",
                  "allocations.index",
              ],
          ]
        : [
              ["residences", "residences", "residences.index"],
              ["lots", "lots", "structure.index"],
              ["contacts", "contacts", "contacts.index"],
              ["setup", "setup", "residences.index"],
              ["invitations", "invitations", "team.index"],
          ],
);
</script>
<template>
    <AuthenticatedLayout
        :title="t('dashboard')"
        :subtitle="isResidence ? t('setupChecklist') : t('organization')"
        ><div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Link
                v-for="card in cards"
                :key="card[0]"
                :href="route(card[2])"
                class="stat"
                ><p
                    class="text-xs font-semibold uppercase tracking-wide text-slate-500"
                >
                    {{ t(card[1]) }}
                </p>
                <p
                    class="mt-3 text-3xl font-bold tracking-tight text-slate-950"
                >
                    {{ stats[card[0]] ?? 0 }}
                </p></Link
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
                                >{{
                                    (row.collected_cents / 100).toLocaleString()
                                }}
                                MAD</b
                            ></span
                        ><span class="text-slate-500"
                            >{{ t("outstandingAmount")
                            }}<b class="block text-red-700"
                                >{{
                                    (
                                        row.outstanding_cents / 100
                                    ).toLocaleString()
                                }}
                                MAD</b
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
                    :href="route('structure.index')"
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
