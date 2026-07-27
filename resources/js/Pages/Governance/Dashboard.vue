<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import GovernanceNav from "@/Components/Governance/GovernanceNav.vue";
import { Link, usePage } from "@inertiajs/vue3";
defineProps<{ metrics: any; upcoming: any[] }>();
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
</script>
<template>
    <AuthenticatedLayout
        :title="ar ? 'الحكامة والجمعيات' : 'Gouvernance et assemblées'"
        :subtitle="
            ar
                ? 'مؤشرات قانونية وتشغيلية حسب الإقامة'
                : 'Indicateurs juridiques et opérationnels de la résidence'
        "
        ><GovernanceNav />
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="c in [
                    [
                        ar ? 'جمعيات منعقدة' : 'Assemblées tenues',
                        metrics.assemblies_held,
                    ],
                    [
                        ar ? 'قيد الإعداد' : 'En préparation',
                        metrics.assemblies_pending,
                    ],
                    [
                        ar ? 'فشل النصاب' : 'Défauts de quorum',
                        metrics.quorum_failures,
                    ],
                    [
                        ar ? 'تنفيذ متأخر' : 'Exécutions en retard',
                        metrics.execution_overdue,
                    ],
                    [
                        ar ? 'مقررات مقبولة' : 'Résolutions adoptées',
                        metrics.adopted,
                    ],
                    [
                        ar ? 'مقررات مرفوضة' : 'Résolutions rejetées',
                        metrics.rejected,
                    ],
                    [
                        ar ? 'فشل التبليغ' : 'Échecs de remise',
                        metrics.delivery_failures,
                    ],
                    [
                        ar ? 'ولايات تنتهي قريبا' : 'Mandats à échéance',
                        metrics.mandates_expiring,
                    ],
                ]"
                :key="c[0]"
                class="rounded-2xl border bg-white p-5 shadow-sm"
            >
                <p class="text-sm text-slate-500">{{ c[0] }}</p>
                <p class="mt-2 text-3xl font-black">{{ c[1] }}</p>
            </article>
        </div>
        <section class="mt-6 rounded-2xl border bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-bold">
                    {{ ar ? "الجمعيات المقبلة" : "Prochaines assemblées" }}
                </h2>
                <Link
                    :href="route('governance.create')"
                    class="rounded-xl bg-teal-700 px-4 py-2 font-semibold text-white"
                    >{{ ar ? "إنشاء جمعية" : "Créer une assemblée" }}</Link
                >
            </div>
            <div class="mt-4 space-y-2">
                <Link
                    v-for="a in upcoming"
                    :key="a.id"
                    :href="route('governance.show', a.id)"
                    class="flex flex-wrap justify-between gap-2 rounded-xl border p-3 hover:border-teal-500"
                    ><span class="font-bold"
                        >{{ a.reference }} · {{ a.type }}</span
                    ><span class="text-sm text-slate-500"
                        >{{ a.meeting_date }} ·
                        {{ statusLabel(a.status) }}</span
                    ></Link
                >
                <p v-if="!upcoming.length" class="text-slate-500">
                    {{
                        ar
                            ? "لا توجد جمعيات مبرمجة"
                            : "Aucune assemblée programmée"
                    }}
                </p>
            </div>
        </section></AuthenticatedLayout
    >
</template>
