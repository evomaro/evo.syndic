<script setup lang="ts">
import { Link, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
defineProps<{ members: any[]; invitations: any[] }>();
const { t } = useI18n();
const form = useForm({
    email: "",
    role: "manager",
    preferred_language: "fr",
});
</script>
<template>
    <AuthenticatedLayout :title="t('team')"
        ><div class="grid gap-5 lg:grid-cols-[1fr_360px]">
            <section class="panel overflow-hidden">
                <div class="panel-head">
                    <h2 class="font-bold">{{ t("members") }}</h2>
                </div>
                <div class="divide-y divide-slate-100">
                    <div
                        v-for="m in members"
                        :key="m.id"
                        class="flex items-center gap-3 p-4"
                    >
                        <span
                            class="grid size-11 place-items-center rounded-full bg-teal-50 font-bold text-teal-800"
                            >{{ m.name.slice(0, 2).toUpperCase() }}</span
                        >
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ m.name }}</p>
                            <p class="truncate text-xs text-slate-500">
                                {{ m.email }}
                            </p>
                        </div>
                        <span class="badge border-slate-200">{{
                            t(m.pivot.role)
                        }}</span>
                    </div>
                </div>
            </section>
            <div class="space-y-5">
                <form
                    class="panel p-5"
                    @submit.prevent="
                        form.post(route('team.invite'), {
                            onSuccess: () => form.reset('email'),
                        })
                    "
                >
                    <h2 class="font-bold">{{ t("invite") }}</h2>
                    <div class="mt-4 grid gap-4">
                        <label class="field"
                            ><span class="field-label">{{ t("email") }}</span
                            ><input
                                v-model="form.email"
                                type="email"
                                required /></label
                        ><label class="field"
                            ><span class="field-label">{{ t("role") }}</span
                            ><select v-model="form.role">
                                <option
                                    v-for="r in [
                                        'owner',
                                        'administrator',
                                        'manager',
                                        'accountant',
                                        'maintenance_agent',
                                        'auditor',
                                    ]"
                                    :key="r"
                                >
                                    {{ t(r) }}
                                </option>
                            </select></label
                        ><label class="field"
                            ><span class="field-label">{{ t("language") }}</span
                            ><select v-model="form.preferred_language">
                                <option value="fr">Français</option>
                                <option value="ar">العربية</option>
                            </select></label
                        ><button class="btn-primary">{{ t("invite") }}</button>
                    </div>
                </form>
                <section class="panel p-5">
                    <h2 class="font-bold">{{ t("invitations") }}</h2>
                    <div class="mt-4 space-y-3">
                        <div
                            v-for="i in invitations"
                            :key="i.id"
                            class="rounded-xl bg-slate-50 p-3"
                        >
                            <p class="truncate text-sm font-semibold">
                                {{ i.email }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ t(i.role) }} ·
                                {{
                                    i.cancelled_at
                                        ? t("cancel")
                                        : i.accepted_at
                                          ? t("active")
                                          : t("setup")
                                }}
                            </p>
                            <div v-if="!i.accepted_at" class="mt-2 flex gap-3">
                                <Link
                                    :href="route('team.resend', i.id)"
                                    method="post"
                                    as="button"
                                    class="inline-flex min-h-11 items-center text-xs font-semibold text-teal-700"
                                    >{{ t("invite") }}</Link
                                >
                                <Link
                                    v-if="!i.cancelled_at"
                                    :href="route('team.cancel', i.id)"
                                    method="post"
                                    as="button"
                                    class="inline-flex min-h-11 items-center text-xs font-semibold text-rose-700"
                                    >{{ t("cancel") }}</Link
                                >
                            </div>
                        </div>
                        <p
                            v-if="!invitations.length"
                            class="text-sm text-slate-500"
                        >
                            {{ t("noResults") }}
                        </p>
                    </div>
                </section>
            </div>
        </div></AuthenticatedLayout
    >
</template>
