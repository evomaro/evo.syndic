<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
const p = usePage<any>();
const ar = p.props.locale === "ar";
const links = [
    ["governance.dashboard", ar ? "لوحة القيادة" : "Tableau de bord"],
    ["governance.index", ar ? "الجمعيات" : "Assemblées"],
    ["governance.mandates.index", ar ? "الولايات" : "Mandats"],
    ["governance.reports", ar ? "التقارير" : "Rapports"],
];
</script>
<template>
    <nav
        class="mb-6 flex max-w-full gap-2 overflow-x-auto pb-1"
        aria-label="Gouvernance"
    >
        <Link
            v-for="l in links"
            :key="l[0]"
            :href="route(l[0])"
            class="shrink-0 rounded-xl border bg-white px-4 py-2 text-sm font-semibold hover:border-teal-500"
            >{{ l[1] }}</Link
        >
    </nav>
</template>
