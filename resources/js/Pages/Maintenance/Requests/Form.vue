<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import MaintenanceNav from "@/Components/Maintenance/MaintenanceNav.vue";
import InputError from "@/Components/InputError.vue";
import { useForm, usePage } from "@inertiajs/vue3";
const props = defineProps<{ options: any; maintenanceRequest?: any }>();
const page = usePage<any>();
const ar = page.props.locale === "ar";
const form = useForm({
    maintenance_category_id:
        props.maintenanceRequest?.maintenance_category_id ?? "",
    equipment_id: props.maintenanceRequest?.equipment_id ?? "",
    building_id: props.maintenanceRequest?.building_id ?? "",
    title: props.maintenanceRequest?.title ?? "",
    description: props.maintenanceRequest?.description ?? "",
    location: props.maintenanceRequest?.location ?? "",
    priority: props.maintenanceRequest?.priority ?? "normal",
    observed_on:
        props.maintenanceRequest?.observed_on?.slice(0, 10) ??
        new Date().toISOString().slice(0, 10),
    contact_method: props.maintenanceRequest?.contact_method ?? "app",
    contact_details: props.maintenanceRequest?.contact_details ?? "",
    contact_visible_to_assignees:
        props.maintenanceRequest?.contact_visible_to_assignees ?? false,
});
const submit = () =>
    props.maintenanceRequest
        ? form.put(
              route("maintenance.requests.update", props.maintenanceRequest.id),
          )
        : form.post(route("maintenance.requests.store"));
</script>
<template>
    <AuthenticatedLayout
        :title="
            maintenanceRequest
                ? ar
                    ? 'تعديل طلب الصيانة'
                    : 'Modifier la demande'
                : ar
                  ? 'طلب صيانة جديد'
                  : 'Nouvelle demande de maintenance'
        "
        ><MaintenanceNav />
        <form
            class="mx-auto max-w-3xl space-y-5 rounded-2xl border bg-white p-5 sm:p-7"
            @submit.prevent="submit"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm font-semibold"
                    >{{ ar ? "الفئة" : "Catégorie"
                    }}<select
                        v-model="form.maintenance_category_id"
                        required
                        class="mt-1 w-full rounded-xl border-slate-300"
                    >
                        <option value="">—</option>
                        <option
                            v-for="c in options.categories"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ ar ? c.name_ar : c.name_fr }}
                        </option></select
                    ><InputError
                        :message="form.errors.maintenance_category_id" /></label
                ><label class="text-sm font-semibold"
                    >{{ ar ? "الأولوية" : "Priorité"
                    }}<select
                        v-model="form.priority"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    >
                        <option
                            v-for="p in ['low', 'normal', 'high', 'urgent']"
                            :key="p"
                        >
                            {{ p }}
                        </option>
                    </select></label
                >
            </div>
            <label class="block text-sm font-semibold"
                >{{ ar ? "العنوان" : "Titre"
                }}<input
                    v-model="form.title"
                    required
                    maxlength="255"
                    class="mt-1 w-full rounded-xl border-slate-300" /><InputError
                    :message="form.errors.title" /></label
            ><label class="block text-sm font-semibold"
                >{{ ar ? "الوصف" : "Description"
                }}<textarea
                    v-model="form.description"
                    required
                    rows="6"
                    class="mt-1 w-full rounded-xl border-slate-300"
                ></textarea
                ><InputError :message="form.errors.description"
            /></label>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm font-semibold"
                    >{{ ar ? "الموقع" : "Emplacement"
                    }}<input
                        v-model="form.location"
                        class="mt-1 w-full rounded-xl border-slate-300" /></label
                ><label class="text-sm font-semibold"
                    >{{ ar ? "تاريخ المعاينة" : "Date observée"
                    }}<input
                        v-model="form.observed_on"
                        type="date"
                        class="mt-1 w-full rounded-xl border-slate-300" /></label
                ><label class="text-sm font-semibold"
                    >{{ ar ? "المعدة" : "Équipement"
                    }}<select
                        v-model="form.equipment_id"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    >
                        <option value="">—</option>
                        <option
                            v-for="e in options.equipment"
                            :key="e.id"
                            :value="e.id"
                        >
                            {{ e.name }}
                        </option>
                    </select></label
                ><label class="text-sm font-semibold"
                    >{{ ar ? "وسيلة التواصل" : "Contact préféré"
                    }}<select
                        v-model="form.contact_method"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    >
                        <option value="app">Application</option>
                        <option value="email">E-mail</option>
                        <option value="phone">Téléphone</option>
                    </select></label
                >
            </div>
            <button
                :disabled="form.processing"
                class="w-full rounded-xl bg-teal-700 px-5 py-3 font-bold text-white disabled:opacity-50"
            >
                {{ ar ? "حفظ المسودة" : "Enregistrer le brouillon" }}
            </button>
        </form></AuthenticatedLayout
    >
</template>
