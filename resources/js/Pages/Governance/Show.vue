<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import GovernanceNav from "@/Components/Governance/GovernanceNav.vue";
import InfoTooltip from "@/Components/InfoTooltip.vue";
import InputError from "@/Components/InputError.vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
const props = defineProps<{
    assembly: any;
    rules: Record<string, any>;
    users: any[];
    contacts: any[];
}>();
const ar = usePage<any>().props.locale === "ar";
const a = props.assembly;
const statusLabels: Record<string, { fr: string; ar: string }> = {
    draft: { fr: "Brouillon", ar: "مسودة" },
    preparing: { fr: "En préparation", ar: "قيد الإعداد" },
    convocation_issued: { fr: "Convocation émise", ar: "تم إصدار الدعوة" },
    scheduled: { fr: "Programmée", ar: "مبرمجة" },
    in_session: { fr: "En séance", ar: "الجلسة منعقدة" },
    deliberations_completed: {
        fr: "Délibérations terminées",
        ar: "انتهاء المداولات",
    },
    minutes_prepared: { fr: "Procès-verbal préparé", ar: "تم إعداد المحضر" },
    minutes_signed: { fr: "Procès-verbal signé", ar: "تم توقيع المحضر" },
    decisions_notified: { fr: "Décisions notifiées", ar: "تم تبليغ القرارات" },
    closed: { fr: "Clôturée", ar: "مختتمة" },
    finalized: { fr: "Finalisée", ar: "مكتملة نهائياً" },
    cancelled: { fr: "Annulée", ar: "ملغاة" },
    postponed: { fr: "Reportée", ar: "مؤجلة" },
    adjourned_no_quorum: {
        fr: "Ajournée faute de quorum",
        ar: "مؤجلة لعدم اكتمال النصاب",
    },
    authorized: { fr: "Autorisée", ar: "مصرح بها" },
    voting_open: { fr: "Vote ouvert", ar: "التصويت مفتوح" },
    adopted: { fr: "Adoptée", ar: "معتمدة" },
    rejected: { fr: "Rejetée", ar: "مرفوضة" },
};
const statusLabel = (status: string) =>
    statusLabels[status]?.[ar ? "ar" : "fr"] ?? status;
const legalStatusLabel = (status: string) =>
    status === "reviewed_configuration"
        ? ar
            ? "إعداد تقني مراجع — دون اعتماد قانوني"
            : "Configuration technique revue — sans certification juridique"
        : ar
          ? "الصلاحية القانونية غير متحققة"
          : "Validité juridique non vérifiée";
const post = (name: string, data: any = {}) =>
    router.post(route(name, a.id), data, { preserveScroll: true });
const agenda = useForm({
    display_order: (a.agenda_items?.length ?? 0) + 1,
    title_fr: "",
    title_ar: "",
    explanation_fr: "",
    explanation_ar: "",
    proposed_text_fr: "",
    proposed_text_ar: "",
    category: "general",
    financial_impact_cents: null as number | null,
    resident_visible: true,
    internal_notes: "",
    rule_identifier: "article_20_relative_majority",
    resolution_code: "R" + ((a.resolutions?.length ?? 0) + 1),
    resolution_category: "general",
});
const addAgenda = () =>
    agenda.post(route("governance.agenda.store", a.id), {
        preserveScroll: true,
        onSuccess: () =>
            agenda.reset(
                "title_fr",
                "title_ar",
                "explanation_fr",
                "explanation_ar",
                "proposed_text_fr",
                "proposed_text_ar",
                "internal_notes",
            ),
    });
const document = useForm<{
    category: string;
    title_fr: string;
    title_ar: string;
    audience: string;
    file: File | null;
}>({
    category: "resolution_project",
    title_fr: "",
    title_ar: "",
    audience: "owners",
    file: null,
});
const file = (e: Event) =>
    (document.file = (e.target as HTMLInputElement).files?.[0] ?? null);
const upload = () =>
    document.post(route("governance.documents.store", a.id), {
        forceFormData: true,
        preserveScroll: true,
    });
const officers = useForm({
    chairperson_contact_id: a.chairperson_contact_id ?? "",
    secretary_user_id: a.secretary_user_id ?? "",
});
const minutes = useForm({
    reservations_fr: "",
    reservations_ar: "",
    incidents_fr: "",
    incidents_ar: "",
});
const move = (status: string, reason: string | null = null) =>
    post("governance.transition", {
        status,
        reason,
        idempotency_key: crypto.randomUUID(),
    });
const vote = (resolutionId: number, electorateId: number, choice: string) =>
    router.post(
        route("governance.ballots.store", resolutionId),
        { electorate_id: electorateId, choice },
        { preserveScroll: true },
    );
const execution = (r: any) => {
    const description = window.prompt(
        ar ? "وصف إجراء التنفيذ" : "Description de l’action d’exécution",
    );
    if (!description) return;
    router.post(
        route("governance.execution.store", r.id),
        {
            action_type:
                r.category === "maintenance" ? "maintenance_request" : "other",
            description,
            source_key: crypto.randomUUID(),
        },
        { preserveScroll: true },
    );
};
</script>
<template>
    <AuthenticatedLayout
        :title="a.reference"
        :subtitle="`${a.type} · ${a.meeting_date} · ${a.location}`"
        ><GovernanceNav />
        <div class="mb-5 flex min-w-0 flex-wrap items-center gap-2">
            <span
                class="rounded-full bg-slate-900 px-3 py-1 text-sm font-bold text-white"
                >{{ statusLabel(a.status) }}</span
            ><span
                class="rounded-full bg-teal-100 px-3 py-1 text-sm font-bold"
                >{{
                    a.convocation_number === 2
                        ? ar
                            ? "الدعوة الثانية"
                            : "2e convocation"
                        : ar
                          ? "الدعوة الأولى"
                          : "1re convocation"
                }}</span
            ><button
                v-if="a.status === 'draft'"
                @click="post('governance.freeze')"
                class="rounded-xl bg-teal-700 px-4 py-2 text-white"
            >
                {{
                    ar
                        ? "تجميد جدول الأعمال والهيئة الناخبة"
                        : "Figer agenda et corps électoral"
                }}</button
            ><button
                v-if="a.status === 'preparing'"
                @click="post('governance.issue')"
                class="rounded-xl bg-teal-700 px-4 py-2 text-white"
            >
                {{ ar ? "إصدار الاستدعاء" : "Émettre la convocation" }}</button
            ><button
                v-if="a.status === 'convocation_issued'"
                @click="move('scheduled')"
                class="rounded-xl bg-slate-900 px-4 py-2 text-white"
            >
                {{ ar ? "برمجة" : "Programmer" }}</button
            ><button
                v-if="a.status === 'scheduled'"
                @click="move('in_session')"
                class="rounded-xl bg-slate-900 px-4 py-2 text-white"
            >
                {{ ar ? "فتح الجلسة" : "Ouvrir la séance" }}
            </button>
        </div>
        <div class="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,1fr)_23rem]">
            <main class="min-w-0 space-y-5">
                <section class="min-w-0 rounded-2xl border bg-white p-5">
                    <h2 class="text-lg font-bold">
                        {{
                            ar
                                ? "جدول الأعمال والمقررات"
                                : "Ordre du jour et résolutions"
                        }}
                    </h2>
                    <div
                        v-for="i in a.agenda_items"
                        :key="i.id"
                        class="mt-3 min-w-0 rounded-xl border p-4"
                    >
                        <div class="flex flex-wrap justify-between gap-2">
                            <strong class="break-words"
                                >{{ i.display_order }}.
                                {{
                                    ar ? i.title_ar || i.title_fr : i.title_fr
                                }}</strong
                            ><span class="text-xs text-slate-500"
                                >v{{ i.version }} · {{ i.status }}</span
                            >
                        </div>
                        <p class="mt-2 break-words text-sm">
                            {{
                                ar
                                    ? i.explanation_ar || i.explanation_fr
                                    : i.explanation_fr
                            }}
                        </p>
                        <div
                            v-if="i.resolution"
                            class="mt-3 rounded-lg bg-slate-50 p-3 text-sm"
                        >
                            <b>{{ i.resolution.code }}</b> ·
                            {{ i.resolution.rule_version?.identifier }} ·
                            {{ statusLabel(i.resolution.status) }}
                            <p class="mt-1 font-semibold text-amber-800">
                                {{
                                    legalStatusLabel(
                                        i.resolution.legal_validity_status ||
                                            a.legal_verification_status,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                    <form
                        v-if="['draft', 'preparing'].includes(a.status)"
                        @submit.prevent="addAgenda"
                        class="mt-4 grid min-w-0 gap-3 sm:grid-cols-2"
                    >
                        <input
                            v-model="agenda.title_fr"
                            required
                            placeholder="Titre français"
                            class="min-w-0 rounded-xl border-slate-300"
                        /><input
                            v-model="agenda.title_ar"
                            dir="rtl"
                            placeholder="العنوان بالعربية"
                            class="min-w-0 rounded-xl border-slate-300"
                        /><textarea
                            v-model="agenda.proposed_text_fr"
                            required
                            placeholder="Projet de résolution"
                            class="min-w-0 rounded-xl border-slate-300"
                        ></textarea
                        ><textarea
                            v-model="agenda.proposed_text_ar"
                            dir="rtl"
                            placeholder="مشروع المقرر"
                            class="min-w-0 rounded-xl border-slate-300"
                        ></textarea
                        ><select
                            v-model="agenda.rule_identifier"
                            class="rounded-xl border-slate-300"
                        >
                            <option
                                v-for="(_, key) in rules"
                                :key="key"
                                :value="key"
                            >
                                {{ key }}
                            </option></select
                        ><input
                            v-model="agenda.resolution_code"
                            required
                            class="rounded-xl border-slate-300"
                        /><button
                            class="rounded-xl bg-slate-900 px-4 py-2 text-white sm:col-span-2"
                        >
                            {{ ar ? "إضافة" : "Ajouter" }}</button
                        ><InputError
                            :message="Object.values(agenda.errors)[0]"
                        />
                    </form>
                </section>
                <section class="min-w-0 rounded-2xl border bg-white p-5">
                    <h2 class="text-lg font-bold">
                        {{
                            ar
                                ? "غرفة الوثائق المحمية"
                                : "Salle documentaire protégée"
                        }}
                    </h2>
                    <div class="mt-3 flex min-w-0 flex-wrap gap-2">
                        <div
                            v-for="d in a.documents"
                            :key="d.id"
                            class="max-w-full rounded-lg border p-2 text-sm"
                        >
                            <a
                                v-if="d.published_version"
                                :href="
                                    route(
                                        'governance.documents.download',
                                        d.published_version.id,
                                    )
                                "
                                class="break-all font-semibold"
                                >{{
                                    ar ? d.title_ar || d.title_fr : d.title_fr
                                }}
                                · {{ d.status }}</a
                            ><span v-else class="break-all"
                                >{{ d.title_fr }} · {{ d.status }}</span
                            ><button
                                v-if="
                                    d.status === 'draft' && d.versions?.length
                                "
                                @click="
                                    router.post(
                                        route('governance.documents.publish', {
                                            document: d.id,
                                            version: d.versions.at(-1).id,
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                "
                                class="ms-2 rounded border px-2 py-1"
                            >
                                Publier
                            </button>
                        </div>
                    </div>
                    <form
                        v-if="['draft', 'preparing'].includes(a.status)"
                        @submit.prevent="upload"
                        class="mt-4 grid min-w-0 gap-3 sm:grid-cols-2"
                    >
                        <input
                            v-model="document.title_fr"
                            required
                            placeholder="Titre"
                            class="min-w-0 rounded-xl border-slate-300"
                        /><select
                            v-model="document.audience"
                            class="rounded-xl border-slate-300"
                        >
                            <option value="owners">Copropriétaires</option>
                            <option value="internal">Interne</option></select
                        ><input
                            type="file"
                            required
                            class="max-w-full min-w-0"
                            @change="file"
                        /><button
                            class="rounded-xl bg-slate-900 px-4 py-2 text-white"
                        >
                            {{ ar ? "رفع نسخة" : "Téléverser" }}
                        </button>
                    </form>
                </section>
                <section
                    v-if="a.electorate?.length"
                    class="min-w-0 rounded-2xl border bg-white p-5"
                >
                    <h2 class="text-lg font-bold">
                        {{
                            ar
                                ? "الهيئة الناخبة والحضور"
                                : "Corps électoral et présence"
                        }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ a.electorate.length }}
                        {{ ar ? "حقوق تصويت مجمدة" : "droits de vote figés" }}
                    </p>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="e in a.electorate"
                            :key="e.id"
                            class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-xl border p-3"
                        >
                            <span class="min-w-0 break-words"
                                ><b>{{ e.contact_name_snapshot }}</b
                                ><br /><small
                                    >{{ e.voting_weight_numerator }} /
                                    {{ e.voting_weight_denominator }}</small
                                ></span
                            >
                            <div class="flex flex-wrap gap-1">
                                <button
                                    v-for="s in ['present', 'absent']"
                                    :key="s"
                                    @click="
                                        router.post(
                                            route('governance.attendance', {
                                                a: a.id,
                                                e: e.id,
                                            }),
                                            { status: s },
                                            { preserveScroll: true },
                                        )
                                    "
                                    class="rounded-lg border px-2 py-1 text-xs"
                                >
                                    {{ s }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <button
                        v-if="['scheduled', 'in_session'].includes(a.status)"
                        @click="
                            post('governance.quorum', {
                                rule_id: Object.values(rules)[0].id,
                            })
                        "
                        class="mt-4 rounded-xl bg-teal-700 px-4 py-2 text-white"
                    >
                        {{
                            ar
                                ? "حساب النصاب وتجميده"
                                : "Calculer et figer le quorum"
                        }}
                    </button>
                    <p v-if="a.quorum_snapshots?.length" class="mt-3 font-bold">
                        {{
                            a.quorum_snapshots.at(-1).quorum_met
                                ? ar
                                    ? "اكتمل النصاب"
                                    : "Quorum atteint"
                                : ar
                                  ? "النصاب غير مكتمل"
                                  : "Quorum insuffisant"
                        }}
                    </p>
                </section>
                <section
                    v-if="
                        a.status === 'in_session' ||
                        a.resolutions?.some((r: any) => r.final_result)
                    "
                    class="min-w-0 rounded-2xl border bg-white p-5"
                >
                    <h2 class="text-lg font-bold">
                        {{ ar ? "فضاء التصويت" : "Espace de vote" }}
                    </h2>
                    <article
                        v-for="r in a.resolutions"
                        :key="r.id"
                        class="mt-4 rounded-xl border p-4"
                    >
                        <div class="flex flex-wrap justify-between gap-2">
                            <b>{{ r.code }} · {{ statusLabel(r.status) }}</b
                            ><button
                                v-if="r.status === 'authorized'"
                                @click="
                                    router.post(
                                        route(
                                            'governance.results.finalize',
                                            r.id,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                "
                                class="rounded-lg bg-slate-900 px-3 py-1 text-white"
                            >
                                {{ ar ? "إقفال النتيجة" : "Finaliser" }}
                            </button>
                        </div>
                        <p class="mt-2 text-sm font-semibold text-amber-800">
                            {{
                                legalStatusLabel(
                                    r.legal_validity_status ||
                                        a.legal_verification_status,
                                )
                            }}
                        </p>
                        <div
                            v-if="r.status === 'authorized'"
                            class="mt-3 space-y-2"
                        >
                            <div
                                v-for="e in a.electorate"
                                :key="e.id"
                                class="flex min-w-0 flex-wrap justify-between gap-2 text-sm"
                            >
                                <span class="break-words">{{
                                    e.contact_name_snapshot
                                }}</span>
                                <div class="flex gap-1">
                                    <button
                                        v-for="c in [
                                            'for',
                                            'against',
                                            'abstention',
                                        ]"
                                        :key="c"
                                        @click="vote(r.id, e.id, c)"
                                        class="rounded border px-2 py-1"
                                    >
                                        {{ c }}
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="r.final_result"
                            class="mt-3 rounded-lg bg-slate-50 p-3"
                        >
                            <b
                                :class="
                                    r.final_result.adopted
                                        ? 'text-emerald-700'
                                        : 'text-red-700'
                                "
                                >{{
                                    r.final_result.adopted
                                        ? ar
                                            ? "مقبول"
                                            : "ADOPTÉ"
                                        : ar
                                          ? "مرفوض"
                                          : "REJETÉ"
                                }}</b
                            >
                            · {{ r.final_result.for_weight }} /
                            {{ r.final_result.denominator
                            }}<button
                                v-if="r.final_result.adopted"
                                @click="execution(r)"
                                class="ms-3 rounded-lg border px-3 py-1"
                            >
                                {{ ar ? "إجراء تنفيذ" : "Action d’exécution" }}
                            </button>
                        </div>
                    </article>
                </section>
                <section
                    v-if="
                        [
                            'deliberations_completed',
                            'minutes_prepared',
                            'minutes_signed',
                            'decisions_notified',
                            'closed',
                        ].includes(a.status)
                    "
                    class="rounded-2xl border bg-white p-5"
                >
                    <h2 class="text-lg font-bold">
                        {{
                            ar
                                ? "المحضر والتوقيع"
                                : "Procès-verbal et signature"
                        }}
                    </h2>
                    <form
                        v-if="!a.minutes"
                        @submit.prevent="
                            minutes.post(
                                route('governance.minutes.prepare', a.id),
                                { preserveScroll: true },
                            )
                        "
                        class="mt-3 grid gap-3"
                    >
                        <textarea
                            v-model="minutes.reservations_fr"
                            placeholder="Réserves et incidents"
                            class="rounded-xl border-slate-300"
                        ></textarea
                        ><button
                            class="rounded-xl bg-slate-900 px-4 py-2 text-white"
                        >
                            {{
                                ar
                                    ? "إعداد المحضر"
                                    : "Préparer le procès-verbal"
                            }}
                        </button>
                    </form>
                    <div v-else class="mt-3 flex flex-wrap items-center gap-2">
                        <p class="me-2">{{ a.minutes.status }}</p>
                        <button
                            v-if="a.minutes.status === 'draft'"
                            @click="
                                router.post(
                                    route(
                                        'governance.minutes.review',
                                        a.minutes.versions.at(-1).id,
                                    ),
                                    {},
                                    { preserveScroll: true },
                                )
                            "
                            class="rounded-lg border px-3 py-2"
                        >
                            {{ ar ? "مراجعة" : "Marquer relu" }}</button
                        ><button
                            v-if="
                                a.minutes.status === 'reviewed' &&
                                a.status === 'deliberations_completed'
                            "
                            @click="move('minutes_prepared')"
                            class="rounded-lg border px-3 py-2"
                        >
                            {{
                                ar
                                    ? "اعتماد النسخة المعدة"
                                    : "Valider la préparation"
                            }}</button
                        ><button
                            v-if="
                                a.minutes.status === 'reviewed' &&
                                a.status === 'minutes_prepared'
                            "
                            @click="
                                router.post(
                                    route(
                                        'governance.minutes.sign',
                                        a.minutes.id,
                                    ),
                                    {
                                        chairperson: 'Président confirmé',
                                        secretary: 'Secrétaire confirmé',
                                        method: 'wet_signature_recorded',
                                    },
                                    { preserveScroll: true },
                                )
                            "
                            class="rounded-lg bg-teal-700 px-3 py-2 text-white"
                        >
                            {{ ar ? "توقيع" : "Signer" }}</button
                        ><button
                            v-if="
                                a.minutes.status === 'signed' &&
                                a.status === 'minutes_prepared'
                            "
                            @click="move('minutes_signed')"
                            class="rounded-lg bg-slate-900 px-3 py-2 text-white"
                        >
                            {{
                                ar
                                    ? "تثبيت التوقيع"
                                    : "Enregistrer la signature"
                            }}</button
                        ><button
                            v-if="a.status === 'minutes_signed'"
                            @click="post('governance.decisions.prepare')"
                            class="rounded-lg border px-3 py-2"
                        >
                            {{
                                ar
                                    ? "إعداد التبليغات"
                                    : "Préparer les notifications"
                            }}
                        </button>
                    </div>
                </section>
            </main>
            <aside class="min-w-0 space-y-5">
                <section
                    v-if="['scheduled', 'in_session'].includes(a.status)"
                    class="rounded-2xl border bg-white p-5"
                >
                    <h2 class="font-bold">
                        {{ ar ? "مكتب الجلسة" : "Bureau de séance" }}
                    </h2>
                    <form
                        @submit.prevent="
                            officers.put(route('governance.officers', a.id), {
                                preserveScroll: true,
                            })
                        "
                        class="mt-3 grid gap-3"
                    >
                        <select
                            v-model="officers.chairperson_contact_id"
                            required
                            class="min-w-0 rounded-xl border-slate-300"
                        >
                            <option value="">Président</option>
                            <option
                                v-for="c in contacts"
                                :key="c.id"
                                :value="c.id"
                            >
                                {{
                                    c.company_name ||
                                    `${c.first_name ?? ""} ${c.last_name ?? ""}`
                                }}
                            </option></select
                        ><select
                            v-model="officers.secretary_user_id"
                            required
                            class="min-w-0 rounded-xl border-slate-300"
                        >
                            <option value="">Secrétaire</option>
                            <option
                                v-for="u in users"
                                :key="u.id"
                                :value="u.id"
                            >
                                {{ u.name }}
                            </option></select
                        ><button
                            class="rounded-xl bg-slate-900 px-3 py-2 text-white"
                        >
                            {{ ar ? "حفظ" : "Enregistrer" }}
                        </button>
                    </form>
                </section>
                <section class="rounded-2xl border bg-white p-5">
                    <h2 class="font-bold">
                        {{ ar ? "التسلسل الرسمي" : "Historique formel" }}
                    </h2>
                    <div
                        v-for="t in a.transitions"
                        :key="t.id"
                        class="mt-3 border-s-2 border-teal-300 ps-3 text-sm"
                    >
                        <b>{{ t.from_status }} → {{ t.to_status }}</b>
                        <p class="break-words text-slate-500">
                            {{ t.transitioned_at }}
                        </p>
                    </div>
                    <button
                        v-if="a.status === 'in_session'"
                        @click="move('deliberations_completed')"
                        class="mt-4 rounded-xl bg-slate-900 px-3 py-2 text-white"
                    >
                        {{ ar ? "إنهاء المداولات" : "Clore les délibérations" }}
                    </button>
                </section>
                <section
                    v-if="a.agenda_questions?.length"
                    class="rounded-2xl border bg-white p-5"
                >
                    <h2 class="font-bold">
                        {{
                            ar ? "أسئلة الملاك" : "Questions des propriétaires"
                        }}
                        <InfoTooltip term="assembly_minutes" />
                    </h2>
                    <div
                        v-for="q in a.agenda_questions"
                        :key="q.id"
                        class="mt-3 rounded-xl border p-3 text-sm"
                    >
                        <p class="break-words">{{ q.question_fr }}</p>
                        <button
                            v-if="q.status === 'submitted'"
                            @click="
                                router.post(
                                    route('governance.questions.decide', q.id),
                                    { status: 'accepted' },
                                    { preserveScroll: true },
                                )
                            "
                            class="mt-2 rounded border px-2 py-1"
                        >
                            Accepter
                        </button>
                    </div>
                </section>
            </aside>
        </div></AuthenticatedLayout
    >
</template>
