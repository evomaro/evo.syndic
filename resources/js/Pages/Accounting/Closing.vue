<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { formatMADCents as money } from "@/Support/money";

const props = defineProps<{
    book: any;
    exercise: any;
    exercises: any[];
    readiness: any;
    packages: any[];
    configurations: any[];
    journals: any[];
    accounts: any[];
    reopeningDiagnostics: any | null;
}>();

const page = usePage<any>();
const isAr = computed(() => page.props.locale === "ar");
const permissions = computed<string[]>(
    () => page.props.auth?.permissions ?? [],
);
const l = (fr: string, ar: string) => (isAr.value ? ar : fr);
const latest = computed(() => props.packages?.[0] ?? null);
const modal = ref<"approve" | "execute" | "carry" | "reopen" | "period" | null>(
    null,
);
const selectedPeriod = ref<any | null>(null);
const periodForm = useForm({ reason: "" });
const confirmationForm = useForm({ confirmation: "", reason: "" });

const statusLabels: Record<string, [string, string]> = {
    draft: ["Brouillon", "مسودة"],
    blocked: ["Bloqué", "محظور"],
    ready_for_review: ["Prêt pour revue", "جاهز للمراجعة"],
    reviewed: ["Revu", "تمت مراجعته"],
    approved: ["Approuvé", "معتمد"],
    executing: ["Exécution en cours", "قيد التنفيذ"],
    closed: ["Clôturé", "مقفل"],
    carry_forward_pending: [
        "Report à nouveau en attente",
        "الترحيل قيد الانتظار",
    ],
    carry_forward_completed: ["Report à nouveau terminé", "اكتمل الترحيل"],
    reopened: ["Rouvert", "أعيد فتحه"],
    superseded: ["Remplacé", "مستبدل"],
    open: ["Ouverte", "مفتوحة"],
    locked: ["Clôturée", "مقفلة"],
    pass: ["Conforme", "سليم"],
    warning: ["Avertissement", "تحذير"],
    unavailable: ["Indisponible", "غير متاح"],
};
const status = (value: string) =>
    statusLabels[value]
        ? l(...statusLabels[value])
        : l("À examiner", "يتطلب المراجعة");
const resultClass = (result: string) =>
    result === "pass"
        ? "border-emerald-200 bg-emerald-50 text-emerald-900"
        : result === "warning"
          ? "border-amber-200 bg-amber-50 text-amber-900"
          : "border-red-200 bg-red-50 text-red-900";
const changeExercise = (event: Event) =>
    router.get(
        route("accounting.closing.index"),
        { financial_exercise_id: (event.target as HTMLSelectElement).value },
        { replace: true },
    );
const prepare = () =>
    router.post(route("accounting.closing.prepare", props.exercise.id));
const review = (pkg: any) =>
    router.post(route("accounting.closing.review", pkg.id));
const openPeriod = (period: any) => {
    selectedPeriod.value = period;
    periodForm.reset();
    modal.value = "period";
};
const closePeriod = () => {
    if (!latest.value || !selectedPeriod.value) return;
    periodForm.post(
        route("accounting.closing.periods.close", {
            package: latest.value.id,
            period: selectedPeriod.value.id,
        }),
        { onSuccess: () => (modal.value = null) },
    );
};
const openConfirmation = (kind: "approve" | "execute" | "carry" | "reopen") => {
    confirmationForm.reset();
    modal.value = kind;
};
const confirmAction = () => {
    if (!latest.value) return;
    const routes = {
        approve: route("accounting.closing.approve", latest.value.id),
        execute: route("accounting.closing.execute", latest.value.id),
        carry: route("accounting.closing.carry-forward", latest.value.id),
        reopen: route("accounting.closing.reopen", latest.value.id),
    };
    if (modal.value === "approve") {
        router.post(
            routes.approve,
            {},
            { onSuccess: () => (modal.value = null) },
        );
        return;
    }
    if (
        modal.value === "execute" ||
        modal.value === "carry" ||
        modal.value === "reopen"
    ) {
        confirmationForm.post(routes[modal.value], {
            onSuccess: () => (modal.value = null),
        });
    }
};
</script>

<template>
    <Head
        :title="l('Clôture comptable contrôlée', 'الإقفال المحاسبي المراقب')"
    />
    <AuthenticatedLayout
        :title="l('Clôture comptable contrôlée', 'الإقفال المحاسبي المراقب')"
        :subtitle="
            l(
                'Préparation, revue et preuves séparées. Aucune décision professionnelle n’est déduite automatiquement.',
                'فصل التحضير والمراجعة والأدلة. لا يُستنتج أي قرار مهني تلقائيا.',
            )
        "
    >
        <div class="w-full min-w-0 space-y-5">
            <section
                role="note"
                class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"
            >
                {{
                    l(
                        "Infrastructure non certifiée. L’exécution reste bloquée tant que les classifications, le compte de résultat et les décisions professionnelles ne sont pas explicitement approuvés.",
                        "بنية غير مصادق عليها. يبقى التنفيذ محظورا حتى اعتماد التصنيفات وحساب النتيجة والقرارات المهنية بشكل صريح.",
                    )
                }}
            </section>

            <section class="rounded-2xl border bg-white p-4">
                <label class="block max-w-md text-sm">
                    {{ l("Exercice fiscal", "السنة المالية") }}
                    <select
                        :value="exercise.id"
                        class="mt-1 w-full rounded-xl border-slate-300"
                        @change="changeExercise"
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
            </section>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border bg-white p-4">
                    <p class="text-xs text-slate-500">
                        {{ l("Préparation technique", "التحضير التقني") }}
                    </p>
                    <p class="mt-1 font-bold">
                        {{
                            readiness.technical_ready
                                ? l("Prête", "جاهزة")
                                : l("Bloquée", "محظورة")
                        }}
                    </p>
                </article>
                <article class="rounded-2xl border bg-white p-4">
                    <p class="text-xs text-slate-500">
                        {{ l("Approbation", "الاعتماد") }}
                    </p>
                    <p class="mt-1 font-bold">
                        {{
                            readiness.approval_ready
                                ? l("Prête", "جاهزة")
                                : l("Bloquée", "محظورة")
                        }}
                    </p>
                </article>
                <article class="rounded-2xl border bg-white p-4">
                    <p class="text-xs text-slate-500">
                        {{ l("Exécution", "التنفيذ") }}
                    </p>
                    <p class="mt-1 font-bold">
                        {{
                            readiness.execution_ready
                                ? l("Prête", "جاهزة")
                                : l("Bloquée", "محظورة")
                        }}
                    </p>
                </article>
                <article class="rounded-2xl border bg-white p-4">
                    <p class="text-xs text-slate-500">Snapshot</p>
                    <p class="mt-1 font-bold">
                        #{{ readiness.snapshot.snapshot_entry_id }}
                    </p>
                    <p class="text-xs text-slate-500">
                        {{ readiness.evaluated_at }}
                    </p>
                </article>
            </section>

            <section class="rounded-2xl border bg-white p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-bold">
                            {{
                                l("Contrôles de préparation", "فحوصات الجاهزية")
                            }}
                        </h2>
                        <p class="text-sm text-slate-500">
                            {{
                                l(
                                    "Les résultats indisponibles ne sont jamais considérés conformes.",
                                    "لا تعتبر النتائج غير المتاحة مطابقة أبدا.",
                                )
                            }}
                        </p>
                    </div>
                    <button
                        v-if="
                            permissions.includes('prepare_fiscal_year_closing')
                        "
                        class="rounded-xl bg-teal-700 px-4 py-2 text-white focus:ring-2 focus:ring-teal-500"
                        @click="prepare"
                    >
                        {{ l("Préparer un instantané", "تحضير لقطة") }}
                    </button>
                </div>
                <div class="mt-4 max-w-full overflow-x-auto" tabindex="0">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="border-b bg-slate-50 text-start">
                                <th class="p-2 text-start">
                                    {{ l("Contrôle", "الفحص") }}
                                </th>
                                <th class="p-2 text-start">
                                    {{ l("Résultat", "النتيجة") }}
                                </th>
                                <th class="p-2 text-start">
                                    {{ l("Preuve", "الدليل") }}
                                </th>
                                <th class="p-2 text-start">
                                    {{ l("Bloque", "يحظر") }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="check in readiness.checks"
                                :key="check.code"
                                class="border-b"
                            >
                                <td class="p-2 font-medium">
                                    {{ isAr ? check.label_ar : check.label_fr }}
                                </td>
                                <td class="p-2">
                                    <span
                                        class="inline-flex rounded-full border px-2 py-1 text-xs"
                                        :class="resultClass(check.result)"
                                    >
                                        {{ status(check.result) }}
                                    </span>
                                </td>
                                <td class="max-w-sm break-words p-2">
                                    {{
                                        typeof check.evidence === "object"
                                            ? JSON.stringify(check.evidence)
                                            : (check.evidence ?? "—")
                                    }}
                                </td>
                                <td class="p-2">
                                    {{
                                        [
                                            check.blocks_preparation
                                                ? l("préparation", "التحضير")
                                                : null,
                                            check.blocks_approval
                                                ? l("approbation", "الاعتماد")
                                                : null,
                                            check.blocks_execution
                                                ? l("exécution", "التنفيذ")
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(" · ")
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-2xl border bg-white p-4">
                <h2 class="font-bold">{{ l("Périodes", "الفترات") }}</h2>
                <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="period in exercise.accounting_periods"
                        :key="period.id"
                        class="rounded-xl border p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <b
                                    >{{ period.sequence }} ·
                                    {{ period.label }}</b
                                >
                                <p class="text-xs text-slate-500">
                                    {{ period.starts_on }} —
                                    {{ period.ends_on }}
                                </p>
                            </div>
                            <span class="rounded-full border px-2 py-1 text-xs">
                                {{ status(period.status) }}
                            </span>
                        </div>
                        <button
                            v-if="
                                period.status === 'open' &&
                                latest &&
                                permissions.includes('close_accounting_period')
                            "
                            class="mt-3 rounded-lg border px-3 py-1 text-sm focus:ring-2 focus:ring-teal-500"
                            @click="openPeriod(period)"
                        >
                            {{ l("Clôturer avec preuve", "إقفال مع دليل") }}
                        </button>
                    </article>
                </div>
            </section>

            <section class="rounded-2xl border bg-white p-4">
                <h2 class="font-bold">
                    {{ l("Dossiers de clôture", "حزم الإقفال") }}
                </h2>
                <div v-if="packages.length" class="mt-3 space-y-3">
                    <article
                        v-for="pkg in packages"
                        :key="pkg.id"
                        class="rounded-xl border p-4"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div>
                                <b
                                    >#{{ pkg.id }} ·
                                    {{ l("génération", "الجيل") }}
                                    {{ pkg.generation }}</b
                                >
                                <p class="text-sm">{{ status(pkg.state) }}</p>
                                <p class="text-xs text-slate-500">
                                    Snapshot #{{ pkg.snapshot_entry_id }} ·
                                    {{ pkg.prepared_at }}
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a
                                    v-if="
                                        permissions.includes(
                                            'export_closing_evidence',
                                        )
                                    "
                                    v-for="format in [
                                        'pdf',
                                        'xlsx',
                                        'csv',
                                        'json',
                                    ]"
                                    :key="format"
                                    :href="
                                        route('accounting.closing.export', {
                                            package: pkg.id,
                                            format,
                                        })
                                    "
                                    class="rounded-lg border px-3 py-1 text-xs uppercase"
                                >
                                    {{ format }}
                                </a>
                            </div>
                        </div>
                    </article>
                </div>
                <p v-else class="mt-3 text-sm text-slate-500">
                    {{ l("Aucun dossier préparé.", "لم يتم تحضير أي حزمة.") }}
                </p>

                <div v-if="latest" class="mt-4 flex flex-wrap gap-2">
                    <button
                        v-if="
                            latest.state === 'ready_for_review' &&
                            permissions.includes('review_fiscal_year_closing')
                        "
                        class="rounded-xl border px-4 py-2"
                        @click="review(latest)"
                    >
                        {{ l("Enregistrer la revue", "تسجيل المراجعة") }}
                    </button>
                    <button
                        v-if="
                            latest.state === 'reviewed' &&
                            permissions.includes('approve_fiscal_year_closing')
                        "
                        class="rounded-xl border px-4 py-2"
                        @click="openConfirmation('approve')"
                    >
                        {{ l("Approuver", "اعتماد") }}
                    </button>
                    <button
                        v-if="
                            latest.state === 'approved' &&
                            permissions.includes('execute_fiscal_year_closing')
                        "
                        :disabled="!readiness.execution_ready"
                        class="rounded-xl bg-slate-950 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-40"
                        @click="openConfirmation('execute')"
                    >
                        {{ l("Exécuter la clôture", "تنفيذ الإقفال") }}
                    </button>
                    <button
                        v-if="
                            latest.state === 'closed' &&
                            permissions.includes('execute_carry_forward')
                        "
                        class="rounded-xl bg-teal-700 px-4 py-2 text-white"
                        @click="openConfirmation('carry')"
                    >
                        {{ l("Exécuter le report à nouveau", "تنفيذ الترحيل") }}
                    </button>
                </div>
            </section>

            <section class="rounded-2xl border border-sky-200 bg-sky-50 p-4">
                <h2 class="font-bold">
                    {{ l("Diagnostic de réouverture", "تشخيص إعادة الفتح") }}
                </h2>
                <p class="mt-1 text-sm">
                    {{
                        l(
                            "La réouverture n’est proposée que lorsque la clôture peut être contre-passée atomiquement sans dépendance ultérieure.",
                            "لا تُقترح إعادة الفتح إلا عندما يمكن عكس الإقفال بشكل ذري دون تبعيات لاحقة.",
                        )
                    }}
                </p>
                <ul
                    v-if="reopeningDiagnostics"
                    class="mt-2 list-disc ps-5 text-sm"
                >
                    <li
                        v-for="issue in reopeningDiagnostics.issues"
                        :key="issue"
                    >
                        {{
                            issue === "active_carry_forward_dependency"
                                ? l(
                                      "Un report à nouveau actif existe.",
                                      "يوجد ترحيل نشط.",
                                  )
                                : issue === "later_year_postings_exist"
                                  ? l(
                                        "Des écritures d’un exercice ultérieur existent.",
                                        "توجد قيود لسنة مالية لاحقة.",
                                    )
                                  : l(
                                        "Le mécanisme sûr de contre-passation des dépendances reste indisponible.",
                                        "آلية العكس الآمن للتبعيات غير متاحة.",
                                    )
                        }}
                    </li>
                </ul>
                <button
                    v-if="
                        latest?.state === 'closed' &&
                        reopeningDiagnostics?.executable &&
                        permissions.includes('reopen_accounting_periods')
                    "
                    class="mt-3 rounded-xl bg-sky-800 px-4 py-2 text-white"
                    @click="openConfirmation('reopen')"
                >
                    {{
                        l(
                            "Réouvrir avec contre-passation",
                            "إعادة الفتح بقيد عكسي",
                        )
                    }}
                </button>
            </section>

            <Teleport to="body">
                <div
                    v-if="modal"
                    class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-4"
                    role="dialog"
                    aria-modal="true"
                >
                    <div
                        class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl"
                    >
                        <h2 class="text-lg font-bold">
                            {{
                                modal === "period"
                                    ? l(
                                          "Confirmer la clôture de période",
                                          "تأكيد إقفال الفترة",
                                      )
                                    : modal === "approve"
                                      ? l(
                                            "Confirmer l’approbation",
                                            "تأكيد الاعتماد",
                                        )
                                      : modal === "execute"
                                        ? l(
                                              "Confirmer l’exécution",
                                              "تأكيد التنفيذ",
                                          )
                                        : modal === "reopen"
                                          ? l(
                                                "Confirmer la réouverture contrôlée",
                                                "تأكيد إعادة الفتح المراقبة",
                                            )
                                          : l(
                                                "Confirmer le report à nouveau",
                                                "تأكيد الترحيل",
                                            )
                            }}
                        </h2>
                        <template v-if="modal === 'period'">
                            <label class="mt-4 block text-sm">
                                {{ l("Motif documenté", "سبب موثق") }}
                                <textarea
                                    v-model="periodForm.reason"
                                    required
                                    class="mt-1 w-full rounded-xl border-slate-300"
                                />
                            </label>
                        </template>
                        <template v-else-if="modal !== 'approve'">
                            <p class="mt-2 text-sm text-slate-600">
                                {{
                                    l(
                                        `Saisissez ${exercise.reference} pour confirmer.`,
                                        `اكتب ${exercise.reference} للتأكيد.`,
                                    )
                                }}
                            </p>
                            <input
                                v-model="confirmationForm.confirmation"
                                class="mt-3 w-full rounded-xl border-slate-300"
                                :aria-label="
                                    l(
                                        'Référence de confirmation',
                                        'مرجع التأكيد',
                                    )
                                "
                            />
                            <label
                                v-if="modal === 'reopen'"
                                class="mt-3 block text-sm"
                            >
                                {{ l("Motif documenté", "سبب موثق") }}
                                <textarea
                                    v-model="confirmationForm.reason"
                                    required
                                    class="mt-1 w-full rounded-xl border-slate-300"
                                />
                            </label>
                        </template>
                        <p v-else class="mt-2 text-sm text-slate-600">
                            {{
                                l(
                                    "L’approbation est distincte de la préparation et de l’exécution.",
                                    "الاعتماد منفصل عن التحضير والتنفيذ.",
                                )
                            }}
                        </p>
                        <div class="mt-5 flex justify-end gap-2">
                            <button
                                class="rounded-xl border px-4 py-2"
                                @click="modal = null"
                            >
                                {{ l("Annuler", "إلغاء") }}
                            </button>
                            <button
                                class="rounded-xl bg-teal-700 px-4 py-2 text-white"
                                :disabled="
                                    modal === 'period'
                                        ? !periodForm.reason
                                        : modal !== 'approve' &&
                                          (confirmationForm.confirmation !==
                                              exercise.reference ||
                                              (modal === 'reopen' &&
                                                  !confirmationForm.reason))
                                "
                                @click="
                                    modal === 'period'
                                        ? closePeriod()
                                        : confirmAction()
                                "
                            >
                                {{ l("Confirmer", "تأكيد") }}
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </AuthenticatedLayout>
</template>
