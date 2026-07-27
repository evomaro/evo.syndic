<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Pagination from "@/Components/Pagination.vue";
import InputError from "@/Components/InputError.vue";
import { Link, useForm, usePage } from "@inertiajs/vue3";
defineProps<{ requests: any; planned: any; categories: any }>();
const ar = usePage<any>().props.locale === "ar";
const form = useForm({
    maintenance_category_id: "",
    title: "",
    description: "",
    location: "",
    priority: "normal",
    observed_on: new Date().toISOString().slice(0, 10),
    contact_method: "app",
    contact_details: "",
    contact_visible_to_assignees: false,
});
const submit = () =>
    form.post(route("portal.maintenance.store"), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
const date = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat(ar ? "ar-MA" : "fr-MA", {
              dateStyle: "medium",
          }).format(new Date(value.slice(0, 10) + "T00:00:00"))
        : "—";
</script>
<template>
    <AuthenticatedLayout
        :title="ar ? 'طلبات الصيانة الخاصة بي' : 'Mes demandes de maintenance'"
        :subtitle="
            ar
                ? 'تتبع آمن ومحدد لإقامتك'
                : 'Suivi privé limité à votre résidence'
        "
        ><div class="grid min-w-0 gap-6 lg:grid-cols-[1fr_24rem]">
            <main class="min-w-0 space-y-6">
                <section>
                    <div
                        v-if="!requests.data.length"
                        class="rounded-2xl border bg-white p-10 text-center text-slate-500"
                    >
                        {{
                            ar
                                ? "لم ترسل أي طلب بعد"
                                : "Vous n’avez encore envoyé aucune demande"
                        }}
                    </div>
                    <Link
                        v-for="r in requests.data"
                        :key="r.id"
                        :href="route('portal.maintenance.show', r.id)"
                        class="mb-3 block min-w-0 rounded-2xl border bg-white p-4 shadow-sm hover:border-teal-300"
                        ><div
                            class="flex min-w-0 items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-bold">{{ r.title }}</p>
                                <p class="text-sm text-slate-500">
                                    {{ r.reference }} ·
                                    {{
                                        ar
                                            ? r.category.name_ar
                                            : r.category.name_fr
                                    }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 rounded-full bg-teal-50 px-3 py-1 text-xs font-bold text-teal-800"
                                >{{ r.status }}</span
                            >
                        </div>
                        <p class="mt-3 line-clamp-2 text-sm text-slate-600">
                            {{ r.description }}
                        </p></Link
                    ><Pagination :links="requests.links" />
                </section>
                <section v-if="planned.length">
                    <h2 class="mb-3 text-lg font-bold">
                        {{
                            ar
                                ? "تدخلات المناطق المشتركة"
                                : "Interventions des parties communes"
                        }}
                    </h2>
                    <article
                        v-for="p in planned"
                        :key="p.id"
                        class="mb-3 rounded-2xl border border-blue-100 bg-blue-50 p-4"
                    >
                        <b>{{ p.resident_notes }}</b>
                        <p class="mt-1 text-sm">
                            {{ date(p.planned_start_at) }} · {{ p.status }}
                        </p>
                    </article>
                </section>
            </main>
            <form
                class="h-fit min-w-0 space-y-3 rounded-2xl border bg-white p-5 lg:sticky lg:top-24"
                @submit.prevent="submit"
            >
                <h2 class="text-lg font-bold">
                    {{ ar ? "إرسال طلب" : "Soumettre une demande" }}
                </h2>
                <select
                    v-model="form.maintenance_category_id"
                    required
                    class="w-full rounded-xl border-slate-300"
                >
                    <option value="">
                        {{ ar ? "اختر الفئة" : "Choisir une catégorie" }}
                    </option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">
                        {{ ar ? c.name_ar : c.name_fr }}
                    </option></select
                ><InputError
                    :message="form.errors.maintenance_category_id"
                /><input
                    v-model="form.title"
                    required
                    :placeholder="ar ? 'عنوان موجز' : 'Titre concis'"
                    class="w-full rounded-xl border-slate-300"
                /><InputError :message="form.errors.title" /><textarea
                    v-model="form.description"
                    required
                    rows="5"
                    :placeholder="
                        ar
                            ? 'صف المشكلة والموقع'
                            : 'Décrivez le problème et le lieu'
                    "
                    class="w-full rounded-xl border-slate-300"
                ></textarea
                ><InputError :message="form.errors.description" /><input
                    v-model="form.location"
                    :placeholder="ar ? 'الموقع' : 'Emplacement'"
                    class="w-full rounded-xl border-slate-300"
                /><select
                    v-model="form.priority"
                    class="w-full rounded-xl border-slate-300"
                >
                    <option value="low">{{ ar ? "منخفضة" : "Basse" }}</option>
                    <option value="normal">
                        {{ ar ? "عادية" : "Normale" }}
                    </option>
                    <option value="high">{{ ar ? "عالية" : "Haute" }}</option>
                    <option value="urgent">
                        {{ ar ? "عاجلة" : "Urgente" }}
                    </option></select
                ><button
                    :disabled="form.processing"
                    class="w-full rounded-xl bg-teal-700 py-3 font-bold text-white disabled:opacity-50"
                >
                    {{ ar ? "حفظ المسودة" : "Enregistrer le brouillon" }}
                </button>
            </form>
        </div></AuthenticatedLayout
    >
</template>
