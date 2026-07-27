<script setup lang="ts">
import { useI18n } from "@/i18n";
defineProps<{ categories: any[] }>();
const value = defineModel<number | string | null>();
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
</script>
<template>
    <select v-model="value" class="w-full rounded-lg border-slate-300">
        <option value="">
            {{ text("Choisir une catégorie", "اختر فئة") }}
        </option>
        <option
            v-for="category in categories"
            :key="category.id"
            :value="category.id"
        >
            {{ category.name }}
        </option>
    </select>
</template>
