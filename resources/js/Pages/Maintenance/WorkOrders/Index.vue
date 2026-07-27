<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import MaintenanceNav from "@/Components/Maintenance/MaintenanceNav.vue";
import Pagination from "@/Components/Pagination.vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
defineProps<{ workOrders: any; filters: any }>();
const page = usePage<any>();
const ar = page.props.locale === "ar";
const permissions = page.props.auth.permissions ?? [];
const action = useForm({ status: "", report: "", actual_cost_cents: null });
const move = (id: number, status: string) => {
    action.status = status;
    action.post(route("maintenance.work-orders.transition", id), {
        preserveScroll: true,
    });
};
const reschedule = (workOrder: any) => {
    const start = window.prompt(
        "Début (AAAA-MM-JJ HH:MM)",
        workOrder.planned_start_at?.slice(0, 16).replace("T", " ") ?? "",
    );
    if (!start) return;
    const end = window.prompt(
        "Fin (AAAA-MM-JJ HH:MM)",
        workOrder.planned_end_at?.slice(0, 16).replace("T", " ") ?? "",
    );
    const reason = window.prompt("Motif obligatoire de replanification");
    if (!end || !reason?.trim()) return;
    router.put(
        route("maintenance.work-orders.schedule", workOrder.id),
        { planned_start_at: start, planned_end_at: end, reason },
        { preserveScroll: true },
    );
};
</script>
<template>
    <AuthenticatedLayout :title="ar ? 'أوامر العمل' : 'Bons de travail'"
        ><MaintenanceNav />
        <div
            v-if="!workOrders.data.length"
            class="rounded-2xl border bg-white p-10 text-center text-slate-500"
        >
            {{ ar ? "لا توجد أوامر عمل" : "Aucun bon de travail" }}
        </div>
        <article
            v-for="w in workOrders.data"
            :key="w.id"
            class="mb-4 rounded-2xl border bg-white p-5"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-mono font-black text-teal-800">
                        {{ w.reference }}
                    </h2>
                    <p class="font-semibold">
                        {{ w.request?.title || w.scope_of_work }}
                    </p>
                    <p class="text-sm text-slate-500">
                        {{
                            w.supplier?.legal_name ||
                            (ar ? "فريق داخلي" : "Équipe interne")
                        }}
                    </p>
                </div>
                <span
                    class="rounded-full bg-slate-100 px-3 py-1 text-sm font-bold"
                    >{{ w.status }}</span
                >
            </div>
            <p class="mt-3 whitespace-pre-wrap text-sm">
                {{ w.scope_of_work }}
            </p>
            <div
                v-if="w.status !== 'validated' && w.status !== 'cancelled'"
                class="mt-4"
            >
                <textarea
                    v-model="action.report"
                    class="w-full rounded-xl border-slate-300"
                    :placeholder="ar ? 'تقرير أو سبب' : 'Rapport ou motif'"
                ></textarea
                ><input
                    v-if="w.status === 'in_progress'"
                    v-model.number="action.actual_cost_cents"
                    type="number"
                    min="0"
                    placeholder="Coût réel (centimes)"
                    class="mt-2 rounded-xl border-slate-300"
                />
                <div class="mt-2 flex flex-wrap gap-2">
                    <button
                        v-if="['draft', 'scheduled'].includes(w.status)"
                        type="button"
                        @click="reschedule(w)"
                        class="rounded-lg border px-3 py-2"
                    >
                        {{ ar ? "إعادة الجدولة" : "Replanifier" }}
                    </button>
                    <button
                        v-if="w.status === 'draft'"
                        @click="move(w.id, 'scheduled')"
                        class="rounded-lg border px-3 py-2"
                    >
                        scheduled</button
                    ><button
                        v-if="['scheduled', 'completed'].includes(w.status)"
                        @click="move(w.id, 'in_progress')"
                        class="rounded-lg border px-3 py-2"
                    >
                        in_progress</button
                    ><button
                        v-if="
                            w.status === 'in_progress' &&
                            permissions.includes('complete_work_orders')
                        "
                        @click="move(w.id, 'completed')"
                        class="rounded-lg bg-amber-500 px-3 py-2 font-bold"
                    >
                        completed</button
                    ><button
                        v-if="
                            w.status === 'completed' &&
                            permissions.includes('validate_work_orders')
                        "
                        @click="move(w.id, 'validated')"
                        class="rounded-lg bg-teal-700 px-3 py-2 font-bold text-white"
                    >
                        validated</button
                    ><button
                        v-if="
                            ['draft', 'scheduled', 'in_progress'].includes(
                                w.status,
                            )
                        "
                        @click="move(w.id, 'cancelled')"
                        class="rounded-lg border border-red-200 px-3 py-2 text-red-700"
                    >
                        cancelled
                    </button>
                </div>
            </div>
            <div
                v-if="w.invoice"
                class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm font-semibold"
            >
                Facture Phase 03 · {{ w.invoice.status }} ·
                {{ (w.invoice.total_cents / 100).toFixed(2) }} MAD
            </div>
        </article>
        <Pagination :links="workOrders.links"
    /></AuthenticatedLayout>
</template>
