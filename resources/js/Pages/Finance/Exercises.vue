<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import { useI18n } from "@/i18n";
defineProps<{ exercises: any[] }>();
const { t } = useI18n();
const form = useForm({
    name: "Exercice 2026",
    starts_on: "2026-01-01",
    ends_on: "2026-12-31",
    notes: "",
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
        <div class="grid gap-5 xl:grid-cols-[360px_1fr]">
            <form
                class="panel grid h-fit gap-3 p-5"
                @submit.prevent="
                    form.post(route('financial-exercises.store'), {
                        onSuccess: () => form.reset(),
                    })
                "
            >
                <h2 class="font-bold">{{ t("createExercise") }}</h2>
                <label class="field"
                    ><span class="field-label">{{ t("name") }}</span
                    ><input v-model="form.name" required /></label
                ><label class="field"
                    ><span class="field-label">{{ t("startDate") }}</span
                    ><input
                        v-model="form.starts_on"
                        type="date"
                        required /></label
                ><label class="field"
                    ><span class="field-label">{{ t("endDate") }}</span
                    ><input
                        v-model="form.ends_on"
                        type="date"
                        required /></label
                ><button class="btn-primary">{{ t("create") }}</button>
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
