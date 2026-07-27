<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";
const page = usePage();
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const active = (name: string) =>
    page.url.startsWith(new URL(route(name), window.location.origin).pathname);
const links = [
    ["Dépenses", "المصاريف", "expenses.index"],
    ["Fournisseurs", "الموردون", "suppliers.index"],
    ["Contrats", "العقود", "supplier-contracts.index"],
    ["Engagements", "الالتزامات", "expense-commitments.index"],
    ["Factures", "الفواتير", "supplier-invoices.index"],
    ["Règlements", "التسديدات", "supplier-settlements.index"],
    ["Avoirs", "الإشعارات الدائنة", "supplier-credit-notes.index"],
    ["Dettes", "الذمم المستحقة", "supplier-payables.index"],
    ["Budgets", "الميزانيات", "budgets.index"],
    ["Catégories", "الفئات", "expense-categories.index"],
];
</script>
<template>
    <nav
        class="mb-5 flex gap-2 overflow-x-auto pb-2"
        :aria-label="text('Navigation des dépenses', 'التنقل بين المصاريف')"
    >
        <Link
            v-for="item in links"
            :key="item[2]"
            :href="route(item[2])"
            class="btn-secondary min-h-11 shrink-0"
            :class="active(item[2]) ? '!border-teal-600 !bg-teal-50' : ''"
            >{{ text(item[0], item[1]) }}</Link
        >
    </nav>
</template>
