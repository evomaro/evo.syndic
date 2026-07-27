<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps<{
    book: any | null;
    frameworks: any[];
    accounts: any[];
    journals: any[];
    regimeAssessments: any[];
    exercises: any[];
    entries: any | null;
    activity: any[];
    automation: any | null;
    automationReadiness: any | null;
    postingRules: any[];
    sourceMappings: any[];
    sourcePostings: any[];
    openingBatches: any[];
    mappingSources: Record<string, any[]>;
}>();
const { book, frameworks, accounts, journals, exercises, entries, activity } =
    props;
const permissions = computed(
    () => usePage<any>().props.auth?.permissions ?? [],
);
const isAr = computed(() => usePage<any>().props.locale === "ar");
const l = (fr: string, ar: string) => (isAr.value ? ar : fr);
const adoption = useForm({
    accounting_framework_id: props.frameworks?.[0]?.id ?? "",
    selected_regime: "full",
    effective_date: new Date().toISOString().slice(0, 10),
});
const entry = useForm<any>({
    financial_exercise_id: "",
    accounting_period_id: "",
    accounting_journal_id: "",
    entry_date: new Date().toISOString().slice(0, 10),
    reference: "",
    description_fr: "",
    description_ar: "",
    lines: [
        { ledger_account_id: "", label: "", debit_minor: 0, credit_minor: 0 },
        { ledger_account_id: "", label: "", debit_minor: 0, credit_minor: 0 },
    ],
});
const search = ref("");
const filteredAccounts = computed(() =>
    (props.accounts ?? []).filter((a: any) =>
        `${a.code} ${a.label_fr} ${a.label_ar ?? ""}`
            .toLowerCase()
            .includes(search.value.toLowerCase()),
    ),
);
const debit = computed(() =>
    entry.lines.reduce(
        (sum: number, line: any) => sum + Number(line.debit_minor || 0),
        0,
    ),
);
const credit = computed(() =>
    entry.lines.reduce(
        (sum: number, line: any) => sum + Number(line.credit_minor || 0),
        0,
    ),
);
const selectedExercise = computed(() =>
    props.exercises?.find((x: any) => x.id == entry.financial_exercise_id),
);
const minorToMad = (value: number) =>
    new Intl.NumberFormat(undefined, {
        style: "currency",
        currency: "MAD",
    }).format(value / 100);
const addLine = () =>
    entry.lines.push({
        ledger_account_id: "",
        label: "",
        debit_minor: 0,
        credit_minor: 0,
    });
const removeLine = (index: number) => {
    if (entry.lines.length > 2) entry.lines.splice(index, 1);
};
const periodStatus = (value: string) =>
    value === "open"
        ? l("Ouverte", "مفتوحة")
        : value === "locked"
          ? l("Clôturée", "مقفلة")
          : l("À examiner", "يتطلب المراجعة");
const ruleForm = useForm({
    stable_code: "",
    version: "1.0",
    source_domain: "fund_call",
    source_event: "validated",
    accounting_journal_id: props.journals?.[0]?.id ?? "",
    debit_resolution: "receivable_control",
    debit_ledger_account_id: "",
    credit_resolution: "charge_category",
    credit_ledger_account_id: "",
    effective_from: new Date().toISOString().slice(0, 10),
    source_notes: "",
});
const mappingForm = useForm({
    mapping_type: "financial_account",
    source_id: "",
    ledger_account_id: "",
    effective_from: new Date().toISOString().slice(0, 10),
});
const activationForm = useForm({
    effective_from: new Date().toISOString().slice(0, 10),
});
const opening = useForm<any>({
    financial_exercise_id: "",
    accounting_journal_id: "",
    opening_date: new Date().toISOString().slice(0, 10),
    reference: "",
    notes: "",
    supporting_document_reference: "",
    lines: [
        { ledger_account_id: "", label: "", debit_minor: 0, credit_minor: 0 },
        { ledger_account_id: "", label: "", debit_minor: 0, credit_minor: 0 },
    ],
});
const regimeAssessment = useForm<any>({
    financial_exercise_id: "",
    recommended_regime: props.book?.selected_regime ?? "full",
    inputs: { assessment_basis: "explicit_user_input" },
    reason_codes: ["user_declared_regime"],
    rule_version: "manual-1",
});
const frameworkSuccessor = useForm({
    version: "",
    effective_date: "",
});
const subaccount = useForm({
    parent_id: "",
    code: "",
    label_fr: "",
    label_ar: "",
    posting_allowed: true,
    reconciliation_required: false,
    effective_from: new Date().toISOString().slice(0, 10),
});
const journal = useForm({
    code: "",
    label_fr: "",
    label_ar: "",
    type: "general",
    effective_from: new Date().toISOString().slice(0, 10),
});
const activationConfirmation = ref(false);
const mappingOptions = computed(() => {
    if (mappingForm.mapping_type === "financial_account")
        return props.mappingSources?.financial_accounts ?? [];
    if (mappingForm.mapping_type === "expense_category")
        return props.mappingSources?.expense_categories ?? [];
    if (mappingForm.mapping_type === "charge_category")
        return props.mappingSources?.charge_categories ?? [];
    return [
        {
            id: 0,
            code: "CONTROL",
            name: l("Compte de contrôle", "حساب المراقبة"),
        },
    ];
});
const openDebit = computed(() =>
    opening.lines.reduce(
        (sum: number, line: any) => sum + Number(line.debit_minor || 0),
        0,
    ),
);
const openCredit = computed(() =>
    opening.lines.reduce(
        (sum: number, line: any) => sum + Number(line.credit_minor || 0),
        0,
    ),
);
const confirmActivation = () => {
    activationConfirmation.value = false;
    activationForm.post(route("accounting.automation.activate"));
};
</script>

<template>
    <Head :title="l('Comptabilité', 'المحاسبة')" />
    <AuthenticatedLayout
        :title="l('Comptabilité', 'المحاسبة')"
        :subtitle="
            l(
                'Fondation comptable en partie double — aucun flux financier existant n’est comptabilisé automatiquement.',
                'أساس محاسبي بالقيد المزدوج — لا يتم ترحيل أي تدفق مالي قائم تلقائيا.',
            )
        "
    >
        <div class="space-y-6">
            <section
                v-if="!book"
                class="rounded-2xl border border-amber-200 bg-amber-50 p-5"
            >
                <h2 class="text-lg font-bold">
                    {{ l("Configurer la comptabilité", "إعداد المحاسبة") }}
                </h2>
                <p class="mt-1 text-sm text-amber-900">
                    {{
                        l(
                            "Le référentiel officiel est publié, mais son paramétrage et le régime choisi restent soumis à la revue d’un comptable marocain et d’un conseil juridique.",
                            "تم نشر المرجع الرسمي، لكن إعداده والنظام المختار يظلان خاضعين لمراجعة محاسب مغربي ومستشار قانوني.",
                        )
                    }}
                </p>
                <form
                    v-if="permissions.includes('manage_accounting_frameworks')"
                    class="mt-4 grid gap-3 sm:grid-cols-3"
                    @submit.prevent="adoption.post(route('accounting.adopt'))"
                >
                    <label class="text-sm"
                        >{{ l("Référentiel", "المرجع المحاسبي")
                        }}<select
                            v-model="adoption.accounting_framework_id"
                            class="mt-1 w-full rounded-xl border-slate-300"
                        >
                            <option
                                v-for="f in frameworks"
                                :key="f.id"
                                :value="f.id"
                            >
                                {{ f.name_fr }} — {{ f.version }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm"
                        >{{ l("Régime", "النظام")
                        }}<select
                            v-model="adoption.selected_regime"
                            class="mt-1 w-full rounded-xl border-slate-300"
                        >
                            <option value="full">Complet</option>
                            <option value="simplified">Simplifié</option>
                            <option value="minimal">Minimal</option>
                        </select></label
                    >
                    <label class="text-sm"
                        >{{ l("Date d’effet", "تاريخ السريان")
                        }}<input
                            v-model="adoption.effective_date"
                            type="date"
                            class="mt-1 w-full rounded-xl border-slate-300"
                    /></label>
                    <button
                        class="min-h-11 rounded-xl bg-teal-700 px-4 text-white sm:col-span-3"
                    >
                        {{ l("Adopter explicitement", "اعتماد صريح") }}
                    </button>
                </form>
            </section>

            <template v-else>
                <section class="grid gap-4 md:grid-cols-3">
                    <div class="rounded-2xl border bg-white p-5">
                        <p class="text-xs uppercase text-slate-500">
                            {{ l("Référentiel", "المرجع المحاسبي") }}
                        </p>
                        <p class="mt-2 font-bold">
                            {{ book.framework.name_fr }}
                        </p>
                        <p class="text-sm">{{ book.framework.version }}</p>
                    </div>
                    <div class="rounded-2xl border bg-white p-5">
                        <p class="text-xs uppercase text-slate-500">
                            {{ l("Régime sélectionné", "النظام المختار") }}
                        </p>
                        <p class="mt-2 font-bold">{{ book.selected_regime }}</p>
                    </div>
                    <div
                        class="rounded-2xl border border-amber-200 bg-amber-50 p-5"
                    >
                        <p class="text-xs uppercase text-amber-700">
                            {{ l("Revue professionnelle", "المراجعة المهنية") }}
                        </p>
                        <p class="mt-2 font-bold">{{ book.review_status }}</p>
                        <button
                            v-if="
                                book.review_status !== 'approved' &&
                                permissions.includes('review_posting_rules')
                            "
                            class="mt-3 rounded-lg border border-amber-400 px-3 py-2 text-sm"
                            @click="
                                router.post(
                                    route(
                                        'accounting.automation.review-configuration',
                                    ),
                                )
                            "
                        >
                            {{ l("Confirmer la revue", "تأكيد المراجعة") }}
                        </button>
                    </div>
                </section>

                <section
                    v-if="permissions.includes('manage_accounting_frameworks')"
                    class="grid gap-4 rounded-2xl border bg-white p-5 lg:grid-cols-2"
                >
                    <form
                        class="space-y-3"
                        @submit.prevent="
                            regimeAssessment.post(
                                route('accounting.regime-assessments.store'),
                            )
                        "
                    >
                        <div>
                            <h2 class="font-bold">
                                {{
                                    l(
                                        "Évaluation explicite du régime",
                                        "تقييم صريح للنظام",
                                    )
                                }}
                            </h2>
                            <p class="text-sm text-slate-600">
                                {{
                                    l(
                                        "La recommandation reste en attente d’une revue professionnelle.",
                                        "تبقى التوصية في انتظار مراجعة مهنية.",
                                    )
                                }}
                            </p>
                        </div>
                        <select
                            v-model="regimeAssessment.financial_exercise_id"
                            class="w-full rounded-xl border-slate-300"
                        >
                            <option value="">
                                {{ l("Aucun exercice lié", "دون سنة مرتبطة") }}
                            </option>
                            <option
                                v-for="exercise in exercises"
                                :key="exercise.id"
                                :value="exercise.id"
                            >
                                {{ exercise.name || exercise.reference }}
                            </option>
                        </select>
                        <select
                            v-model="regimeAssessment.recommended_regime"
                            class="w-full rounded-xl border-slate-300"
                        >
                            <option value="full">{{ l("Complet", "كامل") }}</option>
                            <option value="simplified">
                                {{ l("Simplifié", "مبسط") }}
                            </option>
                            <option value="minimal">
                                {{ l("Minimal", "الحد الأدنى") }}
                            </option>
                        </select>
                        <input
                            v-model="regimeAssessment.rule_version"
                            required
                            class="w-full rounded-xl border-slate-300"
                            :placeholder="l('Version de règle', 'نسخة القاعدة')"
                        />
                        <button
                            class="min-h-11 w-full rounded-xl bg-teal-700 px-4 text-white"
                        >
                            {{ l("Enregistrer l’évaluation", "حفظ التقييم") }}
                        </button>
                    </form>
                    <form
                        class="space-y-3"
                        @submit.prevent="
                            frameworkSuccessor.post(
                                route(
                                    'accounting.frameworks.successors.store',
                                    book.framework.id,
                                ),
                            )
                        "
                    >
                        <div>
                            <h2 class="font-bold">
                                {{
                                    l(
                                        "Successeur du référentiel",
                                        "خَلَف المرجع المحاسبي",
                                    )
                                }}
                            </h2>
                            <p class="text-sm text-slate-600">
                                {{
                                    l(
                                        "Crée une copie brouillon liée sans modifier la version publiée.",
                                        "ينشئ نسخة مسودة مرتبطة دون تعديل النسخة المنشورة.",
                                    )
                                }}
                            </p>
                        </div>
                        <input
                            v-model="frameworkSuccessor.version"
                            required
                            class="w-full rounded-xl border-slate-300"
                            :placeholder="l('Nouvelle version', 'النسخة الجديدة')"
                        />
                        <input
                            v-model="frameworkSuccessor.effective_date"
                            type="date"
                            class="w-full rounded-xl border-slate-300"
                        />
                        <button
                            class="min-h-11 w-full rounded-xl border border-teal-700 px-4 text-teal-800"
                        >
                            {{ l("Créer le brouillon lié", "إنشاء المسودة المرتبطة") }}
                        </button>
                    </form>
                </section>

                <section
                    id="automation"
                    class="min-w-0 rounded-2xl border border-teal-200 bg-white p-5"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h2 class="text-lg font-bold">
                                {{
                                    l(
                                        "Automatisation comptable",
                                        "الأتمتة المحاسبية",
                                    )
                                }}
                            </h2>
                            <p class="mt-1 text-sm text-slate-600">
                                {{
                                    l(
                                        "Inactive par défaut. Aucune opération historique n’est comptabilisée silencieusement.",
                                        "غير مفعلة افتراضيا. لا يتم ترحيل أي عملية تاريخية بشكل ضمني.",
                                    )
                                }}
                            </p>
                        </div>
                        <span
                            class="rounded-full px-3 py-1 text-sm font-semibold"
                            :class="
                                automation?.status === 'active'
                                    ? 'bg-emerald-100 text-emerald-800'
                                    : 'bg-slate-100 text-slate-700'
                            "
                        >
                            {{
                                automation?.status === "active"
                                    ? l("Active", "مفعلة")
                                    : l("Non activée", "غير مفعلة")
                            }}
                        </span>
                    </div>
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div class="rounded-xl bg-slate-50 p-4">
                            <b>{{
                                l("Contrôle de préparation", "فحص الجاهزية")
                            }}</b>
                            <p
                                class="mt-2 text-sm"
                                :class="
                                    automationReadiness?.ready
                                        ? 'text-emerald-700'
                                        : 'text-amber-800'
                                "
                            >
                                {{
                                    automationReadiness?.ready
                                        ? l(
                                              "Configuration prête pour une activation explicite.",
                                              "الإعداد جاهز للتفعيل الصريح.",
                                          )
                                        : l(
                                              "Configuration incomplète.",
                                              "الإعداد غير مكتمل.",
                                          )
                                }}
                            </p>
                            <ul
                                class="mt-2 list-disc ps-5 text-xs text-slate-600"
                            >
                                <li
                                    v-for="issue in automationReadiness?.issues"
                                    :key="issue"
                                >
                                    {{ issue }}
                                </li>
                            </ul>
                        </div>
                        <form
                            v-if="
                                !automation &&
                                permissions.includes(
                                    'activate_accounting_automation',
                                )
                            "
                            class="rounded-xl border p-4"
                            @submit.prevent="activationConfirmation = true"
                        >
                            <label class="text-sm"
                                >{{
                                    l(
                                        "Date d’effet prospective",
                                        "تاريخ السريان المستقبلي",
                                    )
                                }}<input
                                    v-model="activationForm.effective_from"
                                    type="date"
                                    required
                                    class="mt-1 w-full rounded-xl border-slate-300"
                            /></label>
                            <button
                                :disabled="!automationReadiness?.ready"
                                class="mt-3 min-h-11 w-full rounded-xl bg-teal-700 px-4 text-white disabled:opacity-40"
                            >
                                {{
                                    l("Vérifier et activer", "التحقق والتفعيل")
                                }}
                            </button>
                        </form>
                    </div>

                    <div class="mt-6 grid min-w-0 gap-5 xl:grid-cols-2">
                        <div class="min-w-0">
                            <h3 class="font-bold">
                                {{
                                    l(
                                        "Règles versionnées",
                                        "القواعد ذات الإصدارات",
                                    )
                                }}
                            </h3>
                            <div class="mt-2 space-y-2">
                                <article
                                    v-for="ruleItem in postingRules"
                                    :key="ruleItem.id"
                                    class="rounded-xl border p-3 text-sm"
                                >
                                    <div
                                        class="flex flex-wrap justify-between gap-2"
                                    >
                                        <b
                                            >{{ ruleItem.stable_code }} ·
                                            {{ ruleItem.version }}</b
                                        >
                                        <span
                                            >{{ ruleItem.status }} /
                                            {{
                                                ruleItem.professional_review_status
                                            }}</span
                                        >
                                    </div>
                                    <p>
                                        {{ ruleItem.source_domain }}.{{
                                            ruleItem.source_event
                                        }}
                                    </p>
                                    <div
                                        v-if="ruleItem.status === 'draft'"
                                        class="mt-2 flex flex-wrap gap-2"
                                    >
                                        <button
                                            v-if="
                                                permissions.includes(
                                                    'review_posting_rules',
                                                )
                                            "
                                            class="rounded-lg border px-3 py-1"
                                            @click="
                                                router.post(
                                                    route(
                                                        'accounting.automation.rules.review',
                                                        ruleItem.id,
                                                    ),
                                                )
                                            "
                                        >
                                            {{
                                                l(
                                                    "Marquer revue",
                                                    "اعتماد المراجعة",
                                                )
                                            }}
                                        </button>
                                        <button
                                            v-if="
                                                permissions.includes(
                                                    'review_posting_rules',
                                                ) &&
                                                ruleItem.professional_review_status ===
                                                    'approved'
                                            "
                                            class="rounded-lg bg-slate-900 px-3 py-1 text-white"
                                            @click="
                                                router.post(
                                                    route(
                                                        'accounting.automation.rules.activate',
                                                        ruleItem.id,
                                                    ),
                                                )
                                            "
                                        >
                                            {{
                                                l(
                                                    "Activer la version",
                                                    "تفعيل الإصدار",
                                                )
                                            }}
                                        </button>
                                    </div>
                                </article>
                            </div>
                            <form
                                v-if="
                                    permissions.includes(
                                        'manage_draft_posting_rules',
                                    )
                                "
                                class="mt-3 grid gap-2 rounded-xl bg-slate-50 p-3 sm:grid-cols-2 [&>*]:min-w-0 [&>*]:max-w-full [&>*]:w-full"
                                @submit.prevent="
                                    ruleForm.post(
                                        route(
                                            'accounting.automation.rules.store',
                                        ),
                                    )
                                "
                            >
                                <input
                                    v-model="ruleForm.stable_code"
                                    required
                                    placeholder="Code stable"
                                    class="rounded-lg border-slate-300"
                                />
                                <input
                                    v-model="ruleForm.version"
                                    required
                                    placeholder="Version"
                                    class="rounded-lg border-slate-300"
                                />
                                <select
                                    v-model="ruleForm.source_domain"
                                    class="rounded-lg border-slate-300"
                                >
                                    <option value="fund_call">fund_call</option>
                                    <option value="payment">payment</option>
                                    <option value="payment_allocation">
                                        payment_allocation
                                    </option>
                                    <option value="supplier_invoice">
                                        supplier_invoice
                                    </option>
                                    <option value="supplier_credit_note">
                                        supplier_credit_note
                                    </option>
                                    <option value="supplier_settlement">
                                        supplier_settlement
                                    </option>
                                </select>
                                <input
                                    v-model="ruleForm.source_event"
                                    required
                                    placeholder="Événement"
                                    class="rounded-lg border-slate-300"
                                />
                                <select
                                    v-model="ruleForm.accounting_journal_id"
                                    required
                                    class="rounded-lg border-slate-300"
                                >
                                    <option value="">Journal</option>
                                    <option
                                        v-for="j in journals"
                                        :key="j.id"
                                        :value="j.id"
                                    >
                                        {{ j.code }} — {{ j.label_fr }}
                                    </option>
                                </select>
                                <input
                                    v-model="ruleForm.effective_from"
                                    required
                                    type="date"
                                    class="rounded-lg border-slate-300"
                                />
                                <select
                                    v-model="ruleForm.debit_resolution"
                                    class="rounded-lg border-slate-300"
                                >
                                    <option
                                        v-for="mode in [
                                            'fixed_account',
                                            'financial_account',
                                            'expense_category',
                                            'charge_category',
                                            'payment_split',
                                            'receivable_control',
                                            'advance_control',
                                            'supplier_payable',
                                        ]"
                                        :key="mode"
                                        :value="mode"
                                    >
                                        Débit · {{ mode }}
                                    </option>
                                </select>
                                <select
                                    v-model="ruleForm.credit_resolution"
                                    class="rounded-lg border-slate-300"
                                >
                                    <option
                                        v-for="mode in [
                                            'fixed_account',
                                            'financial_account',
                                            'expense_category',
                                            'charge_category',
                                            'payment_split',
                                            'receivable_control',
                                            'advance_control',
                                            'supplier_payable',
                                        ]"
                                        :key="mode"
                                        :value="mode"
                                    >
                                        Crédit · {{ mode }}
                                    </option>
                                </select>
                                <select
                                    v-model="ruleForm.debit_ledger_account_id"
                                    class="rounded-lg border-slate-300"
                                >
                                    <option value="">
                                        Compte débit fixe (facultatif)
                                    </option>
                                    <option
                                        v-for="a in filteredAccounts.filter(
                                            (x: any) =>
                                                x.posting_allowed && x.active,
                                        )"
                                        :key="a.id"
                                        :value="a.id"
                                    >
                                        {{ a.code }} — {{ a.label_fr }}
                                    </option>
                                </select>
                                <select
                                    v-model="ruleForm.credit_ledger_account_id"
                                    class="rounded-lg border-slate-300"
                                >
                                    <option value="">
                                        Compte crédit fixe (facultatif)
                                    </option>
                                    <option
                                        v-for="a in filteredAccounts.filter(
                                            (x: any) =>
                                                x.posting_allowed && x.active,
                                        )"
                                        :key="a.id"
                                        :value="a.id"
                                    >
                                        {{ a.code }} — {{ a.label_fr }}
                                    </option>
                                </select>
                                <button
                                    class="min-h-11 rounded-lg bg-slate-900 text-white sm:col-span-2"
                                >
                                    {{
                                        l(
                                            "Créer un brouillon inactif",
                                            "إنشاء مسودة غير مفعلة",
                                        )
                                    }}
                                </button>
                            </form>
                        </div>

                        <div class="min-w-0">
                            <h3 class="font-bold">
                                {{
                                    l(
                                        "Correspondances explicites",
                                        "الربط الصريح",
                                    )
                                }}
                            </h3>
                            <div class="mt-2 max-h-64 space-y-2 overflow-auto">
                                <article
                                    v-for="map in sourceMappings"
                                    :key="map.id"
                                    class="rounded-xl border p-3 text-sm"
                                >
                                    <div
                                        class="flex flex-wrap justify-between gap-2"
                                    >
                                        <span
                                            >{{ map.mapping_type }}:{{
                                                map.source_id
                                            }}
                                            →
                                            <b>{{ map.account?.code }}</b></span
                                        >
                                        <span>{{ map.review_status }}</span>
                                    </div>
                                    <button
                                        v-if="
                                            map.review_status !== 'approved' &&
                                            permissions.includes(
                                                'review_posting_rules',
                                            )
                                        "
                                        class="mt-2 rounded-lg border px-3 py-1"
                                        @click="
                                            router.post(
                                                route(
                                                    'accounting.automation.mappings.review',
                                                    map.id,
                                                ),
                                            )
                                        "
                                    >
                                        {{ l("Approuver", "اعتماد") }}
                                    </button>
                                </article>
                            </div>
                            <form
                                v-if="
                                    permissions.includes(
                                        'manage_account_mappings',
                                    )
                                "
                                class="mt-3 grid gap-2 rounded-xl bg-slate-50 p-3 sm:grid-cols-2 [&>*]:min-w-0 [&>*]:max-w-full [&>*]:w-full"
                                @submit.prevent="
                                    mappingForm.post(
                                        route(
                                            'accounting.automation.mappings.store',
                                        ),
                                    )
                                "
                            >
                                <select
                                    v-model="mappingForm.mapping_type"
                                    class="rounded-lg border-slate-300"
                                >
                                    <option value="financial_account">
                                        Compte financier
                                    </option>
                                    <option value="expense_category">
                                        Catégorie de dépense
                                    </option>
                                    <option value="charge_category">
                                        Catégorie d’appel
                                    </option>
                                    <option value="receivable_control">
                                        Créances copropriétaires
                                    </option>
                                    <option value="advance_control">
                                        Avances non affectées
                                    </option>
                                    <option value="supplier_payable">
                                        Dettes fournisseurs
                                    </option>
                                </select>
                                <select
                                    v-model="mappingForm.source_id"
                                    required
                                    class="rounded-lg border-slate-300"
                                >
                                    <option value="">Source</option>
                                    <option
                                        v-for="option in mappingOptions"
                                        :key="option.id"
                                        :value="option.id"
                                    >
                                        {{ option.code }} — {{ option.name }}
                                    </option>
                                </select>
                                <select
                                    v-model="mappingForm.ledger_account_id"
                                    required
                                    class="rounded-lg border-slate-300"
                                >
                                    <option value="">Compte comptable</option>
                                    <option
                                        v-for="a in filteredAccounts.filter(
                                            (x: any) =>
                                                x.posting_allowed && x.active,
                                        )"
                                        :key="a.id"
                                        :value="a.id"
                                    >
                                        {{ a.code }} — {{ a.label_fr }}
                                    </option>
                                </select>
                                <input
                                    v-model="mappingForm.effective_from"
                                    type="date"
                                    required
                                    class="rounded-lg border-slate-300"
                                />
                                <button
                                    class="min-h-11 rounded-lg bg-slate-900 text-white sm:col-span-2"
                                >
                                    {{
                                        l(
                                            "Enregistrer pour revue",
                                            "حفظ للمراجعة",
                                        )
                                    }}
                                </button>
                            </form>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border bg-white p-5">
                    <h2 class="text-lg font-bold">
                        {{ l("Statut des sources", "حالة ترحيل المصادر") }}
                    </h2>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="postingItem in sourcePostings"
                            :key="postingItem.id"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-xl border p-3 text-sm"
                        >
                            <span
                                >{{ postingItem.source_type }} #{{
                                    postingItem.source_id
                                }}
                                · {{ postingItem.source_event }}</span
                            >
                            <span>{{ postingItem.status }}</span>
                            <Link
                                v-if="postingItem.entry"
                                :href="
                                    route(
                                        'accounting.entries.show',
                                        postingItem.entry.id,
                                    )
                                "
                                class="text-teal-700 underline"
                            >
                                {{ postingItem.entry.entry_number }}
                            </Link>
                        </div>
                        <p
                            v-if="!sourcePostings.length"
                            class="text-sm text-slate-500"
                        >
                            {{
                                l(
                                    "Aucune source comptabilisée.",
                                    "لا يوجد مصدر مرحل.",
                                )
                            }}
                        </p>
                    </div>
                </section>

                <section class="rounded-2xl border bg-white p-5">
                    <h2 class="text-lg font-bold">
                        {{
                            l(
                                "Soldes d’ouverture contrôlés",
                                "الأرصدة الافتتاحية المراقبة",
                            )
                        }}
                    </h2>
                    <p class="mt-1 text-sm text-amber-800">
                        {{
                            l(
                                "Aucun solde n’est déduit automatiquement des données historiques.",
                                "لا يتم استنتاج أي رصيد تلقائيا من البيانات التاريخية.",
                            )
                        }}
                    </p>
                    <div class="mt-3 space-y-2">
                        <article
                            v-for="batch in openingBatches"
                            :key="batch.id"
                            class="rounded-xl border p-3 text-sm"
                        >
                            <div class="flex flex-wrap justify-between gap-2">
                                <b>{{ batch.reference }}</b
                                ><span>{{ batch.status }}</span>
                            </div>
                            <div class="mt-2 flex gap-2">
                                <button
                                    v-if="
                                        batch.status === 'draft' &&
                                        permissions.includes(
                                            'review_opening_balances',
                                        )
                                    "
                                    class="rounded-lg border px-3 py-1"
                                    @click="
                                        router.post(
                                            route(
                                                'accounting.opening.review',
                                                batch.id,
                                            ),
                                        )
                                    "
                                >
                                    {{ l("Revoir", "مراجعة") }}
                                </button>
                                <button
                                    v-if="
                                        batch.status === 'reviewed' &&
                                        permissions.includes(
                                            'post_opening_balances',
                                        )
                                    "
                                    class="rounded-lg bg-teal-700 px-3 py-1 text-white"
                                    @click="
                                        router.post(
                                            route(
                                                'accounting.opening.post',
                                                batch.id,
                                            ),
                                        )
                                    "
                                >
                                    {{ l("Comptabiliser", "ترحيل") }}
                                </button>
                            </div>
                        </article>
                    </div>
                    <form
                        v-if="
                            permissions.includes(
                                'manage_opening_balance_drafts',
                            )
                        "
                        class="mt-4 min-w-0 space-y-3 rounded-xl bg-slate-50 p-4"
                        @submit.prevent="
                            opening.post(route('accounting.opening.store'))
                        "
                    >
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <select
                                v-model="opening.financial_exercise_id"
                                required
                                class="rounded-lg border-slate-300"
                            >
                                <option value="">Exercice</option>
                                <option
                                    v-for="x in exercises"
                                    :key="x.id"
                                    :value="x.id"
                                >
                                    {{ x.name }}
                                </option>
                            </select>
                            <select
                                v-model="opening.accounting_journal_id"
                                required
                                class="rounded-lg border-slate-300"
                            >
                                <option value="">Journal d’ouverture</option>
                                <option
                                    v-for="j in journals.filter(
                                        (x: any) => x.type === 'opening',
                                    )"
                                    :key="j.id"
                                    :value="j.id"
                                >
                                    {{ j.code }} — {{ j.label_fr }}
                                </option>
                            </select>
                            <input
                                v-model="opening.opening_date"
                                type="date"
                                required
                                class="rounded-lg border-slate-300"
                            />
                            <input
                                v-model="opening.reference"
                                required
                                :placeholder="l('Référence', 'المرجع')"
                                class="rounded-lg border-slate-300"
                            />
                        </div>
                        <input
                            v-model="opening.supporting_document_reference"
                            required
                            :placeholder="
                                l(
                                    'Référence du justificatif revu',
                                    'مرجع المستند المراجع',
                                )
                            "
                            class="w-full rounded-lg border-slate-300"
                        />
                        <div class="min-w-0 max-w-full overflow-x-auto">
                            <table class="min-w-[680px] w-full text-sm">
                                <tbody>
                                    <tr
                                        v-for="(line, index) in opening.lines"
                                        :key="index"
                                    >
                                        <td class="p-1">
                                            <select
                                                v-model="line.ledger_account_id"
                                                required
                                                class="w-full rounded-lg border-slate-300"
                                            >
                                                <option value="">Compte</option>
                                                <option
                                                    v-for="a in filteredAccounts.filter(
                                                        (x: any) =>
                                                            x.posting_allowed &&
                                                            x.active,
                                                    )"
                                                    :key="a.id"
                                                    :value="a.id"
                                                >
                                                    {{ a.code }} —
                                                    {{ a.label_fr }}
                                                </option>
                                            </select>
                                        </td>
                                        <td class="p-1">
                                            <input
                                                v-model="line.label"
                                                required
                                                :placeholder="
                                                    l('Libellé', 'البيان')
                                                "
                                                class="w-full rounded-lg border-slate-300"
                                            />
                                        </td>
                                        <td class="p-1">
                                            <input
                                                v-model.number="
                                                    line.debit_minor
                                                "
                                                type="number"
                                                min="0"
                                                class="w-full rounded-lg border-slate-300"
                                            />
                                        </td>
                                        <td class="p-1">
                                            <input
                                                v-model.number="
                                                    line.credit_minor
                                                "
                                                type="number"
                                                min="0"
                                                class="w-full rounded-lg border-slate-300"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div
                            class="flex flex-wrap justify-between gap-3 text-sm"
                        >
                            <span
                                >{{ l("Débit", "مدين") }}
                                {{ minorToMad(openDebit) }} ·
                                {{ l("Crédit", "دائن") }}
                                {{ minorToMad(openCredit) }}</span
                            >
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-2"
                                @click="
                                    opening.lines.push({
                                        ledger_account_id: '',
                                        label: '',
                                        debit_minor: 0,
                                        credit_minor: 0,
                                    })
                                "
                            >
                                {{ l("Ajouter une ligne", "إضافة سطر") }}
                            </button>
                            <button
                                :disabled="
                                    openDebit <= 0 || openDebit !== openCredit
                                "
                                class="rounded-lg bg-slate-900 px-4 py-2 text-white disabled:opacity-40"
                            >
                                {{ l("Créer le brouillon", "إنشاء المسودة") }}
                            </button>
                        </div>
                    </form>
                </section>

                <section id="periods" class="rounded-2xl border bg-white p-5">
                    <h2 class="text-lg font-bold">
                        {{
                            l(
                                "Exercices et périodes",
                                "السنوات والفترات المحاسبية",
                            )
                        }}
                    </h2>
                    <div class="mt-4 space-y-4">
                        <article
                            v-for="exercise in exercises"
                            :key="exercise.id"
                            class="rounded-xl border p-4"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-3"
                            >
                                <div>
                                    <b>{{ exercise.name }}</b>
                                    <p class="text-sm text-slate-500">
                                        {{ exercise.starts_on }} —
                                        {{ exercise.ends_on }}
                                    </p>
                                </div>
                                <button
                                    v-if="
                                        !exercise.accounting_periods.length &&
                                        permissions.includes(
                                            'manage_accounting_fiscal_years',
                                        )
                                    "
                                    class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white"
                                    @click="
                                        $inertia.post(
                                            route(
                                                'accounting.exercises.configure',
                                                exercise.id,
                                            ),
                                        )
                                    "
                                >
                                    {{
                                        l(
                                            "Générer les périodes",
                                            "إنشاء الفترات",
                                        )
                                    }}
                                </button>
                            </div>
                            <div
                                class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4"
                            >
                                <div
                                    v-for="period in exercise.accounting_periods"
                                    :key="period.id"
                                    class="rounded-lg bg-slate-50 p-3 text-sm"
                                >
                                    <div class="flex justify-between">
                                        <span>{{ period.label }}</span
                                        ><b>{{
                                            periodStatus(period.status)
                                        }}</b>
                                    </div>
                                    <Link
                                        v-if="
                                            permissions.includes(
                                                'view_closing_readiness',
                                            )
                                        "
                                        :href="
                                            route('accounting.closing.index', {
                                                financial_exercise_id:
                                                    exercise.id,
                                            })
                                        "
                                        class="mt-2 inline-block text-teal-700 underline"
                                    >
                                        {{
                                            l(
                                                "Workflow de clôture",
                                                "مسار الإقفال",
                                            )
                                        }}
                                    </Link>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>

                <section id="accounts" class="rounded-2xl border bg-white p-5">
                    <div class="flex flex-wrap justify-between gap-3">
                        <h2 class="text-lg font-bold">
                            {{ l("Plan comptable", "دليل الحسابات") }}
                        </h2>
                        <input
                            v-model="search"
                            aria-label="Rechercher un compte"
                            :placeholder="
                                l('Code ou libellé', 'الرمز أو التسمية')
                            "
                            class="rounded-xl border-slate-300"
                        />
                    </div>
                    <div class="mt-4 max-h-80 overflow-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-white">
                                <tr>
                                    <th class="p-2 text-start">Code</th>
                                    <th class="p-2 text-start">Libellé</th>
                                    <th class="p-2 text-start">Classe</th>
                                    <th class="p-2 text-start">Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="account in filteredAccounts"
                                    :key="account.id"
                                    class="border-t"
                                >
                                    <td class="p-2 font-mono">
                                        {{ account.code }}
                                    </td>
                                    <td class="p-2">{{ account.label_fr }}</td>
                                    <td class="p-2">
                                        {{ account.account_class }}
                                    </td>
                                    <td class="p-2">
                                        {{
                                            account.active ? "Actif" : "Inactif"
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <form
                        v-if="permissions.includes('manage_chart_of_accounts')"
                        class="mt-4 grid gap-2 border-t pt-4 sm:grid-cols-2 lg:grid-cols-4"
                        @submit.prevent="
                            subaccount.post(route('accounting.subaccounts.store'))
                        "
                    >
                        <select
                            v-model="subaccount.parent_id"
                            required
                            class="rounded-xl border-slate-300"
                        >
                            <option value="">
                                {{ l("Compte parent", "الحساب الأب") }}
                            </option>
                            <option
                                v-for="account in accounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ account.code }} · {{ account.label_fr }}
                            </option>
                        </select>
                        <input
                            v-model="subaccount.code"
                            required
                            class="rounded-xl border-slate-300"
                            :placeholder="l('Code', 'الرمز')"
                        />
                        <input
                            v-model="subaccount.label_fr"
                            required
                            class="rounded-xl border-slate-300"
                            :placeholder="l('Libellé français', 'التسمية الفرنسية')"
                        />
                        <input
                            v-model="subaccount.label_ar"
                            class="rounded-xl border-slate-300"
                            :placeholder="l('Libellé arabe', 'التسمية العربية')"
                        />
                        <button
                            class="min-h-11 rounded-xl bg-teal-700 px-4 text-white sm:col-span-2 lg:col-span-4"
                        >
                            {{ l("Créer le sous-compte", "إنشاء الحساب الفرعي") }}
                        </button>
                    </form>
                </section>

                <section id="journals" class="rounded-2xl border bg-white p-5">
                    <h2 class="text-lg font-bold">
                        {{ l("Journaux", "اليوميات") }}
                    </h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div
                            v-for="journal in journals"
                            :key="journal.id"
                            class="rounded-xl border p-3"
                        >
                            <b class="font-mono">{{ journal.code }}</b>
                            <p class="text-sm">{{ journal.label_fr }}</p>
                        </div>
                    </div>
                    <form
                        v-if="
                            permissions.includes('manage_accounting_journals')
                        "
                        class="mt-4 grid gap-2 border-t pt-4 sm:grid-cols-2 lg:grid-cols-4"
                        @submit.prevent="
                            journal.post(route('accounting.journals.store'))
                        "
                    >
                        <input
                            v-model="journal.code"
                            required
                            class="rounded-xl border-slate-300"
                            :placeholder="l('Code', 'الرمز')"
                        />
                        <input
                            v-model="journal.label_fr"
                            required
                            class="rounded-xl border-slate-300"
                            :placeholder="l('Libellé français', 'التسمية الفرنسية')"
                        />
                        <input
                            v-model="journal.label_ar"
                            class="rounded-xl border-slate-300"
                            :placeholder="l('Libellé arabe', 'التسمية العربية')"
                        />
                        <select
                            v-model="journal.type"
                            class="rounded-xl border-slate-300"
                        >
                            <option value="general">general</option>
                            <option value="bank">bank</option>
                            <option value="cash">cash</option>
                            <option value="collections">collections</option>
                            <option value="purchases">purchases</option>
                            <option value="opening">opening</option>
                            <option value="closing">closing</option>
                        </select>
                        <button
                            class="min-h-11 rounded-xl bg-teal-700 px-4 text-white sm:col-span-2 lg:col-span-4"
                        >
                            {{ l("Créer le journal", "إنشاء اليومية") }}
                        </button>
                    </form>
                </section>

                <section
                    v-if="permissions.includes('create_accounting_entries')"
                    class="rounded-2xl border bg-white p-5"
                >
                    <h2 class="text-lg font-bold">
                        {{ l("Nouvelle écriture manuelle", "قيد يدوي جديد") }}
                    </h2>
                    <form
                        class="mt-4 space-y-4"
                        @submit.prevent="
                            entry.post(route('accounting.entries.store'))
                        "
                    >
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <select
                                v-model="entry.financial_exercise_id"
                                required
                                class="rounded-xl border-slate-300"
                            >
                                <option value="">Exercice</option>
                                <option
                                    v-for="x in exercises.filter(
                                        (e: any) => e.accounting_periods.length,
                                    )"
                                    :key="x.id"
                                    :value="x.id"
                                >
                                    {{ x.name }}
                                </option></select
                            ><select
                                v-model="entry.accounting_period_id"
                                required
                                class="rounded-xl border-slate-300"
                            >
                                <option value="">Période</option>
                                <option
                                    v-for="p in selectedExercise?.accounting_periods.filter(
                                        (x: any) => x.status === 'open',
                                    )"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{ p.label }}
                                </option></select
                            ><select
                                v-model="entry.accounting_journal_id"
                                required
                                class="rounded-xl border-slate-300"
                            >
                                <option value="">Journal</option>
                                <option
                                    v-for="j in journals.filter(
                                        (x: any) => x.active,
                                    )"
                                    :key="j.id"
                                    :value="j.id"
                                >
                                    {{ j.code }} — {{ j.label_fr }}
                                </option></select
                            ><input
                                v-model="entry.entry_date"
                                required
                                type="date"
                                class="rounded-xl border-slate-300"
                            />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <input
                                v-model="entry.reference"
                                placeholder="Référence"
                                class="rounded-xl border-slate-300"
                            /><input
                                v-model="entry.description_fr"
                                required
                                placeholder="Description française"
                                class="rounded-xl border-slate-300"
                            />
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-[720px] w-full text-sm">
                                <thead>
                                    <tr>
                                        <th class="p-2 text-start">Compte</th>
                                        <th class="p-2 text-start">Libellé</th>
                                        <th class="p-2">Débit (centimes)</th>
                                        <th class="p-2">Crédit (centimes)</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(line, i) in entry.lines"
                                        :key="i"
                                    >
                                        <td class="p-1">
                                            <select
                                                v-model="line.ledger_account_id"
                                                required
                                                class="w-full rounded-lg border-slate-300"
                                            >
                                                <option value="">Compte</option>
                                                <option
                                                    v-for="a in accounts.filter(
                                                        (x: any) =>
                                                            x.active &&
                                                            x.posting_allowed,
                                                    )"
                                                    :key="a.id"
                                                    :value="a.id"
                                                >
                                                    {{ a.code }} —
                                                    {{ a.label_fr }}
                                                </option>
                                            </select>
                                        </td>
                                        <td class="p-1">
                                            <input
                                                v-model="line.label"
                                                required
                                                class="w-full rounded-lg border-slate-300"
                                            />
                                        </td>
                                        <td class="p-1">
                                            <input
                                                v-model.number="
                                                    line.debit_minor
                                                "
                                                min="0"
                                                type="number"
                                                class="w-full rounded-lg border-slate-300"
                                            />
                                        </td>
                                        <td class="p-1">
                                            <input
                                                v-model.number="
                                                    line.credit_minor
                                                "
                                                min="0"
                                                type="number"
                                                class="w-full rounded-lg border-slate-300"
                                            />
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                aria-label="Supprimer la ligne"
                                                @click="removeLine(i)"
                                            >
                                                ×
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-2"
                                @click="addLine"
                            >
                                {{ l("Ajouter une ligne", "إضافة سطر") }}
                            </button>
                            <div class="text-sm">
                                <b>Débit {{ minorToMad(debit) }}</b> ·
                                <b>Crédit {{ minorToMad(credit) }}</b>
                            </div>
                            <button
                                :disabled="
                                    debit <= 0 ||
                                    debit !== credit ||
                                    entry.processing
                                "
                                class="min-h-11 rounded-xl bg-teal-700 px-5 text-white disabled:opacity-40"
                            >
                                {{
                                    l("Enregistrer le brouillon", "حفظ المسودة")
                                }}
                            </button>
                        </div>
                    </form>
                </section>

                <section id="entries" class="rounded-2xl border bg-white p-5">
                    <h2 class="text-lg font-bold">
                        {{ l("Écritures", "القيود") }}
                    </h2>
                    <div class="mt-3 space-y-2">
                        <Link
                            v-for="item in entries?.data"
                            :key="item.id"
                            :href="route('accounting.entries.show', item.id)"
                            class="flex flex-wrap justify-between gap-2 rounded-xl border p-3 hover:bg-slate-50"
                            ><span
                                ><b>{{
                                    item.entry_number || `Brouillon #${item.id}`
                                }}</b>
                                — {{ item.description_fr }}</span
                            ><span
                                class="rounded-full bg-slate-100 px-2 py-1 text-xs"
                                >{{ item.status }}</span
                            ></Link
                        >
                    </div>
                </section>
                <section class="rounded-2xl border bg-white p-5">
                    <h2 class="text-lg font-bold">
                        {{ l("Activité comptable", "النشاط المحاسبي") }}
                    </h2>
                    <div class="mt-3 max-h-72 space-y-2 overflow-auto">
                        <div
                            v-for="event in activity"
                            :key="event.id"
                            class="rounded-xl bg-slate-50 p-3 text-sm"
                        >
                            <div class="flex flex-wrap justify-between gap-2">
                                <b>{{ event.action }}</b>
                                <span>{{ event.occurred_at }}</span>
                            </div>
                            <p v-if="event.reason" class="mt-1 text-slate-600">
                                {{ event.reason }}
                            </p>
                        </div>
                    </div>
                </section>
            </template>
        </div>
        <div
            v-if="activationConfirmation"
            role="dialog"
            aria-modal="true"
            aria-labelledby="activation-title"
            class="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4"
            tabindex="-1"
            @keydown.esc="activationConfirmation = false"
        >
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
                <h2 id="activation-title" class="text-lg font-bold">
                    {{
                        l(
                            "Confirmer l’activation prospective",
                            "تأكيد التفعيل المستقبلي",
                        )
                    }}
                </h2>
                <p class="mt-2 text-sm text-slate-600">
                    {{
                        l(
                            "Après activation, tout événement applicable échouera si son écriture ne peut pas être créée atomiquement. Aucun historique ne sera repris.",
                            "بعد التفعيل، سيفشل كل حدث معني إذا تعذر إنشاء قيده بشكل ذري. لن يتم ترحيل السجل التاريخي.",
                        )
                    }}
                </p>
                <div class="mt-5 flex justify-end gap-3">
                    <button
                        class="rounded-xl border px-4 py-2"
                        @click="activationConfirmation = false"
                    >
                        {{ l("Annuler", "إلغاء") }}
                    </button>
                    <button
                        autofocus
                        class="rounded-xl bg-teal-700 px-4 py-2 text-white"
                        @click="confirmActivation"
                    >
                        {{ l("Activer", "تفعيل") }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
