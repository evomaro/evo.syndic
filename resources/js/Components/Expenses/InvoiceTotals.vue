<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "@/i18n";
import { formatMADCents as money } from "@/Support/money";
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const props = defineProps<{ lines: any[] }>();
const subtotal = computed(() =>
    props.lines.reduce(
        (s, l) =>
            s +
            Math.round(
                Number(l.quantity || 0) * Number(l.unit_price_cents || 0),
            ),
        0,
    ),
);
const tax = computed(() =>
    props.lines.reduce(
        (s, l) =>
            s +
            Math.round(
                (Number(l.quantity || 0) *
                    Number(l.unit_price_cents || 0) *
                    Number(l.tax_rate || 0)) /
                    100,
            ),
        0,
    ),
);
</script>
<template>
    <div class="grid grid-cols-3 gap-3 rounded-lg bg-slate-50 p-4 text-sm">
        <span
            >{{ text("Sous-total", "المجموع الفرعي") }}<br /><b>{{
                money(subtotal)
            }}</b></span
        ><span
            >TVA<br /><b>{{ money(tax) }}</b></span
        ><span
            >{{ text("Total", "المجموع") }}<br /><b>{{
                money(subtotal + tax)
            }}</b></span
        >
    </div>
</template>
