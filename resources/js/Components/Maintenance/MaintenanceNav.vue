<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
const page = usePage<any>();
const links = [
    ["maintenance.dashboard", "Tableau de bord", "لوحة القيادة"],
    ["maintenance.requests.index", "Demandes", "الطلبات"],
    ["maintenance.work-orders.index", "Bons de travail", "أوامر العمل"],
    ["maintenance.preventive.index", "Préventif", "الصيانة الوقائية"],
    ["maintenance.equipment.index", "Équipements", "المعدات"],
    ["maintenance.categories.index", "Catégories", "الفئات"],
    ["maintenance.operations.kanban", "Kanban", "كانبان"],
    ["maintenance.operations.calendar", "Calendrier", "التقويم"],
    ["maintenance.operations.reports", "Rapports", "التقارير"],
];
</script>

<template>
    <nav class="mb-6 flex gap-2 overflow-x-auto pb-1" aria-label="Maintenance">
        <Link
            v-for="item in links"
            :key="item[0]"
            :href="route(item[0])"
            class="whitespace-nowrap rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-teal-300 hover:text-teal-800"
            >{{ page.props.locale === "ar" ? item[2] : item[1] }}</Link
        >
    </nav>
</template>
