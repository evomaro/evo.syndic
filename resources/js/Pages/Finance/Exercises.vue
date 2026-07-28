<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import { useI18n } from "@/i18n";
import InfoTooltip from "@/Components/InfoTooltip.vue";
import { ref } from "vue";
defineProps<{ exercises: any[] }>();
const { t } = useI18n();
const form = useForm({
    name: "Exercice 2026",
    starts_on: "2026-01-01",
    ends_on: "2026-12-31",
    notes: "",
});
const createStep = ref(1);
const createExercise = () =>
    form.post(route("financial-exercises.store"), {
        onSuccess: () => {
            form.reset();
            createStep.value = 1;
        },
    });
const transition = (id: number, action: string) => {
    const reason = action === "reopen" ? window.prompt(t("reason")) : "";
    useForm({ action, reason }).post(
        route("financial-exercises.transition", id),
    );
};
</script>
<template>
    <AuthenticatedLayout :title="t('exercises')"
        ><FinanceNav />
        <p class="mb-5 text-sm text-slate-600">
            {{ t("exercises") }}
            <InfoTooltip term="accounting_period" />
        </p>
        <div class="grid gap-5 xl:grid-cols-[360px_1fr]">
            <form
                class="panel grid h-fit gap-3 p-5"
                @submit.prevent="createExercise"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-bold">{{ t("createExercise") }}</h2>
                    <span class="badge">{{ createStep }}/2</span>
                </div>
                <label v-if="createStep === 1" class="field"
                    ><span class="field-label">{{ t("name") }}</span
                    ><input v-model="form.name" required /></label
                ><label v-if="createStep === 1" class="field"
                    ><span class="field-label">{{ t("startDate") }}</span
                    ><input
                        v-model="form.starts_on"
                        type="date"
                        required /></label
                ><label v-if="createStep === 1" class="field"
                    ><span class="field-label">{{ t("endDate") }}</span
                    ><input v-model="form.ends_on" type="date" required
                /></label>
                <div
                    v-if="createStep === 2"
                    class="rounded-xl bg-slate-50 p-4 text-sm"
                >
                    <p class="font-bold">{{ form.name }}</p>
                    <p class="mt-1 text-slate-600">
                        Du {{ form.starts_on }} au {{ form.ends_on }}.
                    </p>
                    <p class="mt-3 text-amber-800">
                        L’exercice sera créé en brouillon. Son ouverture restera
                        une action séparée.
                    </p>
                </div>
                <div class="flex justify-between gap-2">
                    <button
                        v-if="createStep === 2"
                        type="button"
                        class="btn-secondary"
                        @click="createStep = 1"
                    >
                        Modifier
                    </button>
                    <span v-else></span>
                    <button
                        v-if="createStep === 1"
                        type="button"
                        class="btn-primary"
                        :disabled="
                            !form.name || !form.starts_on || !form.ends_on
                        "
                        @click="createStep = 2"
                    >
                        Continuer
                    </button>
                    <button v-else class="btn-primary">
                        Confirmer la création
                    </button>
                </div>
                <p v-for="error in form.errors" class="text-sm text-red-600">
                    {{ error }}
                </p>
            </form>
            <div class="grid gap-3">
                <article
                    v-for="exercise in exercises"
                    :key="exercise.id"
                    class="panel p-5"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h2 class="font-bold">{{ exercise.name }}</h2>
                            <p class="text-sm text-slate-500">
                                {{ exercise.starts_on }} -
                                {{ exercise.ends_on }}
                            </p>
                        </div>
                        <span class="badge">{{ t(exercise.status) }}</span>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            v-if="exercise.status === 'draft'"
                            class="btn-primary"
                            @click="transition(exercise.id, 'open')"
                        >
                            {{ t("openExercise") }}</button
                        ><button
                            v-if="exercise.status === 'open'"
                            class="btn-secondary"
                            @click="transition(exercise.id, 'close')"
                        >
                            {{ t("closeExercise") }}</button
                        ><button
                            v-if="exercise.status === 'closed'"
                            class="btn-secondary"
                            @click="transition(exercise.id, 'reopen')"
                        >
                            {{ t("reopenExercise") }}
                        </button>
                    </div>
                </article>
            </div>
        </div></AuthenticatedLayout
    >
</template>
