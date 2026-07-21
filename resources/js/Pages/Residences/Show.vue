<script setup lang="ts">
import { Link, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
const p = defineProps<{ residence: any; setup: any }>();
const { t } = useI18n();
const archive = useForm({});
const archiveResidence = () => {
    if (window.confirm(t("archiveConfirm")))
        archive.post(route("residences.archive", p.residence.id));
};
const restoreResidence = () =>
    archive.post(route("residences.restore", p.residence.id));
</script>
<template>
    <AuthenticatedLayout
        :title="residence.name"
        :subtitle="residence.address_line_1 + ', ' + residence.city"
        ><template #actions
            ><div class="flex gap-2">
                <Link
                    v-if="residence.status !== 'archived'"
                    :href="route('residences.edit', residence.id)"
                    class="btn-secondary"
                    >{{ t("edit") }}</Link
                ><button
                    v-if="residence.status !== 'archived'"
                    class="btn-secondary text-rose-700"
                    @click="archiveResidence"
                >
                    {{ t("archive") }}
                </button>
                <button v-else class="btn-primary" @click="restoreResidence">
                    {{ t("restore") }}
                </button>
            </div></template
        >
        <div class="panel mb-5 flex items-center gap-4 p-5">
            <img
                v-if="residence.logo_url"
                :src="residence.logo_url"
                class="size-20 rounded-2xl object-cover"
                alt=""
            />
            <span
                v-else
                class="grid size-20 place-items-center rounded-2xl bg-teal-100 text-xl font-black text-teal-800"
                >{{ residence.initials }}</span
            >
            <div>
                <p
                    class="text-xs font-bold uppercase tracking-wider text-teal-700"
                >
                    {{ residence.code }}
                </p>
                <h2 class="text-xl font-bold">{{ residence.name }}</h2>
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <Link :href="route('structure.index')" class="stat"
                ><p class="text-sm text-slate-500">{{ t("buildings") }}</p>
                <p class="mt-2 text-3xl font-bold">
                    {{ residence.buildings_count }}
                </p></Link
            ><Link :href="route('structure.index')" class="stat"
                ><p class="text-sm text-slate-500">{{ t("lots") }}</p>
                <p class="mt-2 text-3xl font-bold">
                    {{ residence.lots_count }}
                </p></Link
            >
            <div class="stat">
                <p class="text-sm text-slate-500">{{ t("status") }}</p>
                <p
                    class="mt-2 font-bold"
                    :class="
                        setup.operational
                            ? 'text-emerald-700'
                            : 'text-amber-700'
                    "
                >
                    {{
                        setup.operational
                            ? "✓ " + t("operational")
                            : "! " + t("setup")
                    }}
                </p>
            </div>
        </div>
        <div class="panel mt-5 p-5">
            <h2 class="font-bold">{{ t("setupChecklist") }}</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-4">
                    {{ setup.missing_owners ? "!" : "✓" }}
                    {{ t("missingOwners") }}: {{ setup.missing_owners }}
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    {{ setup.missing_allocations ? "!" : "✓" }}
                    {{ t("missingAllocations") }}:
                    {{ setup.missing_allocations }}
                </div>
            </div>
        </div></AuthenticatedLayout
    >
</template>
