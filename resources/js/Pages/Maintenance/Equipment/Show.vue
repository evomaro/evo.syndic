<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import MaintenanceNav from "@/Components/Maintenance/MaintenanceNav.vue";
import InputError from "@/Components/InputError.vue";
import { Link, useForm, usePage } from "@inertiajs/vue3";

const props = defineProps<{ equipment: any; options: any }>();
const ar = usePage<any>().props.locale === "ar";
const form = useForm({
    building_id: props.equipment.building_id ?? "",
    maintenance_category_id: props.equipment.maintenance_category_id,
    supplier_id: props.equipment.supplier_id ?? "",
    supplier_contract_id: props.equipment.supplier_contract_id ?? "",
    location: props.equipment.location ?? "",
    name: props.equipment.name,
    manufacturer: props.equipment.manufacturer ?? "",
    model: props.equipment.model ?? "",
    serial_number: props.equipment.serial_number ?? "",
    installed_on: props.equipment.installed_on?.slice(0, 10) ?? "",
    warranty_expires_on:
        props.equipment.warranty_expires_on?.slice(0, 10) ?? "",
    condition: props.equipment.condition,
    public_description: props.equipment.public_description ?? "",
    internal_notes: props.equipment.internal_notes ?? "",
});
const documentForm = useForm<{
    file: File | null;
    kind: string;
    visibility: string;
}>({ file: null, kind: "equipment", visibility: "internal" });
const chooseDocument = (event: Event) =>
    (documentForm.file = (event.target as HTMLInputElement).files?.[0] ?? null);
</script>

<template>
    <AuthenticatedLayout
        :title="equipment.name"
        :subtitle="ar ? 'تفاصيل المعدات' : 'Fiche équipement'"
    >
        <MaintenanceNav />
        <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_25rem]">
            <main class="min-w-0 space-y-5">
                <section class="rounded-2xl border bg-white p-5">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div>
                            <b>{{
                                ar
                                    ? equipment.category.name_ar
                                    : equipment.category.name_fr
                            }}</b>
                            <p class="text-sm text-slate-500">
                                {{ equipment.location || "—" }} ·
                                {{ equipment.manufacturer }}
                                {{ equipment.model }}
                            </p>
                        </div>
                        <span
                            class="h-fit rounded-full bg-slate-100 px-3 py-1 text-sm font-bold"
                            >{{ equipment.status }} ·
                            {{ equipment.condition }}</span
                        >
                    </div>
                    <p class="mt-4 whitespace-pre-wrap break-words">
                        {{ equipment.public_description || "—" }}
                    </p>
                    <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm">
                        {{ equipment.internal_notes || "—" }}
                    </p>
                </section>
                <section class="rounded-2xl border bg-white p-5">
                    <h2 class="mb-3 font-bold">
                        {{ ar ? "السجل" : "Historique opérationnel" }}
                    </h2>
                    <Link
                        v-for="request in equipment.requests"
                        :key="'r' + request.id"
                        :href="route('maintenance.requests.show', request.id)"
                        class="mb-2 block rounded-xl border p-3"
                        >{{ request.reference }} · {{ request.title }} ·
                        {{ request.status }}</Link
                    >
                    <div
                        v-for="order in equipment.work_orders"
                        :key="'w' + order.id"
                        class="mb-2 rounded-xl border p-3"
                    >
                        {{ order.reference }} · {{ order.status }}
                    </div>
                    <div
                        v-for="plan in equipment.preventive_plans"
                        :key="'p' + plan.id"
                        class="mb-2 rounded-xl border p-3"
                    >
                        {{ plan.name }} · {{ plan.next_intervention_on }}
                    </div>
                </section>
                <section class="rounded-2xl border bg-white p-5">
                    <h2 class="mb-3 font-bold">
                        {{ ar ? "الوثائق" : "Documents privés" }}
                    </h2>
                    <a
                        v-for="file in equipment.attachments"
                        :key="file.id"
                        :href="
                            route('maintenance.attachments.download', file.id)
                        "
                        class="me-2 inline-block max-w-full break-all rounded-lg border px-3 py-2 text-sm"
                        >{{ file.name }}</a
                    >
                    <form
                        class="mt-4 flex min-w-0 flex-wrap gap-2"
                        @submit.prevent="
                            documentForm.post(
                                route('maintenance.attachments.store', {
                                    type: 'equipment',
                                    id: equipment.id,
                                }),
                                { forceFormData: true, preserveScroll: true },
                            )
                        "
                    >
                        <input
                            type="file"
                            required
                            class="max-w-full min-w-0"
                            @change="chooseDocument"
                        /><InputError
                            :message="documentForm.errors.file"
                        /><button
                            class="rounded-lg bg-slate-900 px-4 py-2 text-white"
                        >
                            {{ ar ? "رفع" : "Téléverser" }}
                        </button>
                    </form>
                </section>
            </main>
            <form
                class="space-y-3 rounded-2xl border bg-white p-5"
                @submit.prevent="
                    form.put(
                        route('maintenance.equipment.update', equipment.id),
                        { preserveScroll: true },
                    )
                "
            >
                <h2 class="font-bold">{{ ar ? "تعديل" : "Modifier" }}</h2>
                <input
                    v-model="form.name"
                    required
                    class="w-full rounded-xl border-slate-300"
                /><select
                    v-model="form.maintenance_category_id"
                    required
                    class="w-full rounded-xl border-slate-300"
                >
                    <option
                        v-for="category in options.categories"
                        :key="category.id"
                        :value="category.id"
                    >
                        {{ ar ? category.name_ar : category.name_fr }}
                    </option>
                </select>
                <input
                    v-model="form.location"
                    placeholder="Emplacement"
                    class="w-full rounded-xl border-slate-300"
                />
                <div class="grid grid-cols-2 gap-2">
                    <input
                        v-model="form.manufacturer"
                        placeholder="Fabricant"
                        class="min-w-0 rounded-xl border-slate-300"
                    /><input
                        v-model="form.model"
                        placeholder="Modèle"
                        class="min-w-0 rounded-xl border-slate-300"
                    />
                </div>
                <input
                    v-model="form.serial_number"
                    placeholder="N° série"
                    class="w-full rounded-xl border-slate-300"
                /><select
                    v-model="form.condition"
                    class="w-full rounded-xl border-slate-300"
                >
                    <option
                        v-for="condition in [
                            'good',
                            'fair',
                            'poor',
                            'critical',
                        ]"
                        :key="condition"
                    >
                        {{ condition }}
                    </option>
                </select>
                <textarea
                    v-model="form.public_description"
                    class="w-full rounded-xl border-slate-300"
                    placeholder="Description publique"
                ></textarea
                ><textarea
                    v-model="form.internal_notes"
                    class="w-full rounded-xl border-slate-300"
                    placeholder="Notes internes"
                ></textarea
                ><button
                    class="w-full rounded-xl bg-teal-700 py-3 font-bold text-white"
                >
                    {{ ar ? "حفظ" : "Enregistrer" }}
                </button>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
