<script setup lang="ts">
import { ref, computed } from "vue";
import { Link, router, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
import Pagination from "@/Components/Pagination.vue";
const p = defineProps<{ buildings: any[]; lots: any; filters: any }>();
const { t } = useI18n();
const tab = ref<"lot" | "building" | "bulk" | null>(null);
const search = ref(p.filters.search ?? "");
const building = useForm({ name: "", code: "" });
const lot = useForm({
    reference: "",
    lot_number: "",
    type: "apartment",
    building_id: "",
    entrance_id: "",
    floor_id: "",
    title: "",
    surface: "",
    property_title_number: "",
    notes: "",
    active: true,
});
const bulk = useForm({
    prefix: "APT-",
    starting_number: 1,
    quantity: 10,
    type: "apartment",
    building_id: "",
    floor_id: "",
    confirm: true,
});
const preview = computed(() =>
    Array.from(
        { length: Math.min(bulk.quantity, 20) },
        (_, i) => bulk.prefix + (bulk.starting_number + i),
    ),
);
const runSearch = () =>
    router.get(
        route("structure.index"),
        { ...p.filters, search: search.value },
        { preserveState: true, replace: true },
    );
</script>
<template>
    <AuthenticatedLayout
        :title="t('structure')"
        :subtitle="t('structureSubtitle')"
        ><template #actions
            ><div class="flex gap-2">
                <button class="btn-secondary" @click="tab = 'bulk'">
                    {{ t("bulkLots") }}</button
                ><button class="btn-primary" @click="tab = 'lot'">
                    ＋ {{ t("addLot") }}
                </button>
            </div></template
        >
        <div class="panel mb-5 flex gap-3 p-3">
            <input
                v-model="search"
                class="min-w-0 flex-1"
                :placeholder="t('search')"
                @keyup.enter="runSearch"
            /><button class="btn-secondary" @click="runSearch">
                {{ t("search") }}
            </button>
        </div>
        <div
            v-if="p.filters.status"
            class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900"
        >
            <span>
                {{
                    p.filters.status === "vacant"
                        ? t("vacantLotsFilter")
                        : t("missingOwnersFilter")
                }}
            </span>
            <Link
                :href="route('structure.index')"
                class="font-semibold underline underline-offset-2"
            >
                {{ t("clearFilter") }}
            </Link>
        </div>
        <div class="grid gap-5 xl:grid-cols-[300px_1fr]">
            <aside class="panel overflow-hidden">
                <div class="panel-head">
                    <h2 class="font-bold">{{ t("buildings") }}</h2>
                    <button
                        class="text-xl text-teal-700"
                        @click="tab = 'building'"
                    >
                        ＋
                    </button>
                </div>
                <div class="divide-y divide-slate-100">
                    <details
                        v-for="b in buildings"
                        :key="b.id"
                        class="group p-4"
                    >
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between font-semibold"
                        >
                            <span>{{ b.code }} · {{ b.name }}</span
                            ><span class="text-slate-400 group-open:rotate-90"
                                >›</span
                            >
                        </summary>
                        <div class="mt-3 space-y-2 ps-3 text-sm text-slate-500">
                            <p v-for="e in b.entrances" :key="e.id">
                                ↳ {{ t("entrance") }} {{ e.name }}
                            </p>
                            <p v-for="f in b.floors" :key="f.id">
                                ↳ {{ t("floor") }} {{ f.name }}
                            </p>
                        </div>
                    </details>
                    <p
                        v-if="!buildings.length"
                        class="p-6 text-center text-sm text-slate-500"
                    >
                        {{ t("noResults") }}
                    </p>
                </div>
            </aside>
            <section>
                <div
                    class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white md:block"
                >
                    <table class="w-full text-start text-sm">
                        <thead
                            class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"
                        >
                            <tr>
                                <th class="p-4 text-start">
                                    {{ t("reference") }}
                                </th>
                                <th class="p-4 text-start">{{ t("type") }}</th>
                                <th class="p-4 text-start">
                                    {{ t("building") }}
                                </th>
                                <th class="p-4 text-start">
                                    {{ t("surface") }}
                                </th>
                                <th class="p-4 text-start">
                                    {{ t("occupancy") }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="item in lots.data"
                                :key="item.id"
                                class="hover:bg-slate-50"
                            >
                                <td class="p-4 font-semibold">
                                    <Link
                                        :href="route('lots.show', item.id)"
                                        class="hover:text-teal-700"
                                        >{{ item.reference }}</Link
                                    >
                                    <p
                                        class="text-xs font-normal text-slate-500"
                                    >
                                        {{ item.lot_number }}
                                    </p>
                                </td>
                                <td class="p-4">{{ t(item.type) }}</td>
                                <td class="p-4">
                                    {{ item.building?.name || "—" }}
                                </td>
                                <td class="p-4">{{ item.surface || "—" }}</td>
                                <td class="p-4">
                                    <span class="badge border-slate-200">{{
                                        t(item.occupancy_status)
                                    }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="grid gap-3 md:hidden">
                    <Link
                        v-for="item in lots.data"
                        :key="item.id"
                        :href="route('lots.show', item.id)"
                        class="panel p-4"
                    >
                        <div class="flex justify-between">
                            <h3 class="font-bold">{{ item.reference }}</h3>
                            <span class="badge border-slate-200">{{
                                t(item.type)
                            }}</span>
                        </div>
                        <div
                            class="mt-4 grid grid-cols-2 gap-3 text-sm text-slate-500"
                        >
                            <p>
                                {{ t("building")
                                }}<b class="block text-slate-900">{{
                                    item.building?.name || "—"
                                }}</b>
                            </p>
                            <p>
                                {{ t("occupancy")
                                }}<b class="block text-slate-900">{{
                                    t(item.occupancy_status)
                                }}</b>
                            </p>
                        </div>
                    </Link>
                </div>
            </section>
        </div>
        <div
            v-if="tab"
            class="fixed inset-0 z-50 flex items-end bg-slate-950/50 sm:items-center sm:justify-center"
            @click.self="tab = null"
        >
            <div
                class="max-h-[95vh] w-full overflow-y-auto rounded-t-3xl bg-white p-6 sm:max-w-xl sm:rounded-2xl"
            >
                <div class="mb-6 flex justify-between">
                    <h2 class="text-xl font-bold">
                        {{
                            t(
                                tab === "lot"
                                    ? "addLot"
                                    : tab === "bulk"
                                      ? "bulkLots"
                                      : "addBuilding",
                            )
                        }}
                    </h2>
                    <button class="size-11" @click="tab = null">×</button>
                </div>
                <form
                    v-if="tab === 'building'"
                    class="grid gap-4"
                    @submit.prevent="
                        building.post(route('buildings.store'), {
                            onSuccess: () => (tab = null),
                        })
                    "
                >
                    <label class="field"
                        ><span class="field-label">{{ t("building") }}</span
                        ><input v-model="building.name" required /></label
                    ><label class="field"
                        ><span class="field-label">{{ t("code") }}</span
                        ><input v-model="building.code" required /></label
                    ><button class="btn-primary">{{ t("create") }}</button>
                </form>
                <form
                    v-else-if="tab === 'lot'"
                    class="grid gap-4 sm:grid-cols-2"
                    @submit.prevent="
                        lot.post(route('lots.store'), {
                            onSuccess: () => (tab = null),
                        })
                    "
                >
                    <label class="field"
                        ><span class="field-label">{{ t("reference") }}</span
                        ><input v-model="lot.reference" required /></label
                    ><label class="field"
                        ><span class="field-label">{{ t("lotNumber") }}</span
                        ><input v-model="lot.lot_number" required /></label
                    ><label class="field"
                        ><span class="field-label">{{ t("type") }}</span
                        ><select v-model="lot.type">
                            <option
                                v-for="x in [
                                    'apartment',
                                    'villa',
                                    'shop',
                                    'office',
                                    'garage',
                                    'parking',
                                    'storage',
                                    'other',
                                ]"
                                :key="x"
                            >
                                {{ x }}
                            </option>
                        </select></label
                    ><label class="field"
                        ><span class="field-label">{{ t("building") }}</span
                        ><select v-model="lot.building_id">
                            <option value="">—</option>
                            <option
                                v-for="b in buildings"
                                :key="b.id"
                                :value="b.id"
                            >
                                {{ b.name }}
                            </option>
                        </select></label
                    ><label class="field"
                        ><span class="field-label">{{ t("surface") }}</span
                        ><input
                            v-model="lot.surface"
                            type="number"
                            min="0"
                            step=".01" /></label
                    ><button class="btn-primary self-end">
                        {{ t("create") }}
                    </button>
                </form>
                <form
                    v-else
                    class="grid gap-4"
                    @submit.prevent="
                        bulk.post(route('lots.bulk'), {
                            onSuccess: () => (tab = null),
                        })
                    "
                >
                    <div class="grid grid-cols-2 gap-3">
                        <label class="field"
                            ><span class="field-label">{{ t("prefix") }}</span
                            ><input v-model="bulk.prefix" required /></label
                        ><label class="field"
                            ><span class="field-label">{{
                                t("startingNumber")
                            }}</span
                            ><input
                                v-model="bulk.starting_number"
                                type="number"
                                min="0" /></label
                        ><label class="field"
                            ><span class="field-label">{{ t("quantity") }}</span
                            ><input
                                v-model="bulk.quantity"
                                type="number"
                                min="1"
                                max="500" /></label
                        ><label class="field"
                            ><span class="field-label">{{ t("building") }}</span
                            ><select v-model="bulk.building_id">
                                <option value="">—</option>
                                <option
                                    v-for="b in buildings"
                                    :key="b.id"
                                    :value="b.id"
                                >
                                    {{ b.name }}
                                </option>
                            </select></label
                        >
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p
                            class="mb-2 text-xs font-bold uppercase text-slate-500"
                        >
                            {{ t("preview") }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="x in preview"
                                :key="x"
                                class="badge border-slate-200 bg-white"
                                >{{ x }}</span
                            ><span v-if="bulk.quantity > 20"
                                >+{{ bulk.quantity - 20 }}</span
                            >
                        </div>
                    </div>
                    <button class="btn-primary">
                        {{ t("confirm") }} · {{ bulk.quantity }}
                    </button>
                </form>
            </div>
        </div>
        <Pagination :links="lots.links"
    /></AuthenticatedLayout>
</template>
