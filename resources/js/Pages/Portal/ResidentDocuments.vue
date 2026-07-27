<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Pagination from "@/Components/Pagination.vue";
import { useI18n } from "@/i18n";

defineProps<{ documents: any }>();
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
        :title="text('Mes documents', 'وثائقي')"
        :subtitle="
            text('Documents publiés pour vos lots', 'الوثائق المنشورة لوحداتكم')
        "
    >
        <section class="panel overflow-hidden">
            <a
                v-for="document in documents.data.filter(
                    (item: any) => item.published_version,
                )"
                :key="document.id"
                :href="
                    route('documents.download', document.published_version.id)
                "
                class="flex min-h-16 items-center justify-between gap-4 border-b px-5 py-3 hover:bg-slate-50"
            >
                <span>
                    <b class="block">{{ document.title }}</b>
                    <small class="text-slate-500">
                        {{ document.category }} ·
                        {{ formatDate(document.published_at) }}
                    </small>
                </span>
                <span aria-hidden="true">↓</span>
            </a>
            <p
                v-if="
                    !documents.data.some((item: any) => item.published_version)
                "
                class="p-6 text-slate-500"
            >
                {{ text("Aucun document publié.", "لا توجد وثائق منشورة.") }}
            </p>
        </section>
        <Pagination class="mt-4" :links="documents.links" />
    </AuthenticatedLayout>
</template>
