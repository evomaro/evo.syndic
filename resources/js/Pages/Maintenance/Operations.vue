<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import MaintenanceNav from "@/Components/Maintenance/MaintenanceNav.vue";
import Pagination from "@/Components/Pagination.vue";
import { Link, usePage } from "@inertiajs/vue3";
const props = defineProps<{
    mode: string;
    requests: any;
    calendar: any;
    supplierPerformance: any;
    averages: any;
}>();
const ar = usePage<any>().props.locale === "ar";
const statuses = [
    "draft",
    "submitted",
    "under_review",
    "approved",
    "in_progress",
    "resolved",
    "closed",
    "rejected",
    "cancelled",
];
const duration = (seconds: number | null) =>
    seconds === null ? "—" : `${(seconds / 3600).toFixed(1)} h`;
const byStatus = (status: string): any[] =>
    props.requests.data.filter((request: any) => request.status === status);
</script>
<template>
    <AuthenticatedLayout
        :title="
            mode === 'kanban'
                ? 'Kanban'
                : mode === 'calendar'
                  ? ar
                      ? 'تقويم الصيانة'
                      : 'Calendrier maintenance'
                  : ar
                    ? 'تقارير الصيانة'
                    : 'Rapports maintenance'
        "
        ><MaintenanceNav />
        <div
            v-if="mode === 'kanban'"
            class="grid auto-cols-[18rem] grid-flow-col gap-3 overflow-x-auto pb-5"
        >
            <section
                v-for="status in statuses"
                :key="status"
                class="rounded-2xl bg-slate-100 p-3"
            >
                <h2 class="mb-3 font-bold">
                    {{ status }} · {{ byStatus(status).length }}
                </h2>
                <Link
                    v-for="r in byStatus(status)"
                    :key="r.id"
                    :href="route('maintenance.requests.show', r.id)"
                    class="mb-2 block rounded-xl border bg-white p-3"
                    ><b>{{ r.title }}</b>
                    <p class="text-xs text-slate-500">
                        {{ r.reference }} · {{ r.priority }}
                    </p></Link
                >
            </section>
        </div>
        <div
            v-else-if="mode === 'calendar'"
            class="overflow-hidden rounded-2xl border bg-white"
        >
            <div
                v-for="item in calendar"
                :key="item.id"
                class="grid gap-2 border-b p-4 sm:grid-cols-[10rem_1fr_8rem]"
            >
                <b>{{
                    item.planned_start_at?.slice(0, 16).replace("T", " ")
                }}</b
                ><span>{{ item.reference }} · {{ item.resident_notes }}</span
                ><span>{{ item.status }}</span>
            </div>
        </div>
        <div v-else class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <article class="rounded-2xl border bg-white p-5">
                    <p class="text-sm text-slate-500">
                        {{ ar ? "متوسط وقت الاستلام" : "Délai moyen d’accusé" }}
                    </p>
                    <b class="text-2xl">{{ duration(averages.ack_seconds) }}</b>
                </article>
                <article class="rounded-2xl border bg-white p-5">
                    <p class="text-sm text-slate-500">
                        {{
                            ar ? "متوسط وقت الحل" : "Délai moyen de résolution"
                        }}
                    </p>
                    <b class="text-2xl">{{
                        duration(averages.resolution_seconds)
                    }}</b>
                </article>
            </div>
            <section class="overflow-hidden rounded-2xl border bg-white">
                <h2 class="p-4 font-bold">
                    {{ ar ? "أداء الموردين" : "Performance fournisseurs" }}
                </h2>
                <div
                    v-for="s in supplierPerformance"
                    :key="s.id"
                    class="grid grid-cols-3 border-t p-4"
                >
                    <b>{{ s.legal_name }}</b
                    ><span>{{ s.validated }} / {{ s.work_orders }} validés</span
                    ><span>{{ duration(s.average_completion_seconds) }}</span>
                </div>
            </section>
            <section class="overflow-hidden rounded-2xl border bg-white">
                <Link
                    v-for="r in requests.data"
                    :key="r.id"
                    :href="route('maintenance.requests.show', r.id)"
                    class="grid gap-2 border-b p-4 sm:grid-cols-[9rem_1fr_8rem]"
                    ><b>{{ r.reference }}</b
                    ><span>{{ r.title }}</span
                    ><span>{{ r.status }}</span></Link
                >
            </section>
        </div>
        <Pagination
            v-if="mode !== 'calendar'"
            class="mt-4"
            :links="requests.links"
    /></AuthenticatedLayout>
</template>
