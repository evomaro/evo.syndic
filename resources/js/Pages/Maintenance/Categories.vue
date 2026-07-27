<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import MaintenanceNav from "@/Components/Maintenance/MaintenanceNav.vue";
import Pagination from "@/Components/Pagination.vue";
import { useForm, usePage } from "@inertiajs/vue3";
defineProps<{ categories: any }>();
const ar = usePage<any>().props.locale === "ar";
const form = useForm({
    name_fr: "",
    name_ar: "",
    description_fr: "",
    description_ar: "",
    default_priority: "normal",
    ack_target_minutes: 1440,
    schedule_target_minutes: 2880,
    resolution_target_minutes: 10080,
    responsible_user_id: null,
    active: true,
    sort_order: 0,
});
const submit = () =>
    form.post(route("maintenance.categories.store"), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
</script>
<template>
    <AuthenticatedLayout
        :title="ar ? 'فئات الصيانة' : 'Catégories de maintenance'"
        ><MaintenanceNav />
        <div class="mb-4">
            <button
                @click="$inertia.post(route('maintenance.categories.seed'))"
                class="rounded-xl border bg-white px-4 py-2 font-semibold"
            >
                {{
                    ar
                        ? "إضافة الفئات الافتراضية"
                        : "Vérifier les catégories par défaut"
                }}
            </button>
        </div>
        <div class="grid gap-5 xl:grid-cols-[1fr_25rem]">
            <section class="overflow-hidden rounded-2xl border bg-white">
                <form
                    v-for="c in categories.data"
                    :key="c.id"
                    class="grid gap-2 border-b p-4 sm:grid-cols-[1fr_1fr_9rem]"
                    @submit.prevent="
                        $inertia.put(
                            route('maintenance.categories.update', c.id),
                            c,
                            { preserveScroll: true },
                        )
                    "
                >
                    <input
                        v-model="c.name_fr"
                        aria-label="Nom français"
                        class="rounded-lg border-slate-300 font-bold"
                    /><input
                        v-model="c.name_ar"
                        aria-label="الاسم بالعربية"
                        dir="rtl"
                        class="rounded-lg border-slate-300 font-bold"
                    /><select
                        v-model="c.default_priority"
                        class="rounded-lg border-slate-300 text-sm"
                    >
                        <option
                            v-for="p in ['low', 'normal', 'high', 'urgent']"
                            :key="p"
                        >
                            {{ p }}
                        </option>
                    </select>
                    <p class="text-xs text-slate-500 sm:col-span-2">
                        Ack {{ c.ack_target_minutes }} min · Schedule
                        {{ c.schedule_target_minutes }} min · Resolution
                        {{ c.resolution_target_minutes }} min
                    </p>
                    <button
                        class="rounded-lg border px-3 py-2 text-xs font-bold"
                    >
                        {{ ar ? "تحديث" : "Mettre à jour" }}
                    </button>
                </form>
                <Pagination class="p-4" :links="categories.links" />
            </section>
            <form
                class="space-y-3 rounded-2xl border bg-white p-5"
                @submit.prevent="submit"
            >
                <h2 class="font-bold">
                    {{ ar ? "فئة جديدة" : "Nouvelle catégorie" }}
                </h2>
                <input
                    v-model="form.name_fr"
                    required
                    placeholder="Nom français"
                    class="w-full rounded-xl border-slate-300"
                /><input
                    v-model="form.name_ar"
                    required
                    dir="rtl"
                    placeholder="الاسم بالعربية"
                    class="w-full rounded-xl border-slate-300"
                /><select
                    v-model="form.default_priority"
                    class="w-full rounded-xl border-slate-300"
                >
                    <option
                        v-for="p in ['low', 'normal', 'high', 'urgent']"
                        :key="p"
                    >
                        {{ p }}
                    </option></select
                ><label class="block text-sm"
                    >Accusé (minutes)<input
                        v-model.number="form.ack_target_minutes"
                        type="number"
                        min="1"
                        class="mt-1 w-full rounded-xl border-slate-300" /></label
                ><label class="block text-sm"
                    >Planification (minutes)<input
                        v-model.number="form.schedule_target_minutes"
                        type="number"
                        min="1"
                        class="mt-1 w-full rounded-xl border-slate-300" /></label
                ><label class="block text-sm"
                    >Résolution (minutes)<input
                        v-model.number="form.resolution_target_minutes"
                        type="number"
                        min="1"
                        class="mt-1 w-full rounded-xl border-slate-300" /></label
                ><button
                    class="w-full rounded-xl bg-teal-700 py-3 font-bold text-white"
                >
                    {{ ar ? "حفظ" : "Enregistrer" }}
                </button>
            </form>
        </div></AuthenticatedLayout
    >
</template>
