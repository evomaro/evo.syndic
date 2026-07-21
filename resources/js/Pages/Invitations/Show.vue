<script setup lang="ts">
import GuestLayout from "@/Layouts/GuestLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";
defineProps<{
    invitation: any;
    token: string;
    authenticated: boolean;
    email_matches: boolean;
}>();
const { t } = useI18n();
</script>
<template>
    <GuestLayout>
        <Head :title="t('invitationTitle')" />
        <h1 class="text-xl font-bold">{{ t("invitationTitle") }}</h1>
        <p class="mt-3 text-sm text-slate-600">
            {{ t("invitedTo") }} <b>{{ invitation.organization }}</b>
        </p>
        <p class="mt-1 text-sm text-slate-600">
            {{ invitation.email }} · {{ invitation.role }}
        </p>
        <div class="mt-6 grid gap-3">
            <button
                v-if="authenticated && email_matches"
                class="btn-primary"
                @click="router.post(route('invitations.accept', token))"
            >
                {{ t("acceptInvitation") }}
            </button>
            <p
                v-else-if="authenticated"
                class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800"
            >
                {{ t("wrongInvitationAccount") }}
            </p>
            <template v-else>
                <Link :href="route('login')" class="btn-primary text-center">{{
                    t("loginToAccept")
                }}</Link>
                <Link
                    :href="route('invitations.register', token)"
                    class="btn-secondary text-center"
                    >{{ t("createInvitedAccount") }}</Link
                >
            </template>
        </div>
    </GuestLayout>
</template>
