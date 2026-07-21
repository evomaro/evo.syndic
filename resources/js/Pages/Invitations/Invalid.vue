<script setup lang="ts">
import GuestLayout from "@/Layouts/GuestLayout.vue";
import { Head, Link } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";
defineProps<{ reason: string }>();
const { t } = useI18n();
</script>
<template>
    <GuestLayout
        ><Head :title="t('invitationUnavailable')" />
        <h1 class="text-xl font-bold">{{ t("invitationUnavailable") }}</h1>
        <p class="mt-3 text-sm text-slate-600">
            {{ t("invitation_" + reason) }}
        </p>
        <Link href="/login" class="btn-primary mt-6 block text-center">{{
            t("backToLogin")
        }}</Link></GuestLayout
    >
</template>
