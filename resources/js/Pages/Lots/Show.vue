<script setup lang="ts">
import { computed, ref } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContactPicker from "@/Components/ContactPicker.vue";
import { useI18n } from "@/i18n";

const props = defineProps<{ lot: any }>();
const { t } = useI18n();
const transferOpen = ref(false);
const occupancyOpen = ref(false);
const transfer = useForm({
    effective_date: new Date().toISOString().slice(0, 10),
    acknowledge_incomplete: false,
    owners: [
        {
            contact_id: null as number | null,
            percentage: 100,
            is_primary: true,
            name: "",
        },
    ],
});
const occupancy = useForm({
    contact_id: null as number | null,
    type: "tenant",
    starts_on: new Date().toISOString().slice(0, 10),
    ends_on: "",
    is_primary_occupant: true,
    notes: "",
    name: "",
});
const total = computed(() =>
    transfer.owners.reduce(
        (sum, owner) => sum + Number(owner.percentage || 0),
        0,
    ),
);
const addOwner = () =>
    transfer.owners.push({
        contact_id: null,
        percentage: 0,
        is_primary: false,
        name: "",
    });
const selectOwner = (index: number, contact: any) =>
    (transfer.owners[index].name = contact.display_name);
const date = (value: string) => new Date(value).toLocaleDateString();
</script>

<template>
    <AuthenticatedLayout
        :title="lot.reference"
        :subtitle="
            [lot.building?.name, lot.entrance?.name, lot.floor?.name]
                .filter(Boolean)
                .join(' · ')
        "
        ><template #actions
            ><Link :href="route('structure.index')" class="btn-secondary"
                >← {{ t("back") }}</Link
            ></template
        >
        <div class="grid gap-5 xl:grid-cols-[1fr_360px]">
            <div class="space-y-5">
                <section class="panel overflow-hidden">
                    <div class="panel-head">
                        <h2 class="font-bold">{{ t("currentOwnership") }}</h2>
                        <button
                            class="btn-primary"
                            @click="transferOpen = true"
                        >
                            {{ t("ownershipTransfer") }}
                        </button>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <div
                            v-for="ownership in lot.ownerships"
                            :key="ownership.id"
                            class="flex items-center gap-4 p-4"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">
                                    {{ ownership.contact.display_name }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ date(ownership.starts_on) }} →
                                    {{
                                        ownership.ends_on
                                            ? date(ownership.ends_on)
                                            : "…"
                                    }}
                                </p>
                            </div>
                            <b>{{ ownership.ownership_percentage }}%</b
                            ><span
                                v-if="ownership.is_primary_contact"
                                class="badge border-teal-200 text-teal-700"
                                >{{ t("primary") }}</span
                            >
                        </div>
                        <p
                            v-if="!lot.ownerships.length"
                            class="p-8 text-center text-sm text-slate-500"
                        >
                            {{ t("noResults") }}
                        </p>
                    </div>
                </section>
                <section class="panel overflow-hidden">
                    <div class="panel-head">
                        <h2 class="font-bold">{{ t("occupancyHistory") }}</h2>
                        <button
                            class="btn-secondary"
                            @click="occupancyOpen = true"
                        >
                            ＋ {{ t("addOccupant") }}
                        </button>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <div
                            v-for="item in lot.occupancies"
                            :key="item.id"
                            class="flex items-center gap-4 p-4"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">
                                    {{ item.contact.display_name }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ date(item.starts_on) }} →
                                    {{
                                        item.ends_on ? date(item.ends_on) : "…"
                                    }}
                                </p>
                            </div>
                            <span class="badge border-slate-200">{{
                                t(item.type)
                            }}</span>
                        </div>
                        <p
                            v-if="!lot.occupancies.length"
                            class="p-8 text-center text-sm text-slate-500"
                        >
                            {{ t("noResults") }}
                        </p>
                    </div>
                </section>
            </div>
            <aside class="panel h-fit p-5">
                <span class="badge border-slate-200">{{ t(lot.type) }}</span>
                <dl class="mt-5 grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-slate-500">{{ t("lotNumber") }}</dt>
                        <dd class="font-bold">{{ lot.lot_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ t("surface") }}</dt>
                        <dd class="font-bold">{{ lot.surface || "—" }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ t("occupancy") }}</dt>
                        <dd class="font-bold">{{ t(lot.occupancy_status) }}</dd>
                    </div>
                </dl>
                <div class="mt-6 border-t pt-5">
                    <h3 class="text-sm font-bold">{{ t("allocations") }}</h3>
                    <div
                        v-for="value in lot.allocation_values"
                        :key="value.id"
                        class="mt-3 flex justify-between text-sm"
                    >
                        <span class="text-slate-500">{{
                            value.allocation_key.name
                        }}</span
                        ><b>{{ value.value }}</b>
                    </div>
                </div>
            </aside>
        </div>
        <div
            v-if="transferOpen"
            class="fixed inset-0 z-50 flex items-end bg-slate-950/50 sm:items-center sm:justify-center"
            @click.self="transferOpen = false"
        >
            <form
                class="max-h-[95vh] w-full overflow-y-auto rounded-t-3xl bg-white p-6 sm:max-w-2xl sm:rounded-2xl"
                @submit.prevent="
                    transfer.post(route('ownerships.transfer', lot.id), {
                        onSuccess: () => (transferOpen = false),
                    })
                "
            >
                <div class="mb-5 flex justify-between">
                    <h2 class="text-xl font-bold">
                        {{ t("ownershipTransfer") }}
                    </h2>
                    <button
                        type="button"
                        class="size-11"
                        @click="transferOpen = false"
                    >
                        ×
                    </button>
                </div>
                <label class="field"
                    ><span class="field-label">{{ t("effectiveDate") }}</span
                    ><input
                        v-model="transfer.effective_date"
                        type="date"
                        required
                /></label>
                <div
                    v-for="(owner, index) in transfer.owners"
                    :key="index"
                    class="mt-4 grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-[1fr_130px]"
                >
                    <ContactPicker
                        v-model="owner.contact_id"
                        @select="selectOwner(index, $event)"
                    /><label class="field"
                        ><span class="field-label">{{ t("percentage") }}</span
                        ><input
                            v-model="owner.percentage"
                            type="number"
                            min=".0001"
                            max="100"
                            step=".0001"
                            required /></label
                    ><label class="flex min-h-11 items-center gap-2 text-sm"
                        ><input v-model="owner.is_primary" type="checkbox" />{{
                            t("primary")
                        }}</label
                    >
                </div>
                <button
                    type="button"
                    class="btn-secondary mt-4"
                    @click="addOwner"
                >
                    ＋ {{ t("addOwner") }}
                </button>
                <div
                    class="mt-5 flex items-center justify-between rounded-xl bg-slate-50 p-4"
                >
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="transfer.acknowledge_incomplete"
                            type="checkbox"
                        />{{ t("incompleteAcknowledgement") }}</label
                    ><b>{{ total }}%</b>
                </div>
                <button class="btn-primary mt-5 w-full">
                    {{ t("preview") }} · {{ t("confirm") }}
                </button>
            </form>
        </div>
        <div
            v-if="occupancyOpen"
            class="fixed inset-0 z-50 flex items-end bg-slate-950/50 sm:items-center sm:justify-center"
            @click.self="occupancyOpen = false"
        >
            <form
                class="w-full rounded-t-3xl bg-white p-6 sm:max-w-xl sm:rounded-2xl"
                @submit.prevent="
                    occupancy.post(route('occupancies.store', lot.id), {
                        onSuccess: () => (occupancyOpen = false),
                    })
                "
            >
                <div class="mb-5 flex justify-between">
                    <h2 class="text-xl font-bold">{{ t("addOccupant") }}</h2>
                    <button
                        type="button"
                        class="size-11"
                        @click="occupancyOpen = false"
                    >
                        ×
                    </button>
                </div>
                <div class="grid gap-4">
                    <ContactPicker
                        v-model="occupancy.contact_id"
                        @select="occupancy.name = $event.display_name"
                    /><label class="field"
                        ><span class="field-label">{{ t("type") }}</span
                        ><select v-model="occupancy.type">
                            <option
                                v-for="kind in [
                                    'owner',
                                    'tenant',
                                    'family_member',
                                    'employee',
                                    'other',
                                ]"
                                :key="kind"
                                :value="kind"
                            >
                                {{ t(kind) }}
                            </option>
                        </select></label
                    ><label class="field"
                        ><span class="field-label">{{
                            t("effectiveDate")
                        }}</span
                        ><input
                            v-model="occupancy.starts_on"
                            type="date"
                            required /></label
                    ><button class="btn-primary">{{ t("save") }}</button>
                </div>
            </form>
        </div></AuthenticatedLayout
    >
</template>
