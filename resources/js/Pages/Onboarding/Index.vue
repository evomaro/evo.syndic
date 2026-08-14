<script setup lang="ts">
import { Head, useForm, Link, router } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";
const props = defineProps<{
    organization?: any;
    residence?: any;
    steps: any[];
    can_activate: boolean;
}>();
const { t, dir } = useI18n();
const org = useForm({
    name: "",
    code: "",
    type: "volunteer_syndic",
    experience_mode: "essential",
});
const res = useForm({ name: "", code: "", address_line_1: "", city: "" });
</script>
<template>
    <div :dir="dir" class="min-h-screen bg-slate-950 px-4 py-10">
        <Head :title="t('welcome')" />
        <main class="mx-auto max-w-2xl">
            <div class="mb-8 flex items-center gap-3 text-white">
                <div
                    class="grid size-11 place-items-center rounded-xl bg-teal-400 font-black text-slate-950"
                >
                    ES
                </div>
                <div>
                    <h1 class="text-2xl font-bold">{{ t("welcome") }}</h1>
                    <p class="text-sm text-slate-400">
                        {{ t("onboardingIntro") }}
                    </p>
                </div>
            </div>
            <div class="panel overflow-hidden">
                <div class="flex border-b border-slate-100">
                    <div
                        :class="
                            organization ? 'text-emerald-700' : 'text-teal-700'
                        "
                        class="flex-1 p-4 text-sm font-semibold"
                    >
                        {{ organization ? "✓" : "1" }}. {{ t("orgStep") }}
                    </div>
                    <div
                        :class="
                            organization ? 'text-teal-700' : 'text-slate-400'
                        "
                        class="flex-1 p-4 text-sm font-semibold"
                    >
                        {{ residence ? "✓" : "2" }}. {{ t("residenceStep") }}
                    </div>
                </div>
                <form
                    v-if="!organization"
                    class="grid gap-5 p-6"
                    @submit.prevent="org.post(route('onboarding.organization'))"
                >
                    <label class="field"
                        ><span class="field-label">{{ t("organization") }}</span
                        ><input v-model="org.name" required /></label
                    ><label class="field"
                        ><span class="field-label">{{ t("code") }}</span
                        ><input v-model="org.code" required
                    /></label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="panel cursor-pointer p-4"
                            ><input
                                v-model="org.type"
                                type="radio"
                                value="volunteer_syndic"
                                class="me-2"
                            />{{ t("volunteer") }}</label
                        ><label class="panel cursor-pointer p-4"
                            ><input
                                v-model="org.type"
                                type="radio"
                                value="professional_syndic"
                                class="me-2"
                            />{{ t("professional") }}</label
                        >
                    </div>
                    <fieldset>
                        <legend class="field-label mb-2">Expérience</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="panel cursor-pointer p-4"
                                ><input
                                    v-model="org.experience_mode"
                                    type="radio"
                                    value="essential"
                                    class="me-2"
                                />
                                <strong>Essential</strong
                                ><span class="mt-1 block text-sm text-slate-500"
                                    >Gestion quotidienne simplifiée.</span
                                ></label
                            >
                            <label class="panel cursor-pointer p-4"
                                ><input
                                    v-model="org.experience_mode"
                                    type="radio"
                                    value="pro"
                                    class="me-2"
                                />
                                <strong>Pro</strong
                                ><span class="mt-1 block text-sm text-slate-500"
                                    >Tous les modules professionnels.</span
                                ></label
                            >
                        </div>
                    </fieldset>
                    <button class="btn-primary" :disabled="org.processing">
                        {{ t("next") }}
                    </button>
                </form>
                <form
                    v-else-if="!residence"
                    class="grid gap-5 p-6"
                    @submit.prevent="res.post(route('onboarding.residence'))"
                >
                    <label class="field"
                        ><span class="field-label">{{
                            t("residenceName")
                        }}</span
                        ><input v-model="res.name" required /></label
                    ><label class="field"
                        ><span class="field-label">{{ t("code") }}</span
                        ><input v-model="res.code" required /></label
                    ><label class="field"
                        ><span class="field-label">{{ t("address") }}</span
                        ><input v-model="res.address_line_1" required /></label
                    ><label class="field"
                        ><span class="field-label">{{ t("city") }}</span
                        ><input v-model="res.city" required /></label
                    ><button class="btn-primary">{{ t("create") }}</button>
                </form>
                <div v-else class="p-6">
                    <ol class="grid gap-3">
                        <li
                            v-for="(step, index) in steps"
                            :key="step.key"
                            class="flex items-center gap-3 rounded-xl border p-4"
                        >
                            <span
                                :class="
                                    step.complete
                                        ? 'bg-emerald-100 text-emerald-800'
                                        : 'bg-slate-100 text-slate-600'
                                "
                                class="grid size-9 shrink-0 place-items-center rounded-full font-bold"
                                >{{ step.complete ? "✓" : index + 1 }}</span
                            >
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">
                                    {{
                                        t(
                                            step.key === "allocations"
                                                ? "allocations"
                                                : step.key,
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="step.missing"
                                    class="text-xs text-amber-700"
                                >
                                    {{ step.missing }} · {{ t("noResults") }}
                                </p>
                            </div>
                            <span
                                v-if="!step.required"
                                class="badge border-slate-200"
                                >{{ t("optional") }}</span
                            >
                        </li>
                    </ol>
                    <div class="mt-5 flex flex-wrap gap-3">
                        <Link
                            v-if="!steps[2].complete"
                            :href="route('structure.index')"
                            class="btn-secondary"
                            >{{ t("structure") }}</Link
                        >
                        <button
                            v-if="steps[4].missing"
                            class="btn-secondary"
                            @click="
                                router.post(
                                    route('onboarding.ownership.acknowledge'),
                                )
                            "
                        >
                            {{ t("incompleteAcknowledgement") }}
                        </button>
                        <button
                            v-if="steps[5].missing"
                            class="btn-secondary"
                            @click="
                                router.post(
                                    route('onboarding.allocations.defer'),
                                )
                            "
                        >
                            {{ t("deferAllocations") }}
                        </button>
                        <button
                            v-if="!steps[6].complete"
                            class="btn-secondary"
                            @click="
                                router.post(route('onboarding.skip', 'team'))
                            "
                        >
                            {{ t("skipOptional") }}
                        </button>
                        <button
                            class="btn-primary"
                            :disabled="!can_activate"
                            @click="router.post(route('onboarding.activate'))"
                        >
                            {{ t("activateResidence") }}
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
