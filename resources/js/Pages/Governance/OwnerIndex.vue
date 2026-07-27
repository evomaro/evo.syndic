<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Link, usePage } from "@inertiajs/vue3";
defineProps<{ assemblies: any }>();
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
        :title="ar ? 'جمعياتي العامة' : 'Mes assemblées générales'"
        :subtitle="
            ar ? 'فضاء المالك المشترك المحمي' : 'Espace copropriétaire protégé'
        "
        ><div class="space-y-3">
            <Link
                v-for="a in assemblies.data"
                :key="a.id"
                :href="route('owner-governance.show', a.id)"
                class="block min-w-0 rounded-2xl border bg-white p-5"
                ><div class="flex flex-wrap justify-between gap-2">
                    <strong class="break-words"
                        >{{ a.reference }} · {{ a.type }}</strong
                    ><span class="rounded-full bg-teal-50 px-3 py-1 text-sm">{{
                        statusLabel(a.status)
                    }}</span>
                </div>
                <p class="mt-2 break-words text-sm text-slate-500">
                    {{ a.meeting_date }} · {{ a.starts_at }} · {{ a.location }}
                </p></Link
            >
            <p
                v-if="!assemblies.data.length"
                class="rounded-2xl border bg-white p-8 text-center text-slate-500"
            >
                {{ ar ? "لا توجد جمعية متاحة" : "Aucune assemblée disponible" }}
            </p>
        </div></AuthenticatedLayout
    >
</template>
