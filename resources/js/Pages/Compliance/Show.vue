<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
const props = defineProps([
    "obligation",
    "members",
    "evidenceTypes",
    "disclaimer",
]);
const obligation = props.obligation as any;
const members = props.members as any[];
const evidenceTypes = props.evidenceTypes as string[];
const disclaimer = props.disclaimer as string;
const page = usePage<any>();
const locale = computed(() => page.props.locale ?? "fr");
const title = computed(() =>
    locale.value === "ar"
        ? obligation.template_version.title_ar
        : obligation.template_version.title_fr,
);
const labels: Record<string, Record<string, string>> = {
    fr: {
        upcoming: "À venir",
        in_preparation: "En préparation",
        ready_for_review: "Prête pour contrôle",
        ready_for_submission: "Prête à soumettre",
        submitted: "Soumise",
        acknowledged: "Accusée",
        accepted: "Acceptée",
        rejected: "Rejetée",
        correction_required: "Correction requise",
        completed_internally: "Terminée en interne",
        waived: "Dispensée",
        not_applicable: "Non applicable",
        cancelled: "Annulée",
        due_soon: "Échéance proche",
        overdue: "En retard",
        unavailable: "Indisponible",
        source_verified: "Source vérifiée",
        professionally_reviewed: "Revue professionnelle",
        counsel_reviewed: "Revue du conseil",
        unverified_draft: "Brouillon non vérifié",
        preparation_document: "Document de préparation",
        submitted_form: "Formulaire soumis",
        submission_receipt: "Reçu de soumission",
        payment_receipt: "Reçu de paiement",
        authority_acknowledgement: "Accusé de l’autorité",
        rejection_notice: "Avis de rejet",
        approval_record: "Preuve d’acceptation",
        correspondence: "Correspondance",
        internal_note: "Note interne",
        source_document: "Document source",
    },
    ar: {
        upcoming: "قادمة",
        in_preparation: "قيد الإعداد",
        ready_for_review: "جاهزة للمراجعة",
        ready_for_submission: "جاهزة للإيداع",
        submitted: "تم إيداعها",
        acknowledged: "تم الإشعار باستلامها",
        accepted: "مقبولة",
        rejected: "مرفوضة",
        correction_required: "يلزم التصحيح",
        completed_internally: "مكتملة داخلياً",
        waived: "معفاة",
        not_applicable: "غير منطبقة",
        cancelled: "ملغاة",
        due_soon: "قريبة الاستحقاق",
        overdue: "متأخرة",
        unavailable: "غير متاحة",
        source_verified: "مصدر متحقق منه",
        professionally_reviewed: "مراجعة مهنية",
        counsel_reviewed: "مراجعة المستشار",
        unverified_draft: "مسودة غير متحقق منها",
        preparation_document: "وثيقة إعداد",
        submitted_form: "استمارة مودعة",
        submission_receipt: "وصل الإيداع",
        payment_receipt: "وصل الأداء",
        authority_acknowledgement: "إشعار السلطة بالاستلام",
        rejection_notice: "إشعار الرفض",
        approval_record: "دليل القبول",
        correspondence: "مراسلة",
        internal_note: "ملاحظة داخلية",
        source_document: "وثيقة المصدر",
    },
};
const label = (value: string) =>
    labels[locale.value]?.[value] ??
    (locale.value === "ar" ? "حالة موثقة" : "État documenté");
const assign = useForm({
    user_id: "",
    role: "",
    assignment_type: "responsible",
});
const submission = useForm({
    submitted_on: new Date().toISOString().slice(0, 10),
    method: "",
    reference: "",
    notes: "",
});
const transition = useForm({
    status: "in_preparation",
    reason: "",
    evidence_id: "",
});
const evidence = useForm({
    type: "preparation_document",
    title: "",
    submission_id: "",
    file: null as File | null,
});
</script>
<template>
    <Head :title="title" />
    <AuthenticatedLayout
        :title="title"
        :subtitle="`${obligation.template.code} · v${obligation.template_version.version}`"
    >
        <div class="space-y-5">
            <Link
                :href="route('compliance.index')"
                class="text-sm font-semibold text-indigo-700"
                >← Retour au registre</Link
            >
            <div
                role="note"
                class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm"
            >
                {{ disclaimer }}
            </div>
            <div class="grid gap-4 lg:grid-cols-3">
                <section class="rounded-xl border bg-white p-4">
                    <h2 class="font-bold">Échéance</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div>
                            <dt class="text-slate-500">Originale</dt>
                            <dd>
                                {{
                                    obligation.original_due_on ?? "Indisponible"
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Courante</dt>
                            <dd>
                                {{
                                    obligation.current_due_on ?? "Indisponible"
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Fuseau</dt>
                            <dd>{{ obligation.timezone }}</dd>
                        </div>
                    </dl>
                </section>
                <section class="rounded-xl border bg-white p-4">
                    <h2 class="font-bold">Source et autorité</h2>
                    <p class="mt-3 text-sm">
                        {{
                            locale === "ar"
                                ? obligation.template.authority.name_ar
                                : obligation.template.authority.name_fr
                        }}
                    </p>
                    <p class="text-sm text-slate-500">
                        {{ obligation.template_version.source.official_title }}
                        ·
                        {{
                            label(obligation.template_version.source.confidence)
                        }}
                    </p>
                </section>
                <section class="rounded-xl border bg-white p-4">
                    <h2 class="font-bold">État</h2>
                    <p class="mt-3">
                        {{ label(obligation.operational_status) }}
                    </p>
                    <p class="text-sm text-slate-500">
                        {{ label(obligation.deadline_status) }}
                    </p>
                </section>
            </div>
            <div class="grid gap-4 xl:grid-cols-2">
                <form
                    class="space-y-3 rounded-xl border bg-white p-4"
                    @submit.prevent="
                        assign.post(
                            route(
                                'compliance.obligations.assign',
                                obligation.id,
                            ),
                        )
                    "
                >
                    <h2 class="font-bold">Affecter</h2>
                    <select
                        v-model="assign.user_id"
                        class="w-full rounded-lg border-slate-300"
                    >
                        <option value="">Non assignée / rôle seulement</option>
                        <option
                            v-for="user in members"
                            :key="user.id"
                            :value="user.id"
                        >
                            {{ user.name }}
                        </option></select
                    ><input
                        v-model="assign.role"
                        placeholder="Rôle responsable"
                        class="w-full rounded-lg border-slate-300"
                    /><select
                        v-model="assign.assignment_type"
                        class="w-full rounded-lg border-slate-300"
                    >
                        <option value="responsible">Responsable</option>
                        <option value="reviewer">Contrôleur</option>
                        <option value="escalation">Escalade</option>
                        <option value="watcher">Observateur</option></select
                    ><button
                        class="rounded-lg bg-indigo-700 px-4 py-2 text-white"
                    >
                        Enregistrer
                    </button>
                </form>
                <form
                    class="space-y-3 rounded-xl border bg-white p-4"
                    @submit.prevent="
                        submission.post(
                            route(
                                'compliance.submissions.store',
                                obligation.id,
                            ),
                        )
                    "
                >
                    <h2 class="font-bold">Enregistrer une soumission</h2>
                    <input
                        v-model="submission.submitted_on"
                        required
                        type="date"
                        class="w-full rounded-lg border-slate-300"
                    /><input
                        v-model="submission.method"
                        required
                        placeholder="Méthode explicite"
                        class="w-full rounded-lg border-slate-300"
                    /><input
                        v-model="submission.reference"
                        placeholder="Référence"
                        class="w-full rounded-lg border-slate-300"
                    /><button
                        class="rounded-lg bg-indigo-700 px-4 py-2 text-white"
                    >
                        Ajouter la tentative
                    </button>
                </form>
                <form
                    class="space-y-3 rounded-xl border bg-white p-4"
                    @submit.prevent="
                        transition.post(
                            route(
                                'compliance.obligations.transition',
                                obligation.id,
                            ),
                        )
                    "
                >
                    <h2 class="font-bold">Transition contrôlée</h2>
                    <select
                        v-model="transition.status"
                        class="w-full rounded-lg border-slate-300"
                    >
                        <option
                            v-for="state in [
                                'in_preparation',
                                'ready_for_review',
                                'ready_for_submission',
                                'submitted',
                                'acknowledged',
                                'accepted',
                                'rejected',
                                'correction_required',
                                'completed_internally',
                                'waived',
                                'not_applicable',
                                'cancelled',
                            ]"
                            :key="state"
                            :value="state"
                        >
                            {{ label(state) }}
                        </option></select
                    ><textarea
                        v-model="transition.reason"
                        placeholder="Motif lorsque requis"
                        class="w-full rounded-lg border-slate-300"
                    ></textarea
                    ><select
                        v-model="transition.evidence_id"
                        class="w-full rounded-lg border-slate-300"
                    >
                        <option value="">Aucune preuve sélectionnée</option>
                        <option
                            v-for="item in obligation.evidence"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.title }} · {{ label(item.type) }}
                        </option></select
                    ><button
                        class="rounded-lg bg-indigo-700 px-4 py-2 text-white"
                    >
                        Appliquer
                    </button>
                </form>
                <form
                    class="space-y-3 rounded-xl border bg-white p-4"
                    @submit.prevent="
                        evidence.post(
                            route('compliance.evidence.store', obligation.id),
                            { forceFormData: true },
                        )
                    "
                >
                    <h2 class="font-bold">Preuve versionnée</h2>
                    <select
                        v-model="evidence.type"
                        class="w-full rounded-lg border-slate-300"
                    >
                        <option
                            v-for="type in evidenceTypes"
                            :key="type"
                            :value="type"
                        >
                            {{ label(type) }}
                        </option></select
                    ><input
                        v-model="evidence.title"
                        required
                        placeholder="Titre"
                        class="w-full rounded-lg border-slate-300"
                    /><input
                        required
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx"
                        class="w-full"
                        @change="
                            evidence.file =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    /><button
                        class="rounded-lg bg-indigo-700 px-4 py-2 text-white"
                    >
                        Téléverser
                    </button>
                </form>
            </div>
            <section class="rounded-xl border bg-white p-4">
                <h2 class="font-bold">Historique immuable</h2>
                <ol class="mt-3 space-y-2">
                    <li
                        v-for="item in obligation.transitions"
                        :key="item.id"
                        class="rounded-lg bg-slate-50 p-3 text-sm"
                    >
                        {{ label(item.from_status) }} →
                        {{ label(item.to_status) }} ·
                        {{ item.transitioned_at }}
                        <div v-if="item.reason" class="text-slate-500">
                            {{ item.reason }}
                        </div>
                    </li>
                    <li
                        v-if="!obligation.transitions.length"
                        class="text-sm text-slate-500"
                    >
                        Aucune transition.
                    </li>
                </ol>
            </section>
            <section class="rounded-xl border bg-white p-4">
                <h2 class="font-bold">Index des preuves</h2>
                <div
                    v-for="item in obligation.evidence"
                    :key="item.id"
                    class="mt-3 border-t pt-3"
                >
                    <strong>{{ item.title }}</strong
                    ><span class="ms-2 text-sm text-slate-500">{{
                        label(item.type)
                    }}</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        <a
                            v-for="version in item.versions"
                            :key="version.id"
                            :href="
                                route(
                                    'compliance.evidence.download',
                                    version.id,
                                )
                            "
                            class="text-sm text-indigo-700 underline"
                            >Version {{ version.version }} ·
                            {{ version.name }}</a
                        >
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
