<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
import Pagination from "@/Components/Pagination.vue";
defineProps<{ residences: any }>();
const { t } = useI18n();
</script>
<template>
    <AuthenticatedLayout :title="t('residences')"
        ><template #actions
            ><Link :href="route('residences.create')" class="btn-primary"
                >＋ {{ t("newResidence") }}</Link
            ></template
        >
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="r in residences.data"
                :key="r.id"
                :href="route('residences.show', r.id)"
                class="panel p-5 transition hover:-translate-y-0.5 hover:border-teal-300"
                ><div class="flex items-start justify-between">
                    <div class="flex min-w-0 gap-3">
                        <img
                            v-if="r.logo_url"
                            :src="r.logo_url"
                            class="size-12 shrink-0 rounded-xl object-cover"
                            alt=""
                        />
                        <span
                            v-else
                            class="grid size-12 shrink-0 place-items-center rounded-xl bg-teal-100 font-black text-teal-800"
                            >{{ r.initials }}</span
                        >
                        <div class="min-w-0">
                            <p
                                class="text-xs font-bold uppercase tracking-widest text-teal-700"
                            >
                                {{ r.code }}
                            </p>
                            <h2 class="mt-1 text-lg font-bold">{{ r.name }}</h2>
                        </div>
                    </div>
                    <span class="badge border-slate-200 bg-slate-50">{{
                        t(r.status)
                    }}</span>
                </div>
                <div class="mt-6 flex gap-5 text-sm text-slate-500">
                    <span
                        ><b class="text-slate-900">{{ r.buildings_count }}</b>
                        {{ t("buildings") }}</span
                    ><span
                        ><b class="text-slate-900">{{ r.lots_count }}</b>
                        {{ t("lots") }}</span
                    >
                </div></Link
            >
        </div>
        <Pagination :links="residences.links"
    /></AuthenticatedLayout>
</template>
