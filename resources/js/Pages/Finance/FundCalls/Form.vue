<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import { useI18n } from "@/i18n";
import { computed, ref } from "vue";
import InfoTooltip from "@/Components/InfoTooltip.vue";
import { formatMAD } from "@/Support/money";
const p = defineProps<{
    call?: any;
    exercises: any[];
    categories: any[];
    allocationKeys: any[];
    buildings: any[];
    lots: any[];
}>();
const { t } = useI18n();
const step = ref(1);
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
const parseAmount = (value: unknown) =>
    Number(
        String(value ?? "0")
            .replace(/\s/g, "")
            .replace(",", "."),
    ) || 0;
const totalAmount = computed(() =>
    form.lines.reduce(
        (sum: number, line: any) => sum + parseAmount(line.amount),
        0,
    ),
);
const targetedLotIds = computed(() => {
    const ids = new Set<number>();
    form.lines.forEach((line: any) => {
        if (line.target_type === "all") {
            p.lots.forEach((lot) => ids.add(lot.id));
        } else if (line.target_type === "lots") {
            line.target_ids.forEach((id: number) => ids.add(Number(id)));
        } else if (line.target_type === "buildings") {
            const buildingIds = line.target_ids.map(Number);
            p.lots
                .filter((lot) => buildingIds.includes(Number(lot.building_id)))
                .forEach((lot) => ids.add(lot.id));
        } else if (line.target_type === "lot_types") {
            p.lots
                .filter((lot) => line.target_ids.includes(lot.type))
                .forEach((lot) => ids.add(lot.id));
        }
    });
    return ids;
});
const stepOneReady = computed(
    () =>
        Boolean(form.financial_exercise_id) &&
        Boolean(form.title.trim()) &&
        Boolean(form.issue_date) &&
        Boolean(form.due_date),
);
const stepTwoReady = computed(
    () =>
        form.lines.length > 0 &&
        form.lines.every(
            (line: any) =>
                line.charge_category_id &&
                line.label?.trim() &&
                parseAmount(line.amount) > 0 &&
                (line.target_type === "all" || line.target_ids.length > 0),
        ),
);
const goNext = () => {
    if (step.value === 1 && stepOneReady.value) step.value = 2;
    else if (step.value === 2 && stepTwoReady.value) step.value = 3;
    window.scrollTo({ top: 0, behavior: "smooth" });
};
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
        <div
            class="mb-6 grid grid-cols-3 overflow-hidden rounded-2xl border bg-white"
            aria-label="Progression"
        >
            <div
                v-for="(label, index) in [
                    'Contexte',
                    'Montants et répartition',
                    'Confirmation',
                ]"
                :key="label"
                class="border-e px-3 py-3 text-center text-xs font-semibold last:border-e-0 sm:text-sm"
                :class="
                    step === index + 1
                        ? 'bg-teal-700 text-white'
                        : step > index + 1
                          ? 'bg-teal-50 text-teal-800'
                          : 'text-slate-400'
                "
            >
                <span class="block sm:inline">{{ index + 1 }}.</span>
                {{ label }}
            </div>
        </div>
        <form class="grid gap-5" @submit.prevent="submit">
            <section
                v-show="step === 1"
                class="panel grid gap-4 p-5 md:grid-cols-2"
            >
                <div class="md:col-span-2">
                    <h2 class="text-lg font-bold">
                        Appel de fonds
                        <InfoTooltip term="fund_call" />
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Commencez par choisir la période et les dates
                        communiquées aux copropriétaires.
                    </p>
                </div>
                <label class="field"
                    ><span class="field-label"
                        >{{ t("exercises") }}
                        <InfoTooltip term="accounting_period" /></span
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
            <section v-show="step === 2" class="grid gap-4">
                <div class="panel p-5">
                    <h2 class="text-lg font-bold">Montants et répartition</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Ajoutez les charges, puis indiquez comment elles seront
                        réparties entre les lots.
                        <InfoTooltip term="distribution_key" />
                    </p>
                </div>
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
            <section v-show="step === 3" class="panel p-6 sm:p-8">
                <p
                    class="text-xs font-bold uppercase tracking-wider text-teal-700"
                >
                    Vérification finale
                </p>
                <h2 class="mt-2 text-2xl font-bold">
                    Voici ce qui va être enregistré
                </h2>
                <p class="mt-4 text-lg leading-8 text-slate-700">
                    <strong>{{ targetedLotIds.size }} lots</strong> vont
                    recevoir un appel de fonds totalisant
                    <strong>{{ formatMAD(totalAmount) }}</strong
                    >, à régler avant le <strong>{{ form.due_date }}</strong
                    >.
                </p>
                <dl
                    class="mt-6 grid gap-3 rounded-2xl bg-slate-50 p-5 text-sm sm:grid-cols-2"
                >
                    <div>
                        <dt class="text-slate-500">Titre</dt>
                        <dd class="font-bold">{{ form.title }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Date d’émission</dt>
                        <dd class="font-bold">{{ form.issue_date }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Nombre de lignes</dt>
                        <dd class="font-bold">{{ form.lines.length }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Total</dt>
                        <dd class="font-bold">{{ formatMAD(totalAmount) }}</dd>
                    </div>
                </dl>
                <p
                    class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900"
                >
                    Vérifiez les lots, les montants et l’échéance. Après
                    validation ultérieure, les créances seront visibles dans les
                    comptes des copropriétaires.
                </p>
            </section>
            <div
                class="sticky bottom-20 z-10 flex items-center justify-between rounded-2xl border bg-white p-4 shadow-lg lg:bottom-4"
            >
                <button
                    v-if="step > 1"
                    type="button"
                    class="btn-secondary"
                    @click="step -= 1"
                >
                    Précédent
                </button>
                <p v-else class="text-sm text-slate-500">
                    Étape {{ step }} sur 3
                </p>
                <button
                    v-if="step < 3"
                    type="button"
                    class="btn-primary"
                    :disabled="
                        (step === 1 && !stepOneReady) ||
                        (step === 2 && !stepTwoReady)
                    "
                    @click="goNext"
                >
                    Continuer
                </button>
                <button v-else class="btn-primary" :disabled="form.processing">
                    {{
                        form.processing
                            ? "Enregistrement…"
                            : p.call
                              ? "Confirmer la modification"
                              : "Confirmer et créer le brouillon"
                    }}
                </button>
            </div>
            <p v-for="e in form.errors" class="text-sm text-red-600">{{ e }}</p>
        </form></AuthenticatedLayout
    >
</template>
