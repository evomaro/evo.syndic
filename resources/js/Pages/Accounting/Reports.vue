<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import { computed, reactive } from "vue";
import { formatMADCents as money } from "@/Support/money";

const props = defineProps<{
    book: any;
    exercise: any;
    exercises: any[];
    periods: any[];
    journals: any[];
    accounts: any[];
    report: any;
    availableReports: string[];
    canExport: boolean;
}>();
const page = usePage<any>();
const isAr = computed(() => page.props.locale === "ar");
const l = (fr: string, ar: string) => (isAr.value ? ar : fr);
const labels: Record<string, [string, string]> = {
    journal: ["Journaux", "اليوميات"],
    "general-ledger": ["Grand livre", "دفتر الأستاذ العام"],
    "account-ledger": ["Grand livre du compte", "دفتر حساب"],
    "trial-balance": ["Balance", "ميزان المراجعة"],
    receivables: ["Créances copropriétaires", "ذمم الملاك"],
    payables: ["Dettes fournisseurs", "ديون الموردين"],
    "budget-actual": ["Budget et réalisé", "الميزانية والمنجز"],
    reconciliation: ["Rapprochement des sources", "مطابقة المصادر"],
    "period-summary": ["Synthèse des périodes", "ملخص الفترات"],
};
const diagnosticLabels: Record<string, [string, string]> = {
    balanced: ["Équilibré", "متوازن"],
    unbalanced_ledger: [
        "Écart d’intégrité du grand livre",
        "فرق في سلامة دفتر الأستاذ",
    ],
    partial_owner_dimensions: [
        "Vue partielle : dimensions propriétaire insuffisantes",
        "عرض جزئي: أبعاد المالك غير مكتملة",
    ],
    accounting_source_reconciliation: [
        "Rapprochement comptable par source",
        "مطابقة محاسبية حسب المصدر",
    ],
    owner_operational_accounting_reconciliation: [
        "Créances opérationnelles rapprochées de la comptabilité",
        "مطابقة الذمم التشغيلية مع المحاسبة",
    ],
    supplier_operational_accounting_reconciliation: [
        "Dettes fournisseurs rapprochées de la comptabilité",
        "مطابقة ديون الموردين مع المحاسبة",
    ],
    owner_dimensions_incomplete: [
        "Le détail par propriétaire reste indisponible sans dimension déterministe.",
        "تفاصيل المالك غير متاحة دون بُعد محدد.",
    ],
    ok: ["Conforme", "سليم"],
    exception: ["Exception à examiner", "استثناء يحتاج للمراجعة"],
    unmapped: ["Non mappé", "غير مربوط"],
    shared_account_ambiguous: ["Compte partagé ambigu", "حساب مشترك ملتبس"],
    mapped: ["Mappé", "مربوط"],
    fund_call: ["Appel de fonds", "نداء أموال"],
    payment: ["Encaissement", "تحصيل"],
    payment_allocation: ["Affectation de crédit", "تخصيص رصيد"],
    supplier_invoice: ["Facture fournisseur", "فاتورة مورد"],
    supplier_settlement: ["Règlement fournisseur", "تسوية مورد"],
    supplier_credit_note: ["Avoir fournisseur", "إشعار دائن للمورد"],
    posted: ["Comptabilisé", "مُرحّل"],
    pending: ["En attente", "قيد الانتظار"],
    failed: ["Échec", "فشل"],
    reversed: ["Contre-passé", "معكوس"],
};
const human = (value: unknown) => {
    if (typeof value !== "string") return value;
    return diagnosticLabels[value] ? l(...diagnosticLabels[value]) : value;
};
const fieldLabels: Record<string, [string, string]> = {
    entry_date: ["Date", "التاريخ"],
    entry_number: ["N° écriture", "رقم القيد"],
    reference: ["Référence", "المرجع"],
    description_fr: ["Description", "الوصف"],
    description_ar: ["Description arabe", "الوصف العربي"],
    source_type: ["Source", "المصدر"],
    status: ["Statut", "الحالة"],
    posted_at: ["Comptabilisé le", "تاريخ الترحيل"],
    journal_code: ["Journal", "اليومية"],
    journal_label_fr: ["Libellé du journal", "تسمية اليومية"],
    journal_label_ar: ["Libellé arabe", "التسمية العربية"],
    debit_minor: ["Débit", "مدين"],
    credit_minor: ["Crédit", "دائن"],
    code: ["Compte", "الحساب"],
    label_fr: ["Libellé", "التسمية"],
    label_ar: ["Libellé arabe", "التسمية العربية"],
    opening_debit_minor: ["Solde initial débiteur", "الرصيد الافتتاحي المدين"],
    opening_credit_minor: [
        "Solde initial créditeur",
        "الرصيد الافتتاحي الدائن",
    ],
    period_debit_minor: ["Mouvements débit", "حركات مدينة"],
    period_credit_minor: ["Mouvements crédit", "حركات دائنة"],
    closing_debit_minor: ["Solde final débiteur", "الرصيد الختامي المدين"],
    closing_credit_minor: ["Solde final créditeur", "الرصيد الختامي الدائن"],
    movement_count: ["Mouvements", "الحركات"],
    running_debit_minor: ["Cumul débiteur", "الرصيد المتراكم المدين"],
    running_credit_minor: ["Cumul créditeur", "الرصيد المتراكم الدائن"],
    balanced: ["Équilibre", "التوازن"],
    source_count: ["Sources", "المصادر"],
    posted_count: ["Sources comptabilisées", "المصادر المُرحّلة"],
    pending_count: ["Sources en attente", "المصادر قيد الانتظار"],
    failed_count: ["Sources en échec", "المصادر الفاشلة"],
    reversed_count: ["Sources contre-passées", "المصادر المعكوسة"],
    missing_count: ["Sources manquantes", "المصادر المفقودة"],
    difference_minor: ["Écart", "الفرق"],
    entry_count: ["Écritures", "القيود"],
    reversal_count: ["Contre-passations", "القيود العكسية"],
    journals_used: ["Journaux utilisés", "اليوميات المستخدمة"],
    last_posting_date: ["Dernière date", "آخر تاريخ"],
    last_entry_number: ["Dernière écriture", "آخر قيد"],
    posting_status: ["Statut de comptabilisation", "حالة الترحيل"],
    source_event: ["Événement source", "حدث المصدر"],
    integrity: ["Intégrité", "السلامة"],
    budget_minor: ["Budget", "الميزانية"],
    actual_minor: ["Réalisé", "المنجز"],
    remaining_minor: ["Reste", "المتبقي"],
    variance_percent: ["Écart (%)", "الفرق (٪)"],
    mapping_status: ["Statut du mapping", "حالة الربط"],
    ambiguous_count: ["Mappings ambigus", "الروابط الملتبسة"],
    unmapped_count: ["Catégories non mappées", "الفئات غير المربوطة"],
    lot_reference: ["Lot", "الوحدة"],
    owner_name: ["Propriétaire facturé", "المالك المفوتر"],
    supplier_name: ["Fournisseur", "المورد"],
    invoice_number: ["Facture", "الفاتورة"],
    due_date: ["Échéance", "الأجل"],
    aging: ["Ancienneté", "التقادم"],
    operational_total_minor: ["Total opérationnel", "المجموع التشغيلي"],
    operational_allocated_minor: ["Affecté opérationnel", "المخصص تشغيليا"],
    operational_paid_minor: ["Payé opérationnel", "المدفوع تشغيليا"],
    operational_credited_minor: ["Avoir opérationnel", "الدائن التشغيلي"],
    operational_outstanding_minor: ["Solde opérationnel", "الرصيد التشغيلي"],
    accounting_recognized_minor: ["Comptabilisé", "المثبت محاسبيا"],
    accounting_allocated_minor: ["Affecté comptablement", "المخصص محاسبيا"],
    accounting_paid_minor: ["Payé comptablement", "المدفوع محاسبيا"],
    accounting_credited_minor: ["Avoir comptabilisé", "الدائن المحاسبي"],
    accounting_outstanding_minor: ["Solde comptable", "الرصيد المحاسبي"],
    reconciliation_status: ["Rapprochement", "المطابقة"],
};
const fieldLabel = (key: string) =>
    fieldLabels[key] ? l(...fieldLabels[key]) : key.replaceAll("_", " ");
const filters = reactive({
    report: props.report.type,
    financial_exercise_id: props.exercise.id,
    accounting_period_id: "",
    date_from: props.report.filters.date_from,
    date_to: props.report.filters.date_to,
    accounting_journal_id: props.report.filters.accounting_journal_id ?? "",
    ledger_account_id: props.report.filters.ledger_account_id ?? "",
    hide_zero: props.report.filters.hide_zero ?? false,
    aging: props.report.filters.aging ?? "",
    as_of: props.report.filters.as_of ?? props.report.filters.date_to,
});
const needsAccount = computed(() => filters.report === "account-ledger");
const apply = () =>
    router.get(route("accounting.reports.index"), filters, {
        preserveState: true,
        replace: true,
    });
const reportUrl = (report: string) =>
    route("accounting.reports.index", { ...filters, report });
const exportUrl = (format: string) =>
    route("accounting.reports.export", {
        format,
        ...filters,
        snapshot_entry_id: props.report.snapshot_entry_id,
    });
const columns = computed(() => {
    const first = props.report.rows?.[0];
    if (!first) return [];
    return Object.keys(first).filter(
        (key) =>
            ![
                "id",
                "source_posting_id",
                "entry_id",
                "account_id",
                "source_id",
                "posted_by",
                "reversal_of_id",
                "reversed_by_id",
                "parent_id",
                "ledger_account_id",
            ].includes(key),
    );
});
const cell = (key: string, value: any) =>
    key.endsWith("_minor")
        ? money(Number(value))
        : typeof value === "boolean"
          ? value
              ? l("Oui", "نعم")
              : l("Non", "لا")
          : human(value);
</script>

<template>
    <Head :title="l('Rapports comptables', 'التقارير المحاسبية')" />
    <AuthenticatedLayout
        :title="l('Consultation comptable', 'الاستشارة المحاسبية')"
        :subtitle="
            l(
                'Rapports en lecture seule fondés exclusivement sur les écritures comptabilisées.',
                'تقارير للقراءة فقط مبنية حصريا على القيود المُرحّلة.',
            )
        "
    >
        <div class="w-full min-w-0 space-y-5">
            <div
                class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"
            >
                {{
                    l(
                        "Rapports de consultation non certifiés. Les brouillons et les écritures échouées sont exclus; les contre-passations restent visibles.",
                        "تقارير استشارية غير مصادق عليها. تُستبعد المسودات والقيود الفاشلة وتظل القيود العكسية ظاهرة.",
                    )
                }}
            </div>

            <nav
                class="flex max-w-full gap-2 overflow-x-auto pb-1"
                :aria-label="l('Types de rapports', 'أنواع التقارير')"
            >
                <Link
                    v-for="type in availableReports"
                    :key="type"
                    :href="reportUrl(type)"
                    replace
                    class="shrink-0 rounded-xl border px-3 py-2 text-sm focus:ring-2 focus:ring-teal-600"
                    :class="
                        filters.report === type
                            ? 'border-teal-700 bg-teal-50 text-teal-900'
                            : 'bg-white'
                    "
                >
                    {{ labels[type] ? l(...labels[type]) : type }}
                </Link>
            </nav>

            <form
                class="grid gap-3 rounded-2xl border bg-white p-4 sm:grid-cols-2 lg:grid-cols-6"
                @submit.prevent="apply"
            >
                <label class="text-sm"
                    >{{ l("Exercice", "السنة المالية") }}
                    <select
                        v-model="filters.financial_exercise_id"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    >
                        <option
                            v-for="item in exercises"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{
                                item.reference ||
                                `${item.starts_on} — ${item.ends_on}`
                            }}
                        </option>
                    </select>
                </label>
                <label class="text-sm"
                    >{{ l("Période", "الفترة") }}
                    <select
                        v-model="filters.accounting_period_id"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    >
                        <option value="">{{ l("Toutes", "الكل") }}</option>
                        <option
                            v-for="item in periods"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.label }}
                        </option>
                    </select>
                </label>
                <label class="text-sm"
                    >{{ l("Du", "من") }}
                    <input
                        v-model="filters.date_from"
                        type="date"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    />
                </label>
                <label class="text-sm"
                    >{{ l("Au", "إلى") }}
                    <input
                        v-model="filters.date_to"
                        type="date"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    />
                </label>
                <label class="text-sm"
                    >{{ l("Journal", "اليومية") }}
                    <select
                        v-model="filters.accounting_journal_id"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    >
                        <option value="">{{ l("Tous", "الكل") }}</option>
                        <option
                            v-for="item in journals"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.code }} —
                            {{
                                isAr
                                    ? item.label_ar || item.label_fr
                                    : item.label_fr
                            }}
                        </option>
                    </select>
                </label>
                <label v-if="needsAccount" class="text-sm"
                    >{{ l("Compte", "الحساب") }}
                    <select
                        v-model="filters.ledger_account_id"
                        required
                        class="mt-1 w-full rounded-xl border-slate-300"
                    >
                        <option value="">
                            {{ l("Sélectionner", "اختيار") }}
                        </option>
                        <option
                            v-for="item in accounts"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.code }} —
                            {{
                                isAr
                                    ? item.label_ar || item.label_fr
                                    : item.label_fr
                            }}
                        </option>
                    </select>
                </label>
                <label
                    v-if="['receivables', 'payables'].includes(filters.report)"
                    class="text-sm"
                    >{{ l("Ancienneté", "التقادم") }}
                    <select
                        v-model="filters.aging"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    >
                        <option value="">{{ l("Toutes", "الكل") }}</option>
                        <option
                            v-for="bucket in [
                                'current',
                                '1-30',
                                '31-60',
                                '61-90',
                                '>90',
                            ]"
                            :key="bucket"
                            :value="bucket"
                        >
                            {{ bucket }}
                        </option>
                    </select>
                </label>
                <label
                    v-if="['receivables', 'payables'].includes(filters.report)"
                    class="text-sm"
                    >{{ l("Âgé au", "التقادم في") }}
                    <input
                        v-model="filters.as_of"
                        type="date"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    />
                </label>
                <div class="flex items-end gap-2 lg:col-span-6">
                    <button class="rounded-xl bg-teal-700 px-4 py-2 text-white">
                        {{ l("Appliquer", "تطبيق") }}
                    </button>
                    <a
                        v-if="canExport"
                        v-for="format in ['csv', 'xlsx', 'pdf', 'json']"
                        :key="format"
                        :href="exportUrl(format)"
                        class="rounded-xl border px-3 py-2 text-sm uppercase"
                    >
                        {{ format }}
                    </a>
                </div>
            </form>

            <section
                v-if="report.integrity === 'unbalanced_ledger'"
                role="alert"
                class="rounded-2xl border border-red-300 bg-red-50 p-4 text-red-900"
            >
                {{ human(report.integrity) }}
            </section>
            <section
                v-if="report.classification || report.notice_code"
                class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm"
            >
                <p v-if="report.classification">
                    {{ human(report.classification) }}
                </p>
                <p v-if="report.notice_code">{{ human(report.notice_code) }}</p>
            </section>

            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="(value, key) in report.totals"
                    :key="key"
                    class="min-w-0 rounded-2xl border bg-white p-4"
                >
                    <p class="break-words text-xs text-slate-500">
                        {{ fieldLabel(String(key)) }}
                    </p>
                    <p class="mt-1 break-words font-bold">
                        {{ cell(String(key), value) }}
                    </p>
                </div>
            </section>

            <section class="min-w-0 rounded-2xl border bg-white p-4">
                <div
                    class="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm"
                >
                    <h2 class="font-bold">
                        {{
                            labels[report.type]
                                ? l(...labels[report.type])
                                : report.type
                        }}
                    </h2>
                    <span class="text-slate-500">
                        {{ report.pagination.total }} {{ l("lignes", "سطر") }} ·
                        snapshot #{{ report.snapshot_entry_id }}
                    </span>
                </div>
                <div
                    v-if="report.rows.length"
                    class="max-w-full overflow-x-auto"
                    tabindex="0"
                    :aria-label="l('Tableau du rapport', 'جدول التقرير')"
                >
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="border-b bg-slate-50">
                                <th
                                    v-for="column in columns"
                                    :key="column"
                                    scope="col"
                                    class="whitespace-nowrap p-2 text-start"
                                >
                                    {{ fieldLabel(column) }}
                                </th>
                                <th scope="col" class="p-2 text-start">
                                    {{ l("Détail", "التفاصيل") }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in report.rows"
                                :key="row.id || row.line_id || index"
                                class="border-b"
                            >
                                <td
                                    v-for="column in columns"
                                    :key="column"
                                    class="max-w-xs p-2"
                                >
                                    {{ cell(column, row[column]) }}
                                </td>
                                <td class="p-2">
                                    <Link
                                        v-if="row.entry_id || row.id"
                                        :href="
                                            route(
                                                'accounting.entries.show',
                                                row.entry_id || row.id,
                                            )
                                        "
                                        class="font-medium text-teal-700 underline"
                                    >
                                        {{ l("Écriture", "القيد") }}
                                    </Link>
                                    <Link
                                        v-else-if="row.account_id"
                                        :href="
                                            route('accounting.reports.index', {
                                                report: 'account-ledger',
                                                financial_exercise_id:
                                                    exercise.id,
                                                ledger_account_id:
                                                    row.account_id,
                                                date_from:
                                                    report.filters.date_from,
                                                date_to: report.filters.date_to,
                                            })
                                        "
                                        class="font-medium text-teal-700 underline"
                                    >
                                        {{ l("Mouvements", "الحركات") }}
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="py-10 text-center text-slate-500">
                    {{
                        l(
                            "Aucune donnée pour ces filtres.",
                            "لا توجد بيانات لهذه المرشحات.",
                        )
                    }}
                </p>
                <div
                    v-if="report.pagination.last_page > 1"
                    class="mt-4 flex justify-end gap-2"
                >
                    <Link
                        v-for="number in report.pagination.last_page"
                        :key="number"
                        :href="
                            route('accounting.reports.index', {
                                ...report.filters,
                                page: number,
                            })
                        "
                        class="rounded-lg border px-3 py-1"
                        :aria-current="
                            number === report.pagination.page
                                ? 'page'
                                : undefined
                        "
                    >
                        {{ number }}
                    </Link>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
