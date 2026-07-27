<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps([
    "disclaimer",
    "metrics",
    "categories",
    "obligations",
    "authorities",
    "templates",
    "sources",
    "policies",
    "deliveries",
    "filters",
    "members",
    "exercises",
]);
const disclaimer = props.disclaimer as string;
const metrics = props.metrics as Record<string, number>;
const categories = props.categories as string[];
const obligations = props.obligations as any;
const authorities = props.authorities as any[];
const templates = props.templates as any[];
const sources = props.sources as any[];
const policies = props.policies as any[];
const deliveries = props.deliveries as any[];
const members = props.members as any[];
const exercises = props.exercises as any[];
const page = usePage<any>();
const locale = computed(() => page.props.locale ?? "fr");
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const pageTitle = computed(() => text("Conformité", "الامتثال"));
const permissions = computed<string[]>(() => page.props.auth.permissions ?? []);
const can = (permission: string) =>
    permissions.value.includes("*") || permissions.value.includes(permission);
const tabs = computed(() => [
    "calendar",
    ...(can("manage_obligation_templates") ? ["templates", "sources"] : []),
    ...(can("manage_compliance_reminder_policies") ? ["reminders"] : []),
    ...(can("view_compliance_reminder_diagnostics") ? ["diagnostics"] : []),
]);
const canExport = computed(() => can("export_compliance_registers"));
const view = ref(props.filters.view ?? "month");
const stateLabels: Record<string, Record<string, string>> = {
    fr: {
        upcoming: "À venir",
        due_soon: "Échéance proche",
        overdue: "En retard",
        unavailable: "Échéance indisponible",
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
        superseded: "Remplacée",
    },
    ar: {
        upcoming: "قادمة",
        due_soon: "قريبة الاستحقاق",
        overdue: "متأخرة",
        unavailable: "الأجل غير متاح",
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
        superseded: "مستبدلة",
    },
};
const label = (value: string) =>
    stateLabels[locale.value]?.[value] ??
    (locale.value === "ar" ? "حالة موثقة" : "État documenté");
const categoryLabels: Record<string, Record<string, string>> = {
    fr: {
        tax: "Fiscalité",
        social_employment: "Social et emploi",
        corporate_governance: "Gouvernance d’entreprise",
        coownership_governance: "Gouvernance de copropriété",
        accounting: "Comptabilité",
        insurance: "Assurance",
        contract_renewal: "Renouvellement de contrat",
        property_safety: "Immobilier et sécurité",
        supplier_documentation: "Documents fournisseurs",
        administrative_authorization: "Autorisation administrative",
        internal_control: "Contrôle interne",
        other: "Autre",
    },
    ar: {
        tax: "الضرائب",
        social_employment: "الشؤون الاجتماعية والتشغيل",
        corporate_governance: "حكامة الشركات",
        coownership_governance: "حكامة الملكية المشتركة",
        accounting: "المحاسبة",
        insurance: "التأمين",
        contract_renewal: "تجديد العقود",
        property_safety: "العقار والسلامة",
        supplier_documentation: "وثائق الموردين",
        administrative_authorization: "ترخيص إداري",
        internal_control: "المراقبة الداخلية",
        other: "أخرى",
    },
};
const confidenceLabels: Record<string, Record<string, string>> = {
    fr: {
        unverified_draft: "Brouillon non vérifié",
        official_source_located: "Source officielle localisée",
        source_verified: "Source vérifiée",
        professional_interpretation_required:
            "Interprétation professionnelle requise",
        professionally_reviewed: "Revue professionnelle effectuée",
        counsel_reviewed: "Revue du conseil effectuée",
        superseded: "Remplacée",
        withdrawn: "Retirée",
    },
    ar: {
        unverified_draft: "مسودة غير متحقق منها",
        official_source_located: "تم العثور على مصدر رسمي",
        source_verified: "مصدر متحقق منه",
        professional_interpretation_required: "يلزم تفسير مهني",
        professionally_reviewed: "تمت المراجعة المهنية",
        counsel_reviewed: "تمت مراجعة المستشار",
        superseded: "مستبدلة",
        withdrawn: "مسحوبة",
    },
};
const categoryLabel = (value: string) =>
    categoryLabels[locale.value]?.[value] ??
    (locale.value === "ar" ? "فئة تنظيمية" : "Catégorie configurable");
const confidenceLabel = (value: string) =>
    confidenceLabels[locale.value]?.[value] ??
    (locale.value === "ar" ? "تصنيف موثق" : "Classification documentée");
const filters = useForm({ ...props.filters, view: view.value });
filters.authority_id ??= "";
filters.assignee_id ??= "";
filters.fiscal_year_id ??= "";
filters.month ??= new Date().toISOString().slice(0, 7);
const applyFilters = () =>
    filters.get(route("compliance.index"), {
        preserveState: true,
        replace: true,
    });
const authority = useForm({
    code: "",
    jurisdiction: "MA",
    name_fr: "",
    name_ar: "",
});
const source = useForm({
    authority_id: "",
    official_title: "",
    official_url: "",
    document_reference: "",
    published_on: "",
    effective_on: "",
    notes_fr: "",
    notes_ar: "",
});
const template = useForm({
    code: "",
    jurisdiction: "MA",
    category: "other",
    authority_id: "",
    source_id: "",
    title_fr: "",
    title_ar: "",
    applicability_description_fr: "",
    applicability_description_ar: "",
    applicability_rule: { attribute: "", operator: "equals", value: "" },
    schedule_type: "manual",
    deadline_rule: {
        basis: "manual_authoritative_date",
        unit: "calendar_days",
        offset: 0,
    },
    calculation_method_fr: "",
    calculation_method_ar: "",
    required_evidence_fr: "",
    required_evidence_ar: "",
    effective_from: "",
    effective_until: "",
});
const tab = ref("calendar");
const datedObligations = computed(() =>
    [...obligations.data]
        .filter((row: any) => row.current_due_on)
        .sort((a: any, b: any) =>
            String(a.current_due_on).localeCompare(String(b.current_due_on)),
        ),
);
const agendaGroups = computed(() =>
    datedObligations.value.reduce((groups: Record<string, any[]>, row: any) => {
        const date = String(row.current_due_on).slice(0, 10);
        (groups[date] ??= []).push(row);
        return groups;
    }, {}),
);
const calendarDays = computed(() => {
    const [year, month] = String(filters.month).split("-").map(Number);
    const total = new Date(year, month, 0).getDate();
    return Array.from({ length: total }, (_, index) => {
        const date = `${year}-${String(month).padStart(2, "0")}-${String(
            index + 1,
        ).padStart(2, "0")}`;
        return { date, day: index + 1, rows: agendaGroups.value[date] ?? [] };
    });
});
</script>

<template>
    <Head :title="pageTitle" />
    <AuthenticatedLayout :title="pageTitle" :subtitle="disclaimer">
        <div class="space-y-5">
            <div
                role="note"
                class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950"
            >
                {{ disclaimer }}
            </div>
            <div
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                aria-live="polite"
            >
                <div
                    v-for="(value, key) in metrics"
                    :key="key"
                    class="rounded-xl border bg-white p-4 shadow-sm"
                >
                    <p class="text-xs uppercase text-slate-500">
                        {{
                            key === "overdue"
                                ? locale === "ar"
                                    ? "متأخرة"
                                    : "En retard"
                                : key === "upcoming"
                                  ? locale === "ar"
                                      ? "قادمة"
                                      : "À venir"
                                  : key === "unassigned"
                                    ? locale === "ar"
                                        ? "غير مسندة"
                                        : "Non assignées"
                                    : locale === "ar"
                                      ? "غير محددة"
                                      : "Applicabilité indéterminée"
                        }}
                    </p>
                    <p class="mt-1 text-2xl font-bold">{{ value }}</p>
                </div>
            </div>

            <div
                class="flex flex-wrap gap-2"
                role="tablist"
                aria-label="Sections de conformité"
            >
                <button
                    v-for="item in tabs"
                    :key="item"
                    type="button"
                    class="rounded-lg border px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"
                    :class="
                        tab === item ? 'bg-slate-900 text-white' : 'bg-white'
                    "
                    @click="tab = item"
                >
                    {{
                        item === "calendar"
                            ? locale === "ar"
                                ? "التقويم"
                                : "Calendrier"
                            : item === "templates"
                              ? locale === "ar"
                                  ? "النماذج"
                                  : "Modèles"
                              : item === "sources"
                                ? locale === "ar"
                                    ? "المصادر"
                                    : "Sources"
                                : item === "reminders"
                                  ? locale === "ar"
                                      ? "التذكيرات"
                                      : "Rappels"
                                  : locale === "ar"
                                    ? "التشخيص"
                                    : "Diagnostics"
                    }}
                </button>
            </div>

            <section v-if="tab === 'calendar'" class="space-y-4">
                <form
                    class="grid gap-3 rounded-xl border bg-white p-4 md:grid-cols-2 xl:grid-cols-5"
                    @submit.prevent="applyFilters"
                >
                    <select
                        v-model="filters.state"
                        class="rounded-lg border-slate-300"
                    >
                        <option value="">
                            {{ text("Tous les états", "جميع الحالات") }}
                        </option>
                        <option
                            v-for="value in [
                                'upcoming',
                                'in_preparation',
                                'ready_for_review',
                                'submitted',
                                'accepted',
                                'rejected',
                                'correction_required',
                                'completed_internally',
                            ]"
                            :key="value"
                            :value="value"
                        >
                            {{ label(value) }}
                        </option>
                    </select>
                    <select
                        v-model="filters.deadline"
                        class="rounded-lg border-slate-300"
                    >
                        <option value="">
                            {{ text("Toutes les échéances", "جميع الآجال") }}
                        </option>
                        <option
                            v-for="value in [
                                'upcoming',
                                'due_soon',
                                'overdue',
                                'unavailable',
                            ]"
                            :key="value"
                            :value="value"
                        >
                            {{ label(value) }}
                        </option>
                    </select>
                    <select
                        v-model="filters.category"
                        class="rounded-lg border-slate-300"
                    >
                        <option value="">
                            {{ text("Toutes les catégories", "جميع الفئات") }}
                        </option>
                        <option
                            v-for="category in categories"
                            :key="category"
                            :value="category"
                        >
                            {{ categoryLabel(category) }}
                        </option>
                    </select>
                    <select
                        v-model="view"
                        class="rounded-lg border-slate-300"
                        @change="filters.view = view"
                    >
                        <option value="month">
                            {{ text("Mois", "شهر") }}
                        </option>
                        <option value="list">
                            {{ text("Liste", "قائمة") }}
                        </option>
                        <option value="agenda">
                            {{ text("Agenda", "جدول الأعمال") }}
                        </option>
                    </select>
                    <select
                        v-model="filters.authority_id"
                        class="rounded-lg border-slate-300"
                    >
                        <option value="">
                            {{ text("Toutes les autorités", "جميع الجهات") }}
                        </option>
                        <option
                            v-for="item in authorities"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{
                                locale === "ar" ? item.name_ar : item.name_fr
                            }}
                        </option>
                    </select>
                    <select
                        v-model="filters.assignee_id"
                        class="rounded-lg border-slate-300"
                    >
                        <option value="">
                            {{ text("Tous les responsables", "جميع المسؤولين") }}
                        </option>
                        <option
                            v-for="item in members"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }}
                        </option>
                    </select>
                    <select
                        v-model="filters.fiscal_year_id"
                        class="rounded-lg border-slate-300"
                    >
                        <option value="">
                            {{ text("Tous les exercices", "جميع السنوات المالية") }}
                        </option>
                        <option
                            v-for="item in exercises"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.reference || `${item.starts_on}–${item.ends_on}` }}
                        </option>
                    </select>
                    <input
                        v-if="view === 'month'"
                        v-model="filters.month"
                        type="month"
                        class="rounded-lg border-slate-300"
                        :aria-label="text('Mois affiché', 'الشهر المعروض')"
                    />
                    <button
                        class="rounded-lg bg-indigo-700 px-4 py-2 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-700"
                    >
                        {{ text("Filtrer", "تصفية") }}
                    </button>
                </form>
                <div
                    v-if="view === 'month'"
                    class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border bg-slate-200 sm:grid-cols-4 lg:grid-cols-7"
                >
                    <article
                        v-for="day in calendarDays"
                        :key="day.date"
                        class="min-h-28 bg-white p-3"
                    >
                        <div class="text-sm font-bold">{{ day.day }}</div>
                        <Link
                            v-for="row in day.rows"
                            :key="row.id"
                            :href="route('compliance.obligations.show', row.id)"
                            class="mt-2 block rounded bg-indigo-50 p-2 text-xs text-indigo-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"
                        >
                            {{
                                locale === "ar"
                                    ? row.template_version.title_ar
                                    : row.template_version.title_fr
                            }}
                        </Link>
                    </article>
                </div>
                <div
                    v-else-if="view === 'agenda'"
                    class="space-y-3"
                >
                    <article
                        v-for="(rows, date) in agendaGroups"
                        :key="date"
                        class="rounded-xl border bg-white p-4"
                    >
                        <h3 class="font-bold">{{ date }}</h3>
                        <Link
                            v-for="row in rows"
                            :key="row.id"
                            :href="route('compliance.obligations.show', row.id)"
                            class="mt-2 flex items-center justify-between gap-3 rounded-lg border p-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"
                        >
                            <span>{{
                                locale === "ar"
                                    ? row.template_version.title_ar
                                    : row.template_version.title_fr
                            }}</span>
                            <span class="text-xs text-slate-500">{{
                                label(row.operational_status)
                            }}</span>
                        </Link>
                    </article>
                    <div
                        v-if="!Object.keys(agendaGroups).length"
                        class="rounded-xl border bg-white p-10 text-center text-slate-500"
                    >
                        {{ text("Aucune échéance datée.", "لا توجد آجال مؤرخة.") }}
                    </div>
                </div>
                <div
                    v-else
                    class="overflow-x-auto rounded-xl border bg-white"
                >
                    <table class="min-w-[900px] w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="p-3 text-start">
                                    {{ text("Échéance", "الأجل") }}
                                </th>
                                <th class="p-3 text-start">
                                    {{ text("Obligation", "الالتزام") }}
                                </th>
                                <th class="p-3 text-start">
                                    {{ text("Autorité", "الجهة") }}
                                </th>
                                <th class="p-3 text-start">
                                    {{
                                        text(
                                            "État opérationnel",
                                            "الحالة التشغيلية",
                                        )
                                    }}
                                </th>
                                <th class="p-3 text-start">
                                    {{ text("Classement date", "تصنيف الأجل") }}
                                </th>
                                <th class="p-3 text-start">
                                    {{ text("Responsable", "المسؤول") }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in obligations.data"
                                :key="row.id"
                                class="border-t"
                            >
                                <td class="p-3">
                                    {{ row.current_due_on ?? "—" }}
                                </td>
                                <td class="p-3">
                                    <Link
                                        class="font-semibold text-indigo-700 underline-offset-2 hover:underline"
                                        :href="
                                            route(
                                                'compliance.obligations.show',
                                                row.id,
                                            )
                                        "
                                        >{{
                                            locale === "ar"
                                                ? row.template_version.title_ar
                                                : row.template_version.title_fr
                                        }}</Link
                                    >
                                    <div class="text-xs text-slate-500">
                                        {{ row.template.code }} · v{{
                                            row.template_version.version
                                        }}
                                    </div>
                                </td>
                                <td class="p-3">
                                    {{
                                        locale === "ar"
                                            ? row.template.authority.name_ar
                                            : row.template.authority.name_fr
                                    }}
                                </td>
                                <td class="p-3">
                                    {{ label(row.operational_status) }}
                                </td>
                                <td class="p-3">
                                    {{ label(row.deadline_status) }}
                                </td>
                                <td class="p-3">
                                    {{
                                        row.assignments.find(
                                            (a: any) =>
                                                a.assignment_type ===
                                                "responsible",
                                        )?.role ??
                                        text("Non assignée", "غير مسندة")
                                    }}
                                </td>
                            </tr>
                            <tr v-if="!obligations.data.length">
                                <td
                                    colspan="6"
                                    class="p-10 text-center text-slate-500"
                                >
                                    {{
                                        text(
                                            "Aucune obligation dans ce périmètre.",
                                            "لا يوجد أي التزام في هذا النطاق.",
                                        )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="canExport" class="flex flex-wrap gap-2">
                    <a
                        v-for="item in [
                            ['register', 'xlsx'],
                            ['register', 'csv'],
                            ['register', 'pdf'],
                            ['register', 'json'],
                            ['evidence', 'xlsx'],
                            ['submissions', 'xlsx'],
                            ['overdue', 'xlsx'],
                        ]"
                        :key="`${item[0]}-${item[1]}`"
                        class="rounded-lg border bg-white px-3 py-2 text-sm font-medium"
                        :href="
                            route('compliance.export', {
                                format: item[1],
                                family: item[0],
                                state: filters.state,
                                deadline: filters.deadline,
                                category: filters.category,
                                authority_id: filters.authority_id,
                                assignee_id: filters.assignee_id,
                                fiscal_year_id: filters.fiscal_year_id,
                            })
                        "
                        >{{ text("Exporter", "تصدير") }}
                        {{ item[0] }} {{ item[1].toUpperCase() }}</a
                    >
                </div>
            </section>

            <section
                v-if="tab === 'templates'"
                class="grid gap-4 xl:grid-cols-2"
            >
                <form
                    class="space-y-3 rounded-xl border bg-white p-4"
                    @submit.prevent="
                        authority.post(route('compliance.authorities.store'))
                    "
                >
                    <h2 class="font-bold">Nouvelle autorité technique</h2>
                    <input
                        v-model="authority.code"
                        required
                        placeholder="Code stable"
                        class="w-full rounded-lg border-slate-300"
                    /><input
                        v-model="authority.jurisdiction"
                        required
                        placeholder="Juridiction"
                        class="w-full rounded-lg border-slate-300"
                    />
                    <input
                        v-model="authority.name_fr"
                        required
                        placeholder="Nom français"
                        class="w-full rounded-lg border-slate-300"
                    /><input
                        v-model="authority.name_ar"
                        required
                        dir="rtl"
                        placeholder="الاسم بالعربية"
                        class="w-full rounded-lg border-slate-300"
                    />
                    <button
                        class="rounded-lg bg-slate-900 px-4 py-2 text-white"
                    >
                        Créer
                    </button>
                </form>
                <form
                    class="space-y-3 rounded-xl border bg-white p-4"
                    @submit.prevent="
                        template.post(route('compliance.templates.store'))
                    "
                >
                    <h2 class="font-bold">Nouveau modèle non vérifié</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <input
                            v-model="template.code"
                            required
                            placeholder="Code stable"
                            class="rounded-lg border-slate-300"
                        /><select
                            v-model="template.authority_id"
                            required
                            class="rounded-lg border-slate-300"
                        >
                            <option value="">Autorité</option>
                            <option
                                v-for="a in authorities"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.name_fr }}
                            </option>
                        </select>
                    </div>
                    <input
                        v-model="template.title_fr"
                        required
                        placeholder="Libellé français"
                        class="w-full rounded-lg border-slate-300"
                    /><input
                        v-model="template.title_ar"
                        required
                        dir="rtl"
                        placeholder="التسمية العربية"
                        class="w-full rounded-lg border-slate-300"
                    />
                    <textarea
                        v-model="template.applicability_description_fr"
                        required
                        placeholder="Applicabilité explicite"
                        class="w-full rounded-lg border-slate-300"
                    ></textarea
                    ><textarea
                        v-model="template.applicability_description_ar"
                        required
                        dir="rtl"
                        placeholder="وصف الانطباق"
                        class="w-full rounded-lg border-slate-300"
                    ></textarea>
                    <textarea
                        v-model="template.calculation_method_fr"
                        required
                        placeholder="Méthode de calcul"
                        class="w-full rounded-lg border-slate-300"
                    ></textarea
                    ><textarea
                        v-model="template.calculation_method_ar"
                        required
                        dir="rtl"
                        placeholder="طريقة الحساب"
                        class="w-full rounded-lg border-slate-300"
                    ></textarea>
                    <textarea
                        v-model="template.required_evidence_fr"
                        required
                        placeholder="Preuves requises"
                        class="w-full rounded-lg border-slate-300"
                    ></textarea
                    ><textarea
                        v-model="template.required_evidence_ar"
                        required
                        dir="rtl"
                        placeholder="الأدلة المطلوبة"
                        class="w-full rounded-lg border-slate-300"
                    ></textarea>
                    <button
                        class="rounded-lg bg-slate-900 px-4 py-2 text-white"
                    >
                        Créer le brouillon
                    </button>
                </form>
                <div
                    class="xl:col-span-2 overflow-x-auto rounded-xl border bg-white"
                >
                    <table class="min-w-[800px] w-full text-sm">
                        <thead>
                            <tr>
                                <th class="p-3 text-start">Code</th>
                                <th class="p-3 text-start">Catégorie</th>
                                <th class="p-3 text-start">Versions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in templates"
                                :key="row.id"
                                class="border-t"
                            >
                                <td class="p-3">{{ row.code }}</td>
                                <td class="p-3">
                                    {{ categoryLabel(row.category) }}
                                </td>
                                <td class="p-3">
                                    {{
                                        row.versions
                                            .map(
                                                (v: any) =>
                                                    `v${v.version} — ${label(v.status)}`,
                                            )
                                            .join(", ")
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="tab === 'sources'" class="space-y-4">
                <form
                    class="grid gap-3 rounded-xl border bg-white p-4 md:grid-cols-2"
                    @submit.prevent="
                        source.post(route('compliance.sources.store'))
                    "
                >
                    <select
                        v-model="source.authority_id"
                        required
                        class="rounded-lg border-slate-300"
                    >
                        <option value="">Autorité</option>
                        <option
                            v-for="a in authorities"
                            :key="a.id"
                            :value="a.id"
                        >
                            {{ a.name_fr }}
                        </option></select
                    ><input
                        v-model="source.official_title"
                        required
                        placeholder="Titre officiel"
                        class="rounded-lg border-slate-300"
                    />
                    <input
                        v-model="source.official_url"
                        type="url"
                        placeholder="URL officielle"
                        class="rounded-lg border-slate-300"
                    /><input
                        v-model="source.document_reference"
                        placeholder="Référence documentaire"
                        class="rounded-lg border-slate-300"
                    />
                    <input
                        v-model="source.published_on"
                        type="date"
                        class="rounded-lg border-slate-300"
                    /><input
                        v-model="source.effective_on"
                        type="date"
                        class="rounded-lg border-slate-300"
                    />
                    <button
                        class="rounded-lg bg-slate-900 px-4 py-2 text-white"
                    >
                        Enregistrer comme non vérifiée
                    </button>
                </form>
                <div
                    v-for="row in sources"
                    :key="row.id"
                    class="rounded-xl border bg-white p-4"
                >
                    <div class="font-semibold">{{ row.official_title }}</div>
                    <div class="text-sm text-slate-500">
                        {{
                            row.document_reference ||
                            row.official_url ||
                            "Source absente — activation bloquée"
                        }}
                        · {{ confidenceLabel(row.confidence) }}
                    </div>
                </div>
            </section>

            <section v-if="tab === 'reminders'" class="space-y-3">
                <div
                    v-for="policy in policies"
                    :key="policy.id"
                    class="rounded-xl border bg-white p-4"
                >
                    <div class="font-semibold">{{ policy.name }}</div>
                    <div class="text-sm text-slate-500">
                        Application:
                        {{ policy.database_enabled ? "oui" : "non" }} · E-mail:
                        {{ policy.email_enabled ? "optionnel" : "désactivé" }} ·
                        doublons supprimés
                    </div>
                </div>
                <div
                    v-if="!policies.length"
                    class="rounded-xl border bg-white p-8 text-center text-slate-500"
                >
                    Aucune politique active.
                </div>
            </section>
            <section
                v-if="tab === 'diagnostics'"
                class="rounded-xl border bg-white p-4"
            >
                <h2 class="font-bold">Livraisons récentes</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-[700px] w-full text-sm">
                        <thead>
                            <tr>
                                <th class="p-2 text-start">ID</th>
                                <th class="p-2 text-start">Canal</th>
                                <th class="p-2 text-start">État</th>
                                <th class="p-2 text-start">Tentatives</th>
                                <th class="p-2 text-start">Code d’échec</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in deliveries"
                                :key="row.id"
                                class="border-t"
                            >
                                <td class="p-2">{{ row.id }}</td>
                                <td class="p-2">
                                    {{
                                        row.channel === "mail"
                                            ? "E-mail"
                                            : "Application"
                                    }}
                                </td>
                                <td class="p-2">{{ row.status }}</td>
                                <td class="p-2">{{ row.attempts }}</td>
                                <td class="p-2">
                                    {{ row.failure_code ?? "—" }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
