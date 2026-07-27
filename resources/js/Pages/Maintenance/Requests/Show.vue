<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import MaintenanceNav from "@/Components/Maintenance/MaintenanceNav.vue";
import InputError from "@/Components/InputError.vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
const props = defineProps<{ maintenanceRequest: any; options: any }>();
const page = usePage<any>();
const ar = page.props.locale === "ar";
const permissions = page.props.auth.permissions ?? [];
const transition = useForm({ status: "", reason: "", idempotency_key: "" });
const move = (status: string) => {
    transition.status = status;
    transition.idempotency_key = crypto.randomUUID();
    transition.post(
        route("maintenance.requests.transition", props.maintenanceRequest.id),
        { preserveScroll: true },
    );
};
const update = useForm({ body: "", visibility: "resident" });
const publish = () =>
    update.post(
        route(
            "maintenance.requests.updates.store",
            props.maintenanceRequest.id,
        ),
        { preserveScroll: true, onSuccess: () => update.reset("body") },
    );
const quote = useForm({
    supplier_id: "",
    supplier_reference: "",
    subtotal_cents: 0,
    tax_cents: 0,
    total_cents: 0,
    submitted_on: new Date().toISOString().slice(0, 10),
    valid_until: "",
    internal_notes: "",
});
const addQuote = () =>
    quote.post(
        route("maintenance.quotations.store", props.maintenanceRequest.id),
        { preserveScroll: true },
    );
const order = useForm({
    maintenance_request_id: props.maintenanceRequest.id,
    preventive_intervention_id: null,
    equipment_id: props.maintenanceRequest.equipment_id,
    supplier_id: "",
    accepted_quotation_id: "",
    scope_of_work: props.maintenanceRequest.title,
    planned_start_at: "",
    planned_end_at: "",
    estimated_cost_cents: null,
    is_primary: true,
});
const addOrder = () =>
    order.post(route("maintenance.work-orders.store"), {
        preserveScroll: true,
    });
const acceptedQuotations = (): any[] =>
    props.maintenanceRequest.quotations.filter(
        (quotation: any) => quotation.status === "accepted",
    );
const replaceQuote = (acceptedId: number, replacementId: number) => {
    const reason = window.prompt(
        ar
            ? "سبب استبدال العرض المقبول"
            : "Motif obligatoire du remplacement du devis accepté",
    );
    if (!reason?.trim()) return;
    if (
        !window.confirm(
            ar
                ? "هل تؤكد استبدال العرض المقبول؟"
                : "Confirmer le remplacement du devis accepté ?",
        )
    )
        return;
    router.post(
        route("maintenance.quotations.replace", acceptedId),
        { replacement_id: replacementId, reason },
        { preserveScroll: true },
    );
};
const documentForm = useForm<{
    file: File | null;
    kind: string;
    visibility: string;
}>({ file: null, kind: "internal", visibility: "internal" });
const chooseDocument = (event: Event) =>
    (documentForm.file = (event.target as HTMLInputElement).files?.[0] ?? null);
const uploadDocument = () =>
    documentForm.post(
        route("maintenance.attachments.store", {
            type: "request",
            id: props.maintenanceRequest.id,
        }),
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => documentForm.reset("file"),
        },
    );
</script>
<template>
    <AuthenticatedLayout
        :title="maintenanceRequest.reference"
        :subtitle="maintenanceRequest.title"
        ><template #actions
            ><a
                v-if="
                    ['draft', 'submitted', 'under_review'].includes(
                        maintenanceRequest.status,
                    )
                "
                :href="
                    route('maintenance.requests.edit', maintenanceRequest.id)
                "
                class="rounded-xl border bg-white px-4 py-2 font-semibold"
                >{{ ar ? "تعديل" : "Modifier" }}</a
            ></template
        ><MaintenanceNav />
        <div class="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,1fr)_23rem]">
            <main class="min-w-0 space-y-5">
                <section class="min-w-0 rounded-2xl border bg-white p-5">
                    <div class="mb-4 flex flex-wrap gap-2">
                        <span
                            class="rounded-full bg-slate-100 px-3 py-1 text-sm font-bold"
                            >{{ maintenanceRequest.status }}</span
                        ><span
                            class="rounded-full bg-amber-100 px-3 py-1 text-sm font-bold"
                            >{{ maintenanceRequest.priority }}</span
                        >
                    </div>
                    <p class="whitespace-pre-wrap break-words text-slate-700">
                        {{ maintenanceRequest.description }}
                    </p>
                    <dl class="mt-5 grid min-w-0 gap-3 text-sm sm:grid-cols-3">
                        <div class="min-w-0">
                            <dt class="text-slate-500">SLA acknowledgement</dt>
                            <dd class="break-all">
                                {{ maintenanceRequest.ack_deadline_at || "—" }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-slate-500">SLA planification</dt>
                            <dd class="break-all">
                                {{
                                    maintenanceRequest.schedule_deadline_at ||
                                    "—"
                                }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-slate-500">SLA résolution</dt>
                            <dd class="break-all">
                                {{
                                    maintenanceRequest.resolution_deadline_at ||
                                    "—"
                                }}
                            </dd>
                        </div>
                    </dl>
                </section>
                <section class="min-w-0 rounded-2xl border bg-white p-5">
                    <h2 class="mb-4 text-lg font-bold">
                        {{ ar ? "التسلسل الزمني" : "Chronologie" }}
                    </h2>
                    <div
                        v-for="item in maintenanceRequest.transitions"
                        :key="item.id"
                        class="border-s-2 border-teal-300 py-2 ps-4"
                    >
                        <b>{{ item.from_status }} → {{ item.to_status }}</b>
                        <p class="text-sm text-slate-500">
                            {{ item.transitioned_at }} · {{ item.actor?.name }}
                        </p>
                        <p v-if="item.reason" class="text-sm">
                            {{ item.reason }}
                        </p>
                    </div>
                    <div
                        v-for="item in maintenanceRequest.updates"
                        :key="'u' + item.id"
                        class="mt-3 rounded-xl bg-slate-50 p-3"
                    >
                        <span
                            class="text-xs font-bold uppercase text-teal-700"
                            >{{ item.visibility }}</span
                        >
                        <p>{{ item.body }}</p>
                        <small
                            >{{ item.author?.name }} ·
                            {{ item.created_at }}</small
                        >
                    </div>
                    <form class="mt-4" @submit.prevent="publish">
                        <textarea
                            v-model="update.body"
                            required
                            class="w-full rounded-xl border-slate-300"
                            :placeholder="
                                ar ? 'تحديث جديد' : 'Nouvelle mise à jour'
                            "
                        ></textarea>
                        <div class="mt-2 flex gap-2">
                            <select
                                v-model="update.visibility"
                                class="rounded-xl border-slate-300"
                            >
                                <option value="resident">
                                    Resident-visible
                                </option>
                                <option value="internal">
                                    Interne
                                </option></select
                            ><button
                                class="rounded-xl bg-slate-900 px-4 text-white"
                            >
                                {{ ar ? "نشر" : "Publier" }}
                            </button>
                        </div>
                    </form>
                </section>
                <section class="rounded-2xl border bg-white p-5">
                    <h2 class="mb-3 font-bold">
                        {{ ar ? "الوثائق الخاصة" : "Documents privés" }}
                    </h2>
                    <a
                        v-for="file in maintenanceRequest.attachments"
                        :key="file.id"
                        :href="
                            route('maintenance.attachments.download', file.id)
                        "
                        class="me-2 inline-block max-w-full break-all rounded-lg border px-3 py-2 text-sm font-semibold"
                        >{{ file.name }} · {{ file.visibility }}</a
                    >
                    <form
                        class="mt-4 flex min-w-0 flex-wrap gap-2"
                        @submit.prevent="uploadDocument"
                    >
                        <input
                            type="file"
                            required
                            class="max-w-full min-w-0 text-sm"
                            @change="chooseDocument"
                        /><select
                            v-model="documentForm.visibility"
                            class="rounded-lg border-slate-300"
                        >
                            <option value="internal">Interne</option>
                            <option value="resident">Résident</option></select
                        ><button
                            class="rounded-lg bg-slate-900 px-4 py-2 text-white"
                        >
                            Téléverser
                        </button>
                    </form>
                </section>
                <section
                    v-if="permissions.includes('view_maintenance_quotations')"
                    class="min-w-0 rounded-2xl border bg-white p-5"
                >
                    <h2 class="mb-4 text-lg font-bold">
                        {{ ar ? "عروض الموردين" : "Devis fournisseurs" }}
                    </h2>
                    <div
                        v-for="q in maintenanceRequest.quotations"
                        :key="q.id"
                        class="mb-2 flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-xl border p-3"
                    >
                        <span class="min-w-0 break-words"
                            >{{ q.supplier.legal_name }} ·
                            {{ (q.total_cents / 100).toFixed(2) }} MAD ·
                            {{ q.status }}</span
                        ><button
                            v-if="
                                q.status === 'received' &&
                                permissions.includes(
                                    'accept_maintenance_quotations',
                                )
                            "
                            @click="
                                $inertia.post(
                                    route(
                                        'maintenance.quotations.accept',
                                        q.id,
                                    ),
                                )
                            "
                            class="rounded-lg bg-teal-700 px-3 py-2 text-white"
                        >
                            Accepter
                        </button>
                        <button
                            v-if="
                                q.status === 'received' &&
                                acceptedQuotations().length &&
                                permissions.includes(
                                    'accept_maintenance_quotations',
                                )
                            "
                            type="button"
                            class="rounded-lg border border-amber-300 px-3 py-2 text-amber-800"
                            @click="
                                replaceQuote(acceptedQuotations()[0].id, q.id)
                            "
                        >
                            {{ ar ? "استبدال" : "Remplacer" }}
                        </button>
                    </div>
                    <form
                        v-if="
                            permissions.includes(
                                'manage_maintenance_quotations',
                            )
                        "
                        class="mt-4 grid gap-3 sm:grid-cols-3"
                        @submit.prevent="addQuote"
                    >
                        <select
                            v-model="quote.supplier_id"
                            required
                            class="rounded-xl border-slate-300"
                        >
                            <option value="">Fournisseur</option>
                            <option
                                v-for="s in options.suppliers"
                                :key="s.id"
                                :value="s.id"
                            >
                                {{ s.legal_name }}
                            </option></select
                        ><input
                            v-model.number="quote.subtotal_cents"
                            type="number"
                            min="0"
                            placeholder="HT centimes"
                            class="rounded-xl border-slate-300"
                        /><input
                            v-model.number="quote.tax_cents"
                            type="number"
                            min="0"
                            placeholder="TVA centimes"
                            class="rounded-xl border-slate-300"
                        /><input
                            v-model.number="quote.total_cents"
                            type="number"
                            min="1"
                            placeholder="TTC centimes"
                            class="rounded-xl border-slate-300"
                        /><input
                            v-model="quote.submitted_on"
                            type="date"
                            class="rounded-xl border-slate-300"
                        /><button
                            class="rounded-xl bg-slate-900 px-4 text-white"
                        >
                            Ajouter
                        </button>
                    </form>
                </section>
                <section
                    v-if="permissions.includes('manage_work_orders')"
                    class="rounded-2xl border bg-white p-5"
                >
                    <h2 class="mb-4 text-lg font-bold">
                        {{ ar ? "أوامر العمل" : "Bons de travail" }}
                    </h2>
                    <div
                        v-for="w in maintenanceRequest.work_orders"
                        :key="w.id"
                        class="mb-2 rounded-xl border p-3"
                    >
                        {{ w.reference }} · {{ w.status }}
                    </div>
                    <form
                        class="grid gap-3 sm:grid-cols-2"
                        @submit.prevent="addOrder"
                    >
                        <select
                            v-model="order.supplier_id"
                            class="rounded-xl border-slate-300"
                        >
                            <option value="">Interne</option>
                            <option
                                v-for="s in options.suppliers"
                                :key="s.id"
                                :value="s.id"
                            >
                                {{ s.legal_name }}
                            </option></select
                        ><select
                            v-model="order.accepted_quotation_id"
                            class="rounded-xl border-slate-300"
                        >
                            <option value="">Sans devis</option>
                            <option
                                v-for="q in acceptedQuotations()"
                                :key="q.id"
                                :value="q.id"
                            >
                                {{ q.supplier_reference || q.id }}
                            </option></select
                        ><textarea
                            v-model="order.scope_of_work"
                            required
                            class="rounded-xl border-slate-300 sm:col-span-2"
                        ></textarea
                        ><button
                            class="rounded-xl bg-slate-900 px-4 py-2 text-white sm:col-span-2"
                        >
                            Créer le bon
                        </button>
                    </form>
                </section>
            </main>
            <aside>
                <section class="sticky top-24 rounded-2xl border bg-white p-5">
                    <h2 class="mb-4 font-bold">
                        {{ ar ? "تغيير الحالة" : "Changer le statut" }}
                    </h2>
                    <textarea
                        v-model="transition.reason"
                        class="w-full rounded-xl border-slate-300"
                        :placeholder="
                            ar ? 'السبب أو التقرير' : 'Motif ou résumé'
                        "
                    ></textarea
                    ><InputError
                        :message="
                            transition.errors.reason || transition.errors.status
                        "
                    />
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            v-for="s in [
                                'submitted',
                                'under_review',
                                'approved',
                                'in_progress',
                                'resolved',
                                'closed',
                                'rejected',
                                'cancelled',
                            ]"
                            :key="s"
                            @click="move(s)"
                            class="rounded-lg border px-2 py-2 text-xs font-bold hover:bg-slate-50"
                        >
                            {{ s }}
                        </button>
                    </div>
                </section>
            </aside>
        </div></AuthenticatedLayout
    >
</template>
