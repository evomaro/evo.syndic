<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Pagination from "@/Components/Pagination.vue";
import { useI18n } from "@/i18n";

defineProps<{ announcements: any }>();
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const formatDate = (value?: string | null) =>
    value
        ? new Intl.DateTimeFormat(
              locale.value === "ar" ? "ar-MA" : "fr-MA",
          ).format(new Date(`${value.slice(0, 10)}T12:00:00Z`))
        : "—";
</script>

<template>
    <AuthenticatedLayout
        :title="text('Annonces', 'الإعلانات')"
        :subtitle="text('Communications de votre résidence', 'إعلانات إقامتكم')"
    >
        <div class="grid gap-4">
            <article
                v-for="announcement in announcements.data"
                :key="announcement.id"
                class="panel p-5"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h2 class="font-bold">{{ announcement.title }}</h2>
                    <span class="badge">{{ announcement.priority }}</span>
                </div>
                <p class="mt-3 whitespace-pre-wrap text-sm leading-6">
                    {{ announcement.body }}
                </p>
                <small class="mt-3 block text-slate-500">
                    {{ formatDate(announcement.published_at) }}
                </small>
            </article>
            <p
                v-if="!announcements.data.length"
                class="panel p-6 text-slate-500"
            >
                {{ text("Aucune annonce publiée.", "لا توجد إعلانات منشورة.") }}
            </p>
        </div>
        <Pagination class="mt-4" :links="announcements.links" />
    </AuthenticatedLayout>
</template>
