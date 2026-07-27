<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import MaintenanceNav from "@/Components/Maintenance/MaintenanceNav.vue";
import Pagination from "@/Components/Pagination.vue";
import { Link, useForm, usePage } from "@inertiajs/vue3";
const props = defineProps<{ equipment: any; filters: any; options: any }>();
const ar = usePage<any>().props.locale === "ar";
const form = useForm({
    maintenance_category_id: "",
    building_id: "",
    supplier_id: "",
    supplier_contract_id: "",
    location: "",
    name: "",
    manufacturer: "",
    model: "",
    serial_number: "",
    installed_on: "",
    warranty_expires_on: "",
    condition: "good",
    public_description: "",
    internal_notes: "",
});
const submit = () =>
    form.post(route("maintenance.equipment.store"), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
</script>
<template>
    <AuthenticatedLayout
        :title="ar ? 'سجل المعدات' : 'Registre des équipements'"
        ><MaintenanceNav />
        <div class="grid gap-5 xl:grid-cols-[1fr_26rem]">
            <section>
                <div
                    v-if="!equipment.data.length"
                    class="rounded-2xl border bg-white p-10 text-center text-slate-500"
                >
                    {{ ar ? "لا توجد معدات" : "Aucun équipement" }}
                </div>
                <article
                    v-for="e in equipment.data"
                    :key="e.id"
                    class="mb-3 rounded-2xl border bg-white p-4"
                >
                    <div class="flex justify-between gap-3">
                        <div>
                            <h2 class="font-bold">
                                <Link
                                    :href="
                                        route(
                                            'maintenance.equipment.show',
                                            e.id,
                                        )
                                    "
                                    class="hover:text-teal-700 hover:underline"
                                    >{{ e.name }}</Link
                                >
                            </h2>
                            <p class="text-sm text-slate-500">
                                {{ e.location || "—" }} ·
                                {{
                                    ar ? e.category.name_ar : e.category.name_fr
                                }}
                            </p>
                            <p class="mt-2 text-sm">
                                {{ e.manufacturer }} {{ e.model }}
                                <span v-if="e.serial_number"
                                    >· {{ e.serial_number }}</span
                                >
                            </p>
                        </div>
                        <span
                            class="h-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold"
                            >{{ e.status }} · {{ e.condition }}</span
                        >
                    </div>
                    <form
                        class="mt-3 flex gap-2"
                        :action="
                            route('maintenance.equipment.transition', e.id)
                        "
                        method="post"
                        @submit.prevent="
                            $inertia.post(
                                route('maintenance.equipment.transition', e.id),
                                {
                                    action:
                                        e.status === 'active'
                                            ? 'retire'
                                            : 'reactivate',
                                    reason:
                                        e.status === 'active'
                                            ? 'Retrait opérationnel'
                                            : 'Réactivation autorisée',
                                },
                            )
                        "
                    >
                        <button
                            class="rounded-lg border px-3 py-2 text-xs font-bold"
                        >
                            {{
                                e.status === "active"
                                    ? ar
                                        ? "إحالة للتقاعد"
                                        : "Retirer"
                                    : ar
                                      ? "إعادة التفعيل"
                                      : "Réactiver"
                            }}
                        </button>
                    </form>
                </article>
                <Pagination :links="equipment.links" />
            </section>
            <form
                class="space-y-3 rounded-2xl border bg-white p-5"
                @submit.prevent="submit"
            >
                <h2 class="font-bold">
                    {{ ar ? "معدات جديدة" : "Nouvel équipement" }}
                </h2>
                <input
                    v-model="form.name"
                    required
                    :placeholder="ar ? 'الاسم' : 'Nom'"
                    class="w-full rounded-xl border-slate-300"
                /><select
                    v-model="form.maintenance_category_id"
                    required
                    class="w-full rounded-xl border-slate-300"
                >
                    <option value="">Catégorie</option>
                    <option
                        v-for="c in options.categories"
                        :key="c.id"
                        :value="c.id"
                    >
                        {{ ar ? c.name_ar : c.name_fr }}
                    </option></select
                ><input
                    v-model="form.location"
                    placeholder="Emplacement"
                    class="w-full rounded-xl border-slate-300"
                />
                <div class="grid grid-cols-2 gap-2">
                    <input
                        v-model="form.manufacturer"
                        placeholder="Fabricant"
                        class="rounded-xl border-slate-300"
                    /><input
                        v-model="form.model"
                        placeholder="Modèle"
                        class="rounded-xl border-slate-300"
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
                        v-for="c in ['good', 'fair', 'poor', 'critical']"
                        :key="c"
                    >
                        {{ c }}
                    </option></select
                ><textarea
                    v-model="form.public_description"
                    placeholder="Description visible aux résidents"
                    class="w-full rounded-xl border-slate-300"
                ></textarea
                ><textarea
                    v-model="form.internal_notes"
                    placeholder="Notes internes"
                    class="w-full rounded-xl border-slate-300"
                ></textarea
                ><button
                    class="w-full rounded-xl bg-teal-700 py-3 font-bold text-white"
                >
                    {{ ar ? "حفظ" : "Enregistrer" }}
                </button>
            </form>
        </div></AuthenticatedLayout
    >
</template>
