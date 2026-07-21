<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import { useI18n } from "@/i18n";
const p = defineProps<{
    call?: any;
    exercises: any[];
    categories: any[];
    allocationKeys: any[];
    buildings: any[];
    lots: any[];
}>();
const { t } = useI18n();
const empty = () => ({
    charge_category_id: p.categories[0]?.id ?? "",
    label: "",
    distribution_method: "allocation_key",
    allocation_key_id: p.allocationKeys[0]?.id ?? null,
    target_type: "all",
    target_ids: [] as any[],
    amount: "",
    fixed_amount: "",
    manual_allocations: [],
});
const form = useForm({
    financial_exercise_id:
        p.call?.financial_exercise_id ??
        p.exercises.find((e) => e.status === "open")?.id ??
        "",
    title: p.call?.title ?? "",
    description: p.call?.description ?? "",
    issue_date:
        p.call?.issue_date?.slice(0, 10) ??
        new Date().toISOString().slice(0, 10),
    due_date: p.call?.due_date?.slice(0, 10) ?? "",
    lines: p.call?.lines?.map((l: any) => ({
        ...l,
        amount: (l.amount_cents / 100).toFixed(2),
        fixed_amount: l.fixed_amount_cents
            ? (l.fixed_amount_cents / 100).toFixed(2)
            : "",
    })) ?? [empty()],
});
const submit = () =>
    p.call
        ? form.put(route("fund-calls.update", p.call.id))
        : form.post(route("fund-calls.store"));
const syncManualLot = (line: any, lotId: number) => {
    const selected = line.target_ids.includes(lotId);
    const existing = line.manual_allocations.find(
        (row: any) => row.lot_id === lotId,
    );
    if (selected && !existing) {
        line.manual_allocations.push({ lot_id: lotId, amount: "" });
    }
    if (!selected && existing) {
        line.manual_allocations = line.manual_allocations.filter(
            (row: any) => row.lot_id !== lotId,
        );
    }
};
</script>
<template>
    <AuthenticatedLayout :title="p.call ? t('edit') : t('fundCalls')"
        ><FinanceNav />
        <form class="grid gap-5" @submit.prevent="submit">
            <section class="panel grid gap-4 p-5 md:grid-cols-2">
                <label class="field"
                    ><span class="field-label">{{ t("exercises") }}</span
                    ><select v-model="form.financial_exercise_id" required>
                        <option v-for="e in exercises" :value="e.id">
                            {{ e.name }} · {{ t(e.status) }}
                        </option>
                    </select></label
                ><label class="field"
                    ><span class="field-label">{{ t("title") }}</span
                    ><input v-model="form.title" required /></label
                ><label class="field"
                    ><span class="field-label">{{ t("issueDate") }}</span
                    ><input
                        v-model="form.issue_date"
                        type="date"
                        required /></label
                ><label class="field"
                    ><span class="field-label">{{ t("dueDate") }}</span
                    ><input v-model="form.due_date" type="date" required
                /></label>
            </section>
            <section class="grid gap-4">
                <article
                    v-for="(line, i) in form.lines"
                    :key="i"
                    class="panel grid gap-3 p-5 md:grid-cols-3"
                >
                    <select v-model="line.charge_category_id" required>
                        <option v-for="c in categories" :value="c.id">
                            {{ c.name }}
                        </option></select
                    ><input
                        v-model="line.label"
                        :placeholder="t('title')"
                        required
                    /><input
                        v-model="line.amount"
                        inputmode="decimal"
                        :placeholder="t('amount')"
                        required
                    /><select
                        v-model="line.distribution_method"
                        @change="
                            line.distribution_method === 'manual' &&
                            (line.target_type = 'lots')
                        "
                    >
                        <option
                            v-for="m in [
                                'allocation_key',
                                'equal',
                                'fixed',
                                'manual',
                            ]"
                            :value="m"
                        >
                            {{ t(m) }}
                        </option></select
                    ><select
                        v-if="line.distribution_method === 'allocation_key'"
                        v-model="line.allocation_key_id"
                    >
                        <option v-for="k in allocationKeys" :value="k.id">
                            {{ k.name }}
                        </option></select
                    ><input
                        v-if="line.distribution_method === 'fixed'"
                        v-model="line.fixed_amount"
                        inputmode="decimal"
                        :placeholder="t('fixed')"
                    /><select v-model="line.target_type">
                        <option value="all">{{ t("allLots") }}</option>
                        <option value="buildings">{{ t("buildings") }}</option>
                        <option value="lots">{{ t("selectedLots") }}</option>
                        <option value="lot_types">
                            {{ t("type") }}
                        </option>
                    </select>
                    <div
                        v-if="line.target_type === 'buildings'"
                        class="max-h-40 overflow-auto rounded-xl border p-2 md:col-span-2"
                    >
                        <label
                            v-for="building in buildings"
                            class="flex min-h-11 items-center gap-2"
                            ><input
                                v-model="line.target_ids"
                                type="checkbox"
                                :value="building.id"
                            />{{ building.name }}</label
                        >
                    </div>
                    <div
                        v-if="line.target_type === 'lots'"
                        class="max-h-52 overflow-auto rounded-xl border p-2 md:col-span-2"
                    >
                        <label
                            v-for="lot in lots"
                            class="flex min-h-11 items-center gap-2"
                            ><input
                                v-model="line.target_ids"
                                type="checkbox"
                                :value="lot.id"
                                @change="syncManualLot(line, lot.id)"
                            />{{ lot.reference }}</label
                        >
                        <div
                            v-if="line.distribution_method === 'manual'"
                            class="mt-2 grid gap-2 border-t pt-2"
                        >
                            <label
                                v-for="row in line.manual_allocations"
                                class="flex min-h-11 items-center justify-between gap-2 text-sm"
                            >
                                <span>{{
                                    lots.find((lot) => lot.id === row.lot_id)
                                        ?.reference
                                }}</span>
                                <input
                                    v-model="row.amount"
                                    class="w-32"
                                    inputmode="decimal"
                                    :placeholder="t('amount')"
                                    required
                                />
                            </label>
                        </div>
                    </div>
                    <div
                        v-if="line.target_type === 'lot_types'"
                        class="grid grid-cols-2 rounded-xl border p-2 md:col-span-2"
                    >
                        <label
                            v-for="lotType in [
                                'apartment',
                                'villa',
                                'shop',
                                'office',
                                'garage',
                                'parking',
                                'storage',
                                'other',
                            ]"
                            class="flex min-h-11 items-center gap-2"
                            ><input
                                v-model="line.target_ids"
                                type="checkbox"
                                :value="lotType"
                            />{{ t(lotType) }}</label
                        >
                    </div>
                    <button
                        v-if="form.lines.length > 1"
                        type="button"
                        class="btn-secondary"
                        @click="form.lines.splice(i, 1)"
                    >
                        {{ t("cancel") }}
                    </button>
                </article>
                <button
                    type="button"
                    class="btn-secondary justify-self-start"
                    @click="form.lines.push(empty())"
                >
                    {{ t("addLine") }}
                </button>
            </section>
            <div
                class="sticky bottom-20 z-10 flex items-center justify-between rounded-2xl border bg-white p-4 shadow-lg lg:bottom-4"
            >
                <p class="text-sm text-slate-500">{{ t("previewConfirm") }}</p>
                <button class="btn-primary" :disabled="form.processing">
                    {{ t("save") }}
                </button>
            </div>
            <p v-for="e in form.errors" class="text-sm text-red-600">{{ e }}</p>
        </form></AuthenticatedLayout
    >
</template>
