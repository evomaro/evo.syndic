<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { reactive } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import Pagination from "@/Components/Pagination.vue";
import { useI18n } from "@/i18n";
const p = defineProps<{ calls: any; exercises: any[]; filters: any }>();
const { t, locale } = useI18n();
const f = reactive({ ...p.filters });
const money = (c: number) =>
    new Intl.NumberFormat(locale.value === "ar" ? "ar-MA" : "fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(c / 100);
const apply = () =>
    router.get(route("fund-calls.index"), f, { preserveState: true });
</script>
<template>
    <AuthenticatedLayout :title="t('fundCalls')"
        ><template #actions
            ><Link :href="route('fund-calls.create')" class="btn-primary">{{
                t("create")
            }}</Link></template
        ><FinanceNav />
        <form
            class="panel mb-4 grid gap-3 p-4 sm:grid-cols-4"
            @submit.prevent="apply"
        >
            <input v-model="f.search" :placeholder="t('search')" /><select
                v-model="f.status"
            >
                <option value="">{{ t("all") }}</option>
                <option
                    v-for="s in ['draft', 'validated', 'closed', 'cancelled']"
                    :value="s"
                >
                    {{ t(s) }}
                </option></select
            ><select v-model="f.financial_exercise_id">
                <option value="">{{ t("exercises") }}</option>
                <option v-for="e in exercises" :value="e.id">
                    {{ e.name }}
                </option></select
            ><button class="btn-secondary">{{ t("filters") }}</button>
        </form>
        <div class="grid gap-3">
            <Link
                v-for="call in calls.data"
                :key="call.id"
                :href="route('fund-calls.show', call.id)"
                class="panel flex min-h-20 items-center justify-between gap-4 p-4"
                ><div>
                    <b>{{ call.number || t("draft") }} · {{ call.title }}</b>
                    <p class="text-sm text-slate-500">
                        {{ call.issue_date }} → {{ call.due_date }} ·
                        {{ call.exercise.name }}
                    </p>
                </div>
                <div class="text-end">
                    <b>{{ money(call.total_cents) }}</b
                    ><span class="badge ms-2">{{ t(call.status) }}</span>
                </div></Link
            >
            <p
                v-if="!calls.data.length"
                class="panel p-8 text-center text-slate-500"
            >
                {{ t("noResults") }}
            </p>
        </div>
        <Pagination :links="calls.links"
    /></AuthenticatedLayout>
</template>
