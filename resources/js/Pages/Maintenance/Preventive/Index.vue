<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import MaintenanceNav from "@/Components/Maintenance/MaintenanceNav.vue";
import Pagination from "@/Components/Pagination.vue";
import { useForm, usePage } from "@inertiajs/vue3";
defineProps<{ plans: any; interventions: any; equipment: any }>();
const ar = usePage<any>().props.locale === "ar";
const form = useForm({
    equipment_id: "",
    supplier_id: null,
    supplier_contract_id: null,
    responsible_user_id: null,
    name: "",
    description: "",
    location: "",
    frequency_type: "monthly",
    frequency_interval: 1,
    starts_on: new Date().toISOString().slice(0, 10),
    next_intervention_on: new Date().toISOString().slice(0, 10),
    reminder_days: 7,
    checklist: ["Contrôle visuel"],
    active: true,
});
const submit = () =>
    form.post(route("maintenance.preventive.store"), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
const checklist = (event: Event) =>
    (form.checklist = (event.target as HTMLTextAreaElement).value
        .split("\n")
        .filter(Boolean));
</script>
<template>
    <AuthenticatedLayout
        :title="ar ? 'الصيانة الوقائية' : 'Maintenance préventive'"
        ><MaintenanceNav />
        <div class="grid gap-6 xl:grid-cols-[1fr_26rem]">
            <main class="space-y-6">
                <section>
                    <h2 class="mb-3 text-lg font-bold">
                        {{ ar ? "الخطط" : "Plans" }}
                    </h2>
                    <article
                        v-for="p in plans.data"
                        :key="p.id"
                        class="mb-3 rounded-2xl border bg-white p-4"
                    >
                        <div class="flex justify-between">
                            <div>
                                <b>{{ p.name }}</b>
                                <p class="text-sm text-slate-500">
                                    {{ p.equipment?.name || p.location }} ·
                                    {{ p.frequency_type }} ×
                                    {{ p.frequency_interval }}
                                </p>
                            </div>
                            <span class="text-sm font-semibold">{{
                                p.next_intervention_on
                            }}</span>
                        </div>
                    </article>
                    <Pagination :links="plans.links" />
                </section>
                <section>
                    <h2 class="mb-3 text-lg font-bold">
                        {{ ar ? "التدخلات" : "Interventions" }}
                    </h2>
                    <div class="overflow-hidden rounded-2xl border bg-white">
                        <div
                            v-for="i in interventions.data"
                            :key="i.id"
                            class="grid grid-cols-[1fr_8rem_6rem] border-b p-3 text-sm"
                        >
                            <b>{{ i.plan.name }}</b
                            ><span>{{ i.due_on }}</span
                            ><span>{{ i.status }}</span>
                        </div>
                    </div>
                    <Pagination class="mt-3" :links="interventions.links" />
                </section>
            </main>
            <form
                class="space-y-3 rounded-2xl border bg-white p-5"
                @submit.prevent="submit"
            >
                <h2 class="font-bold">
                    {{ ar ? "خطة جديدة" : "Nouveau plan" }}
                </h2>
                <input
                    v-model="form.name"
                    required
                    placeholder="Nom"
                    class="w-full rounded-xl border-slate-300"
                /><select
                    v-model="form.equipment_id"
                    class="w-full rounded-xl border-slate-300"
                >
                    <option value="">Sans équipement</option>
                    <option v-for="e in equipment" :key="e.id" :value="e.id">
                        {{ e.name }}
                    </option></select
                ><input
                    v-model="form.location"
                    placeholder="Emplacement"
                    class="w-full rounded-xl border-slate-300"
                />
                <div class="grid grid-cols-2 gap-2">
                    <select
                        v-model="form.frequency_type"
                        class="rounded-xl border-slate-300"
                    >
                        <option
                            v-for="f in [
                                'daily',
                                'weekly',
                                'monthly',
                                'quarterly',
                                'semiannual',
                                'annual',
                                'custom',
                            ]"
                            :key="f"
                        >
                            {{ f }}
                        </option></select
                    ><input
                        v-model.number="form.frequency_interval"
                        type="number"
                        min="1"
                        class="rounded-xl border-slate-300"
                    />
                </div>
                <label class="block text-sm"
                    >Prochaine intervention<input
                        v-model="form.next_intervention_on"
                        type="date"
                        class="mt-1 w-full rounded-xl border-slate-300" /></label
                ><label class="block text-sm"
                    >Checklist<textarea
                        :value="form.checklist.join('\n')"
                        @input="checklist"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    ></textarea></label
                ><button
                    class="w-full rounded-xl bg-teal-700 py-3 font-bold text-white"
                >
                    {{ ar ? "حفظ" : "Enregistrer" }}
                </button>
            </form>
        </div></AuthenticatedLayout
    >
</template>
