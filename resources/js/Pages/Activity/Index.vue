<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
import Pagination from "@/Components/Pagination.vue";
defineProps<{ activities: any }>();
const { t } = useI18n();
</script>
<template>
    <AuthenticatedLayout :title="t('activity')"
        ><div class="panel divide-y divide-slate-100">
            <article
                v-for="item in activities.data"
                :key="item.id"
                class="flex gap-4 p-5"
            >
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-full bg-slate-100"
                    >◷</span
                >
                <div>
                    <p class="font-semibold">{{ item.description }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ new Date(item.created_at).toLocaleString() }}
                    </p>
                </div>
            </article>
            <p
                v-if="!activities.data.length"
                class="p-10 text-center text-sm text-slate-500"
            >
                {{ t("noResults") }}
            </p>
        </div>
        <Pagination :links="activities.links"
    /></AuthenticatedLayout>
</template>
