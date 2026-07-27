<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import MaintenanceNav from "@/Components/Maintenance/MaintenanceNav.vue";
import { Link, usePage } from "@inertiajs/vue3";
defineProps<{ metrics: any }>();
const page = usePage<any>();
const ar = page.props.locale === "ar";
const money = (value: number) =>
    new Intl.NumberFormat(ar ? "ar-MA" : "fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(value / 100);
</script>

<template>
    <AuthenticatedLayout
        :title="ar ? 'الصيانة والحوادث' : 'Maintenance et incidents'"
        :subtitle="
            ar
                ? 'مؤشرات تشغيلية حقيقية ومحددة حسب الإقامة'
                : 'Indicateurs opérationnels réels et limités à la résidence'
        "
    >
        <MaintenanceNav />
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="card in [
                    [ar ? 'طلبات مفتوحة' : 'Demandes ouvertes', metrics.open],
                    [ar ? 'متأخرة حسب SLA' : 'SLA en retard', metrics.overdue],
                    [ar ? 'إعادات الفتح' : 'Réouvertures', metrics.reopened],
                    [
                        ar ? 'تدخلات وقائية' : 'Préventif à traiter',
                        metrics.preventive_due,
                    ],
                ]"
                :key="card[0]"
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <p class="text-sm text-slate-500">{{ card[0] }}</p>
                <p class="mt-2 text-3xl font-black text-slate-950">
                    {{ card[1] }}
                </p>
            </article>
        </div>
        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-bold">
                    {{
                        ar
                            ? "تكلفة أوامر العمل الفعلية"
                            : "Coût réel des bons de travail"
                    }}
                </h2>
                <p class="mt-3 text-2xl font-black">
                    {{ money(metrics.actual_work_cost_cents) }}
                </p>
                <p class="mt-1 text-sm text-slate-500">
                    {{
                        ar ? "لا يشمل المبالغ المقدرة" : "Hors montants estimés"
                    }}
                </p>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-bold">
                    {{
                        ar
                            ? "فواتير الموردين المعتمدة"
                            : "Factures fournisseur validées"
                    }}
                </h2>
                <p class="mt-3 text-2xl font-black">
                    {{ money(metrics.validated_invoice_cents) }}
                </p>
                <p class="mt-1 text-sm text-slate-500">
                    {{
                        ar
                            ? "قيمة المرحلة 03 المرجعية"
                            : "Valeur Phase 03 de référence"
                    }}
                </p>
            </section>
        </div>
        <div class="mt-6 flex flex-wrap gap-3">
            <Link
                :href="route('maintenance.requests.create')"
                class="rounded-xl bg-teal-700 px-4 py-3 font-semibold text-white"
                >{{ ar ? "طلب جديد" : "Nouvelle demande" }}</Link
            ><Link
                :href="route('maintenance.requests.index')"
                class="rounded-xl border bg-white px-4 py-3 font-semibold"
                >{{ ar ? "عرض الطلبات" : "Voir les demandes" }}</Link
            >
        </div>
    </AuthenticatedLayout>
</template>
