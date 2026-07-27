<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import InputError from "@/Components/InputError.vue";
import { useForm, usePage, Link } from "@inertiajs/vue3";
const props = defineProps<{ maintenanceRequest: any }>();
const ar = usePage<any>().props.locale === "ar";
const edit = useForm({
    title: props.maintenanceRequest.title,
    description: props.maintenanceRequest.description,
    location: props.maintenanceRequest.location ?? "",
    priority: props.maintenanceRequest.priority,
    observed_on: props.maintenanceRequest.observed_on?.slice(0, 10) ?? "",
});
const transition = useForm({ status: "", reason: "", idempotency_key: "" });
const move = (status: string) => {
    transition.status = status;
    transition.idempotency_key = crypto.randomUUID();
    transition.post(
        route("portal.maintenance.transition", props.maintenanceRequest.id),
        { preserveScroll: true },
    );
};
const note = useForm({ body: "", visibility: "resident" });
const publish = () =>
    note.post(
        route("portal.maintenance.updates.store", props.maintenanceRequest.id),
        { preserveScroll: true, onSuccess: () => note.reset("body") },
    );
const evidence = useForm<{
    file: File | null;
    kind: string;
    visibility: string;
}>({ file: null, kind: "evidence", visibility: "resident" });
const choose = (event: Event) =>
    (evidence.file = (event.target as HTMLInputElement).files?.[0] ?? null);
const upload = () =>
    evidence.post(
        route("maintenance.attachments.store", {
            type: "request",
            id: props.maintenanceRequest.id,
        }),
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => evidence.reset("file"),
        },
    );
</script>
<template>
    <AuthenticatedLayout
        :title="maintenanceRequest.title"
        :subtitle="maintenanceRequest.reference"
        ><template #actions
            ><Link
                :href="route('portal.maintenance.index')"
                class="rounded-xl border bg-white px-4 py-2 font-semibold"
                >{{ ar ? "رجوع" : "Retour" }}</Link
            ></template
        >
        <div class="mx-auto max-w-3xl min-w-0 space-y-5">
            <form
                v-if="maintenanceRequest.status === 'draft'"
                class="space-y-3 rounded-2xl border bg-white p-5"
                @submit.prevent="
                    edit.put(
                        route(
                            'portal.maintenance.update',
                            maintenanceRequest.id,
                        ),
                        { preserveScroll: true },
                    )
                "
            >
                <h2 class="font-bold">
                    {{ ar ? "تعديل المسودة" : "Modifier le brouillon" }}
                </h2>
                <input
                    v-model="edit.title"
                    required
                    class="w-full rounded-xl border-slate-300"
                />
                <textarea
                    v-model="edit.description"
                    required
                    rows="5"
                    class="w-full rounded-xl border-slate-300"
                ></textarea>
                <div class="grid gap-3 sm:grid-cols-2">
                    <input
                        v-model="edit.location"
                        :placeholder="ar ? 'الموقع' : 'Emplacement'"
                        class="min-w-0 rounded-xl border-slate-300"
                    />
                    <select
                        v-model="edit.priority"
                        class="min-w-0 rounded-xl border-slate-300"
                    >
                        <option
                            v-for="priority in [
                                'low',
                                'normal',
                                'high',
                                'urgent',
                            ]"
                            :key="priority"
                        >
                            {{ priority }}
                        </option>
                    </select>
                </div>
                <InputError
                    :message="edit.errors.title || edit.errors.description"
                />
                <button
                    class="w-full rounded-xl bg-slate-900 py-3 font-bold text-white"
                >
                    {{ ar ? "حفظ التعديلات" : "Enregistrer les modifications" }}
                </button>
            </form>
            <section class="min-w-0 rounded-2xl border bg-white p-5">
                <div class="flex flex-wrap gap-2">
                    <span
                        class="rounded-full bg-teal-50 px-3 py-1 text-sm font-bold text-teal-800"
                        >{{ maintenanceRequest.status }}</span
                    ><span
                        class="rounded-full bg-amber-50 px-3 py-1 text-sm font-bold"
                        >{{ maintenanceRequest.priority }}</span
                    >
                </div>
                <p class="mt-5 whitespace-pre-wrap break-words">
                    {{ maintenanceRequest.description }}
                </p>
                <p
                    v-if="maintenanceRequest.resolution_summary"
                    class="mt-4 rounded-xl bg-emerald-50 p-4"
                >
                    <b>{{ ar ? "ملخص الحل" : "Résumé de résolution" }}</b
                    ><br />{{ maintenanceRequest.resolution_summary }}
                </p>
            </section>
            <section class="rounded-2xl border bg-white p-5">
                <h2 class="mb-4 font-bold">
                    {{ ar ? "تقدم الطلب" : "Progression" }}
                </h2>
                <div
                    v-for="t in maintenanceRequest.transitions"
                    :key="t.id"
                    class="border-s-2 border-teal-300 py-2 ps-4"
                >
                    <b>{{ t.to_status }}</b>
                    <p class="text-xs text-slate-500">
                        {{ t.transitioned_at }}
                    </p>
                </div>
                <div
                    v-for="u in maintenanceRequest.updates"
                    :key="u.id"
                    class="mt-3 rounded-xl bg-slate-50 p-3"
                >
                    <p class="break-words">{{ u.body }}</p>
                    <small class="text-slate-500">{{ u.created_at }}</small>
                </div>
            </section>
            <form
                class="rounded-2xl border bg-white p-5"
                @submit.prevent="publish"
            >
                <label class="font-bold"
                    >{{ ar ? "إضافة معلومات" : "Ajouter une information"
                    }}<textarea
                        v-model="note.body"
                        required
                        class="mt-2 w-full rounded-xl border-slate-300"
                    ></textarea></label
                ><button
                    class="mt-2 rounded-xl bg-slate-900 px-4 py-2 text-white"
                >
                    {{ ar ? "نشر" : "Publier" }}
                </button>
            </form>
            <form
                class="rounded-2xl border bg-white p-5"
                @submit.prevent="upload"
            >
                <label class="font-bold"
                    >{{ ar ? "إضافة دليل" : "Ajouter une preuve"
                    }}<input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
                        required
                        class="mt-2 block w-full text-sm"
                        @change="choose" /></label
                ><InputError :message="evidence.errors.file" /><button
                    class="mt-3 rounded-xl border px-4 py-2 font-bold"
                >
                    {{ ar ? "رفع الملف" : "Téléverser" }}
                </button>
            </form>
            <section
                v-if="
                    ['draft', 'resolved', 'closed'].includes(
                        maintenanceRequest.status,
                    )
                "
                class="rounded-2xl border bg-white p-5"
            >
                <textarea
                    v-model="transition.reason"
                    class="w-full rounded-xl border-slate-300"
                    :placeholder="
                        ar
                            ? 'سبب إعادة الفتح عند الحاجة'
                            : 'Motif de réouverture si nécessaire'
                    "
                ></textarea
                ><InputError
                    :message="
                        transition.errors.reason || transition.errors.status
                    "
                />
                <div class="mt-2 flex gap-2">
                    <button
                        v-if="maintenanceRequest.status === 'draft'"
                        @click="move('submitted')"
                        class="rounded-xl bg-teal-700 px-4 py-3 font-bold text-white"
                    >
                        {{
                            ar ? "إرسال الطلب" : "Soumettre la demande"
                        }}</button
                    ><button
                        v-if="maintenanceRequest.status === 'resolved'"
                        @click="move('closed')"
                        class="rounded-xl bg-teal-700 px-4 py-3 font-bold text-white"
                    >
                        {{
                            ar ? "تأكيد الحل" : "Confirmer la résolution"
                        }}</button
                    ><button
                        v-if="
                            ['resolved', 'closed'].includes(
                                maintenanceRequest.status,
                            )
                        "
                        @click="move('in_progress')"
                        class="rounded-xl border px-4 py-3 font-bold"
                    >
                        {{ ar ? "إعادة الفتح" : "Réouvrir" }}
                    </button>
                </div>
            </section>
        </div></AuthenticatedLayout
    >
</template>
