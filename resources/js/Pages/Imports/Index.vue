<script setup lang="ts">
import { useForm, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
import Pagination from "@/Components/Pagination.vue";
defineProps<{ batches: any }>();
const { t } = useI18n();
const form = useForm<{ type: string; file: File | null }>({
    type: "lots",
    file: null,
});
const rollback = (id: number) => {
    if (window.confirm(t("confirm")))
        router.post(route("imports.rollback", id));
};
</script>
<template>
    <AuthenticatedLayout :title="t('imports')"
        ><div class="grid gap-5 lg:grid-cols-[380px_1fr]">
            <form
                class="panel h-fit p-5"
                @submit.prevent="form.post(route('imports.upload'))"
            >
                <h2 class="font-bold">{{ t("upload") }}</h2>
                <div class="mt-5 grid gap-4">
                    <label class="field"
                        ><span class="field-label">{{ t("importType") }}</span
                        ><select v-model="form.type">
                            <option
                                v-for="x in [
                                    'lots',
                                    'contacts',
                                    'ownerships',
                                    'occupancies',
                                    'allocations',
                                ]"
                                :key="x"
                            >
                                {{ t(x) }}
                            </option>
                        </select></label
                    ><label class="field"
                        ><span class="field-label">{{ t("file") }}</span
                        ><input
                            type="file"
                            accept=".csv,.xlsx"
                            required
                            @change="
                                form.file =
                                    ($event.target as HTMLInputElement)
                                        .files?.[0] ?? null
                            " /></label
                    ><a
                        :href="route('imports.template', form.type)"
                        class="text-sm font-semibold text-teal-700"
                        >↓ {{ t("downloadTemplate") }}</a
                    ><button class="btn-primary">{{ t("next") }}</button>
                </div>
            </form>
            <section class="panel overflow-hidden">
                <div class="panel-head">
                    <h2 class="font-bold">{{ t("report") }}</h2>
                </div>
                <div class="divide-y divide-slate-100">
                    <div
                        v-for="b in batches.data"
                        :key="b.id"
                        class="flex items-center justify-between p-4"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-semibold">
                                {{ b.original_filename }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ b.type }} ·
                                {{ new Date(b.created_at).toLocaleString() }}
                            </p>
                        </div>
                        <span class="badge border-slate-200">{{
                            b.status
                        }}</span>
                        <div class="ms-3 flex gap-2">
                            <Link
                                v-if="b.failed_rows"
                                :href="route('imports.errors', b.id)"
                                class="text-sm font-semibold text-red-700"
                                >CSV</Link
                            >
                            <button
                                v-if="
                                    [
                                        'completed',
                                        'completed_with_errors',
                                    ].includes(b.status)
                                "
                                class="text-sm font-semibold text-amber-700"
                                @click="rollback(b.id)"
                            >
                                {{ t("cancel") }}
                            </button>
                        </div>
                    </div>
                    <p
                        v-if="!batches.data.length"
                        class="p-8 text-center text-sm text-slate-500"
                    >
                        {{ t("noResults") }}
                    </p>
                </div>
            </section>
            <Pagination :links="batches.links" /></div
    ></AuthenticatedLayout>
</template>
