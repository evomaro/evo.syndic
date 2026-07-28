<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import GovernanceNav from "@/Components/Governance/GovernanceNav.vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import { reactive } from "vue";
import EmptyState from "@/Components/EmptyState.vue";
const props = defineProps<{ assemblies: any; filters: any }>();
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
    statusLabels[status]?.[ar ? "ar" : "fr"] ??
    (ar ? "حالة غير معروفة" : "Statut inconnu");
const f = reactive({
    search: props.filters.search ?? "",
    status: props.filters.status ?? "",
    sort: props.filters.sort ?? "meeting_date",
    direction: props.filters.direction ?? "desc",
});
const apply = () =>
    router.get(route("governance.index"), f, {
        preserveState: true,
        replace: true,
    });
</script>
<template>
    <AuthenticatedLayout
        :title="ar ? 'الجمعيات العامة' : 'Assemblées générales'"
        ><GovernanceNav />
        <div class="mb-5 flex flex-wrap gap-3">
            <input
                v-model="f.search"
                @keyup.enter="apply"
                :placeholder="ar ? 'بحث' : 'Rechercher'"
                class="min-w-0 flex-1 rounded-xl border-slate-300"
            /><select
                v-model="f.status"
                @change="apply"
                class="rounded-xl border-slate-300"
            >
                <option value="">
                    {{ ar ? "كل الحالات" : "Tous les statuts" }}
                </option>
                <option
                    v-for="s in [
                        'draft',
                        'preparing',
                        'convocation_issued',
                        'scheduled',
                        'in_session',
                        'deliberations_completed',
                        'minutes_prepared',
                        'minutes_signed',
                        'decisions_notified',
                        'closed',
                        'cancelled',
                        'postponed',
                        'adjourned_no_quorum',
                    ]"
                    :key="s"
                    :value="s"
                >
                    {{ statusLabel(s) }}
                </option></select
            ><button @click="apply" class="rounded-xl border bg-white px-4">
                {{ ar ? "تصفية" : "Filtrer" }}</button
            ><Link
                :href="route('governance.create')"
                class="rounded-xl bg-teal-700 px-4 py-2 text-white"
                >{{ ar ? "جديدة" : "Nouvelle" }}</Link
            >
        </div>
        <div class="space-y-3">
            <Link
                v-for="a in assemblies.data"
                :key="a.id"
                :href="route('governance.show', a.id)"
                class="block rounded-2xl border bg-white p-5 hover:border-teal-500"
                ><div class="flex min-w-0 flex-wrap justify-between gap-2">
                    <strong class="break-words"
                        >{{ a.reference }} · {{ a.type }}</strong
                    ><span
                        class="rounded-full bg-slate-100 px-3 py-1 text-sm"
                        >{{ statusLabel(a.status) }}</span
                    >
                </div>
                <p class="mt-2 text-sm text-slate-500">
                    {{ a.meeting_date }} · {{ a.starts_at }} · {{ a.location }}
                </p></Link
            >
            <EmptyState
                v-if="!assemblies.data.length"
                :title="
                    ar ? 'لا توجد جمعيات عامة' : 'Aucune assemblée générale'
                "
                :message="
                    ar
                        ? 'أنشئوا أول جمعية وابدؤوا بإعداد جدول الأعمال.'
                        : 'Créez la première assemblée et commencez à préparer son ordre du jour.'
                "
                :primary-label="ar ? 'إنشاء جمعية' : 'Créer une assemblée'"
                :primary-href="route('governance.create')"
            >
                <template #icon>⚖</template>
            </EmptyState>
        </div>
        <div class="mt-5 flex flex-wrap gap-2">
            <Link
                v-for="l in assemblies.links"
                :key="l.label"
                :href="l.url || '#'"
                v-html="l.label"
                class="rounded-lg border px-3 py-2"
                :class="{
                    'bg-slate-900 text-white': l.active,
                    'pointer-events-none opacity-50': !l.url,
                }"
            /></div
    ></AuthenticatedLayout>
</template>
