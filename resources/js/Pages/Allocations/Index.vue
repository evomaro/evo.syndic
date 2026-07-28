<script setup lang="ts">
import { ref, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
import Pagination from "@/Components/Pagination.vue";
import InfoTooltip from "@/Components/InfoTooltip.vue";
const p = defineProps<{ keys: any[]; lots: any }>();
const { t } = useI18n();
const selected = ref(p.keys[0]?.id);
const key = computed(() => p.keys.find((k) => k.id === selected.value));
const displayedLots = computed(() =>
    key.value?.applies_to_all_lots
        ? p.lots.data
        : p.lots.data.filter((lot: any) =>
              key.value?.lots?.some(
                  (selectedLot: any) => selectedLot.id === lot.id,
              ),
          ),
);
const form = useForm({
    values: displayedLots.value.map((lot: any) => ({
        lot_id: lot.id,
        value:
            lot.allocation_values.find(
                (v: any) => v.allocation_key_id === selected.value,
            )?.value ?? 0,
    })),
});
const refresh = () =>
    (form.values = displayedLots.value.map((lot: any) => ({
        lot_id: lot.id,
        value:
            lot.allocation_values.find(
                (v: any) => v.allocation_key_id === selected.value,
            )?.value ?? 0,
    })));
const total = computed(() =>
    form.values.reduce(
        (s: number, v: { value: number | string }) => s + Number(v.value || 0),
        0,
    ),
);
const createForm = useForm({
    name: "",
    code: "",
    type: "special",
    expected_total: "",
    applies_to_all_lots: false,
    lot_ids: [] as number[],
});
const bulk = useForm({ paste: "" });
</script>
<template>
    <AuthenticatedLayout :title="t('allocations')"
        ><p class="mb-5 text-sm text-slate-600">
            {{ t("allocations") }}
            <InfoTooltip term="tantiemes" />
            · {{ t("distribution") }}
            <InfoTooltip term="distribution_key" />
        </p>
        <div class="grid gap-5 xl:grid-cols-[320px_1fr]">
            <aside class="space-y-3">
                <form
                    class="panel grid gap-3 p-4"
                    @submit.prevent="
                        createForm.post(route('allocations.store'), {
                            onSuccess: () => createForm.reset(),
                        })
                    "
                >
                    <h2 class="font-bold">{{ t("newKey") }}</h2>
                    <input
                        v-model="createForm.name"
                        :placeholder="t('newKey')"
                        required
                    />
                    <input
                        v-model="createForm.code"
                        :placeholder="t('code')"
                        required
                    />
                    <select v-model="createForm.type">
                        <option value="general">{{ t("general") }}</option>
                        <option value="special">{{ t("special") }}</option>
                    </select>
                    <input
                        v-model="createForm.expected_total"
                        type="number"
                        min="0"
                        step=".0001"
                        :placeholder="t('expected')"
                    />
                    <label
                        v-if="createForm.type === 'special'"
                        class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="createForm.applies_to_all_lots"
                            type="checkbox"
                        />{{ t("all") }}</label
                    >
                    <div
                        v-if="
                            createForm.type === 'special' &&
                            !createForm.applies_to_all_lots
                        "
                        class="max-h-36 overflow-auto rounded-lg border p-2"
                    >
                        <label
                            v-for="lot in lots.data"
                            :key="lot.id"
                            class="flex min-h-10 items-center gap-2 text-sm"
                            ><input
                                v-model="createForm.lot_ids"
                                type="checkbox"
                                :value="lot.id"
                            />{{ lot.reference }}</label
                        >
                    </div>
                    <button
                        class="btn-primary"
                        :disabled="createForm.processing"
                    >
                        {{ t("create") }}
                    </button>
                </form>
                <button
                    v-for="k in keys"
                    :key="k.id"
                    :class="
                        selected === k.id
                            ? 'border-teal-500 ring-2 ring-teal-100'
                            : 'border-slate-200'
                    "
                    class="panel w-full p-4 text-start"
                    @click="
                        selected = k.id;
                        refresh();
                    "
                >
                    <div class="flex justify-between">
                        <b>{{ k.name }}</b
                        ><span
                            v-if="k.is_default"
                            class="badge border-teal-200 text-teal-700"
                            >{{ t("general") }}</span
                        >
                    </div>
                    <div
                        class="mt-4 grid grid-cols-2 gap-2 text-xs text-slate-500"
                    >
                        <p>
                            {{ t("expected")
                            }}<b class="block text-slate-900">{{
                                k.expected_total ?? "—"
                            }}</b>
                        </p>
                        <p>
                            {{ t("assigned")
                            }}<b class="block text-slate-900">{{
                                k.assigned_total ?? 0
                            }}</b>
                        </p>
                    </div>
                </button>
            </aside>
            <section class="panel overflow-hidden">
                <div class="panel-head">
                    <div>
                        <h2 class="font-bold">{{ key?.name }}</h2>
                        <p class="text-xs text-slate-500">
                            {{ t("difference") }}:
                            {{ key?.difference ?? "—" }}
                        </p>
                    </div>
                    <button
                        class="btn-primary"
                        @click="form.put(route('allocations.values', selected))"
                    >
                        {{ t("save") }}
                    </button>
                </div>
                <div class="divide-y divide-slate-100">
                    <label
                        v-for="(lot, i) in displayedLots"
                        :key="lot.id"
                        class="flex min-h-16 items-center gap-3 px-5"
                        ><span class="min-w-0 flex-1 truncate font-semibold">{{
                            lot.reference
                        }}</span
                        ><input
                            v-model="form.values[i].value"
                            class="w-36 text-end"
                            type="number"
                            min="0"
                            step=".0001"
                            :aria-label="t('value')"
                    /></label>
                </div>
                <form
                    class="border-t p-5"
                    @submit.prevent="
                        bulk.post(route('allocations.bulk', selected), {
                            onSuccess: () => bulk.reset(),
                        })
                    "
                >
                    <label class="field"
                        ><span class="field-label">{{ t("bulkPaste") }}</span
                        ><textarea
                            v-model="bulk.paste"
                            rows="5"
                            placeholder="A-01&#9;12.5000"
                            required
                        />
                    </label>
                    <p
                        v-if="bulk.errors.paste"
                        class="mt-2 text-sm text-red-600"
                    >
                        {{ bulk.errors.paste }}
                    </p>
                    <button
                        class="btn-secondary mt-3"
                        :disabled="bulk.processing"
                    >
                        {{ t("save") }}
                    </button>
                </form>
                <div
                    class="sticky bottom-0 flex justify-between border-t bg-slate-50 px-5 py-4 font-bold"
                >
                    <span>{{ t("assigned") }}</span
                    ><span>{{ total.toLocaleString() }}</span>
                </div>
                <Pagination :links="lots.links" />
            </section></div
    ></AuthenticatedLayout>
</template>
