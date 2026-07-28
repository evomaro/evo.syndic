<script setup lang="ts">
import { useI18n } from "@/i18n";
import { formatMADCents as money } from "@/Support/money";
defineProps<{ metrics: any }>();
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
</script>
<template>
    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <article
            v-for="(row, key) in metrics"
            v-show="typeof key === 'number'"
            :key="key"
            class="panel p-4"
        >
            <b>{{ row.category }}</b>
            <p class="mt-2 text-sm">
                {{ text("Prévu", "المخطط") }} {{ money(row.planned_cents) }} ·
                {{ text("Réel", "الفعلي") }}
                {{ money(row.actual_cents) }}
            </p>
            <p class="text-sm">
                {{ text("Projeté", "المتوقع") }}
                {{ money(row.projected_cents) }} ·
                {{ text("Disponible", "المتاح") }}
                {{ money(row.available_cents) }}
            </p>
        </article>
    </div>
</template>
