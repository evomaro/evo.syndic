<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useForm, usePage } from "@inertiajs/vue3";
defineProps<{
    assembly: any;
    electorate: any;
    delivery: any;
    proxyOnly: boolean;
}>();
const ar = usePage<any>().props.locale === "ar";
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
};
const statusLabel = (status: string) =>
    statusLabels[status]?.[ar ? "ar" : "fr"] ?? status;
const q = useForm({ question_fr: "", question_ar: "" });
const proxy = useForm<{ representative_email: string; file: File | null }>({
    representative_email: "",
    file: null,
});
const proxyFile = (event: Event) =>
    (proxy.file = (event.target as HTMLInputElement).files?.[0] ?? null);
</script>
<template>
    <AuthenticatedLayout
        :title="assembly.reference"
        :subtitle="
            ar
                ? 'معلومات مصادق عليها من الخادم'
                : 'Informations autorisées par le serveur'
        "
        ><div class="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <main class="min-w-0 space-y-5">
                <section class="min-w-0 rounded-2xl border bg-white p-5">
                    <span
                        class="rounded-full bg-teal-100 px-3 py-1 text-sm font-bold"
                        >{{ statusLabel(assembly.status) }}</span
                    >
                    <h2 class="mt-4 text-xl font-black break-words">
                        {{ assembly.meeting_date }} · {{ assembly.starts_at }}
                    </h2>
                    <p class="mt-2 break-words text-slate-600">
                        {{ assembly.location }}
                    </p>
                    <div class="mt-4 flex max-w-full flex-wrap gap-2">
                        <a
                            v-for="c in assembly.convocations"
                            :key="c.id"
                            :href="
                                route(
                                    'owner-governance.convocations.download',
                                    c.id,
                                )
                            "
                            class="rounded-xl bg-slate-900 px-4 py-2 text-white"
                            >{{
                                ar
                                    ? "تحميل الاستدعاء"
                                    : "Télécharger la convocation"
                            }}</a
                        ><a
                            v-if="assembly.minutes?.signed_version"
                            :href="
                                route(
                                    'owner-governance.minutes.download',
                                    assembly.minutes.signed_version.id,
                                )
                            "
                            class="rounded-xl border px-4 py-2"
                            >{{
                                ar
                                    ? "تحميل المحضر الموقع"
                                    : "Télécharger le PV signé"
                            }}</a
                        >
                    </div>
                </section>
                <section class="min-w-0 rounded-2xl border bg-white p-5">
                    <h2 class="font-bold">
                        {{ ar ? "جدول الأعمال" : "Ordre du jour" }}
                    </h2>
                    <article
                        v-for="i in assembly.agenda_items"
                        :key="i.id"
                        class="mt-3 rounded-xl border p-4"
                    >
                        <b class="break-words"
                            >{{ i.display_order }}.
                            {{ ar ? i.title_ar || i.title_fr : i.title_fr }}</b
                        >
                        <p class="mt-2 break-words text-sm">
                            {{
                                ar
                                    ? i.explanation_ar || i.explanation_fr
                                    : i.explanation_fr
                            }}
                        </p>
                    </article>
                </section>
                <section class="min-w-0 rounded-2xl border bg-white p-5">
                    <h2 class="font-bold">
                        {{ ar ? "غرفة الوثائق" : "Salle documentaire" }}
                    </h2>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a
                            v-for="d in assembly.documents"
                            :key="d.id"
                            :href="
                                route(
                                    'governance.documents.download',
                                    d.published_version.id,
                                )
                            "
                            class="max-w-full break-all rounded-xl border px-3 py-2 text-sm"
                            >{{ ar ? d.title_ar || d.title_fr : d.title_fr }}</a
                        >
                        <p
                            v-if="!assembly.documents.length"
                            class="text-sm text-slate-500"
                        >
                            {{
                                ar
                                    ? "لا توجد وثائق منشورة"
                                    : "Aucun document publié"
                            }}
                        </p>
                    </div>
                </section>
                <section
                    v-if="!proxyOnly"
                    class="rounded-2xl border bg-white p-5"
                >
                    <h2 class="font-bold">
                        {{ ar ? "التوكيل الكتابي" : "Mandat écrit" }}
                    </h2>
                    <a
                        :href="
                            route('owner-governance.proxy-form', assembly.id)
                        "
                        class="mt-2 inline-block rounded-lg border px-3 py-2 text-sm"
                        >{{
                            ar ? "تحميل النموذج" : "Télécharger le formulaire"
                        }}</a
                    >
                    <form
                        class="mt-3 grid min-w-0 gap-2"
                        @submit.prevent="
                            proxy.post(
                                route(
                                    'owner-governance.proxies.store',
                                    assembly.id,
                                ),
                                { forceFormData: true, preserveScroll: true },
                            )
                        "
                    >
                        <input
                            v-model="proxy.representative_email"
                            type="email"
                            required
                            placeholder="E-mail du mandataire"
                            class="min-w-0 rounded-lg border-slate-300"
                        />
                        <input
                            type="file"
                            required
                            class="max-w-full min-w-0 text-sm"
                            @change="proxyFile"
                        />
                        <button
                            class="rounded-lg bg-slate-900 px-3 py-2 text-white"
                        >
                            {{ ar ? "إيداع التوكيل" : "Déposer le mandat" }}
                        </button>
                        <p
                            v-if="Object.values(proxy.errors)[0]"
                            class="break-words text-xs text-red-600"
                        >
                            {{ Object.values(proxy.errors)[0] }}
                        </p>
                    </form>
                </section>
                <section
                    v-if="!proxyOnly"
                    class="rounded-2xl border bg-white p-5"
                >
                    <h2 class="font-bold">
                        {{ ar ? "اقتراح سؤال" : "Proposer une question" }}
                    </h2>
                    <form
                        @submit.prevent="
                            q.post(
                                route(
                                    'owner-governance.questions.store',
                                    assembly.id,
                                ),
                                {
                                    preserveScroll: true,
                                    onSuccess: () => q.reset(),
                                },
                            )
                        "
                        class="mt-3 grid gap-3"
                    >
                        <textarea
                            v-model="q.question_fr"
                            required
                            placeholder="Question en français"
                            class="min-w-0 rounded-xl border-slate-300"
                        ></textarea
                        ><textarea
                            v-model="q.question_ar"
                            dir="rtl"
                            placeholder="السؤال بالعربية"
                            class="min-w-0 rounded-xl border-slate-300"
                        ></textarea
                        ><button
                            class="rounded-xl bg-teal-700 px-4 py-2 text-white"
                        >
                            {{ ar ? "إرسال" : "Soumettre" }}
                        </button>
                        <p
                            v-if="Object.values(q.errors)[0]"
                            class="break-words text-sm text-red-600"
                        >
                            {{ Object.values(q.errors)[0] }}
                        </p>
                    </form>
                </section>
                <section
                    v-if="assembly.resolutions.length"
                    class="rounded-2xl border bg-white p-5"
                >
                    <h2 class="font-bold">
                        {{
                            ar ? "المقررات والإنجاز" : "Décisions et exécution"
                        }}
                    </h2>
                    <article
                        v-for="r in assembly.resolutions"
                        :key="r.id"
                        class="mt-3 rounded-xl border p-3"
                    >
                        <b>{{ r.code }} · {{ r.status }}</b>
                        <p class="mt-1 text-sm">
                            {{
                                ar
                                    ? r.final_text_ar || r.final_text_fr
                                    : r.final_text_fr
                            }}
                        </p>
                        <div
                            v-for="x in r.execution_actions"
                            :key="x.id"
                            class="mt-2 rounded bg-slate-50 p-2 text-sm"
                        >
                            {{ x.action_type }} · {{ x.status }}
                            <p>{{ x.description }}</p>
                        </div>
                    </article>
                </section>
            </main>
            <aside class="min-w-0 space-y-5">
                <section class="rounded-2xl border bg-white p-5">
                    <h2 class="font-bold">
                        {{
                            ar
                                ? "حقي المجمد في التصويت"
                                : proxyOnly
                                  ? "Droit de vote représenté"
                                  : "Mon droit de vote figé"
                        }}
                    </h2>
                    <p class="mt-3 break-words">
                        {{ electorate.contact_name_snapshot }}
                    </p>
                    <p class="mt-2 text-sm text-slate-500">
                        {{ electorate.voting_weight_numerator }} /
                        {{ electorate.voting_weight_denominator }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-1">
                        <span
                            v-for="l in electorate.ownership_fractions"
                            :key="l.lot_id"
                            class="rounded bg-slate-100 px-2 py-1 text-xs"
                            >{{ l.reference }} · {{ l.percentage }}%</span
                        >
                    </div>
                </section>
                <section
                    v-if="!proxyOnly"
                    class="rounded-2xl border bg-white p-5"
                >
                    <h2 class="font-bold">
                        {{ ar ? "حالة التبليغ" : "État de remise" }}
                    </h2>
                    <p class="mt-2 text-sm">
                        {{ delivery?.status || "pending" }} ·
                        {{ delivery?.delivery_method || "—" }}
                    </p>
                </section>
            </aside>
        </div></AuthenticatedLayout
    >
</template>
