<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import BudgetLineEditor from "@/Components/Expenses/BudgetLineEditor.vue";
import InfoTooltip from "@/Components/InfoTooltip.vue";
import { computed, ref } from "vue";
import { formatMADCents } from "@/Support/money";
defineProps<{ exercises: any[]; categories: any[] }>();
const step = ref(1);
const form = useForm<any>({
    financial_exercise_id: "",
    title: "",
    notes: "",
    lines: [{ expense_category_id: "", planned_cents: 0, description: "" }],
});
const total = computed(() =>
    form.lines.reduce(
        (sum: number, line: any) => sum + Number(line.planned_cents || 0),
        0,
    ),
);
const save = () => form.post(route("budgets.store"));
</script>
<template>
    <AuthenticatedLayout title="Nouveau budget" subtitle="Version de travail"
        ><ExpenseNavigation />
        <div
            class="mb-5 grid max-w-4xl grid-cols-3 overflow-hidden rounded-2xl border bg-white text-center text-sm font-semibold"
        >
            <div
                v-for="(label, index) in [
                    'Contexte',
                    'Lignes budgétaires',
                    'Confirmation',
                ]"
                :key="label"
                class="border-e px-3 py-3 last:border-e-0"
                :class="
                    step === index + 1
                        ? 'bg-teal-700 text-white'
                        : step > index + 1
                          ? 'bg-teal-50 text-teal-800'
                          : 'text-slate-400'
                "
            >
                {{ index + 1 }}. {{ label }}
            </div>
        </div>
        <form class="grid max-w-4xl gap-5" @submit.prevent="save">
            <section
                v-show="step === 1"
                class="panel grid gap-4 p-5 md:grid-cols-2"
            >
                <label class="field">
                    <span class="field-label"
                        >Exercice
                        <InfoTooltip term="accounting_period" />
                    </span>
                    <select
                        v-model="form.financial_exercise_id"
                        class="rounded-lg border-slate-300"
                    >
                        <option value="">Sélectionner un exercice</option>
                        <option
                            v-for="e in exercises"
                            :key="e.id"
                            :value="e.id"
                        >
                            {{ e.name }}
                        </option>
                    </select>
                </label>
                <input
                    v-model="form.title"
                    class="rounded-lg border-slate-300"
                    placeholder="Titre"
                />
            </section>
            <BudgetLineEditor
                v-show="step === 2"
                v-model="form.lines"
                :categories="categories"
            />
            <section v-show="step === 3" class="panel p-6">
                <h2 class="text-xl font-bold">Vérifiez le budget</h2>
                <p class="mt-3 text-slate-600">
                    Le brouillon <strong>{{ form.title }}</strong> contient
                    <strong>{{ form.lines.length }} lignes</strong> pour un
                    total planifié de
                    <strong>{{ formatMADCents(total) }}</strong
                    >.
                </p>
                <p class="mt-4 rounded-xl bg-teal-50 p-4 text-sm text-teal-900">
                    La création du brouillon ne génère aucune dette. Le budget
                    devra être contrôlé puis approuvé séparément.
                </p>
            </section>
            <div class="flex items-center justify-between gap-3">
                <button
                    v-if="step > 1"
                    type="button"
                    class="btn-secondary"
                    @click="step -= 1"
                >
                    Précédent
                </button>
                <span v-else></span>
                <button
                    v-if="step < 3"
                    type="button"
                    class="btn-primary"
                    :disabled="
                        (step === 1 &&
                            (!form.financial_exercise_id ||
                                !form.title.trim())) ||
                        (step === 2 && !form.lines.length)
                    "
                    @click="step += 1"
                >
                    Continuer
                </button>
                <button v-else class="btn-primary" :disabled="form.processing">
                    Confirmer et créer le brouillon
                </button>
            </div>
        </form></AuthenticatedLayout
    >
</template>
