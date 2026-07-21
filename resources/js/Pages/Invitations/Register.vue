<script setup lang="ts">
import GuestLayout from "@/Layouts/GuestLayout.vue";
import InputError from "@/Components/InputError.vue";
import { Head, useForm } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";
const props = defineProps<{ token: string; email: string }>();
const { t } = useI18n();
const form = useForm({ name: "", password: "", password_confirmation: "" });
</script>
<template>
    <GuestLayout>
        <Head :title="t('createInvitedAccount')" />
        <h1 class="text-xl font-bold">{{ t("createInvitedAccount") }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ email }}</p>
        <form
            class="mt-6 grid gap-4"
            @submit.prevent="
                form.post(route('invitations.register.store', props.token))
            "
        >
            <label class="field"
                ><span class="field-label">{{ t("name") }}</span
                ><input
                    v-model="form.name"
                    required
                    autocomplete="name" /><InputError
                    :message="form.errors.name"
            /></label>
            <label class="field"
                ><span class="field-label">{{ t("password") }}</span
                ><input
                    v-model="form.password"
                    required
                    type="password"
                    autocomplete="new-password" /><InputError
                    :message="form.errors.password"
            /></label>
            <label class="field"
                ><span class="field-label">{{ t("confirmPassword") }}</span
                ><input
                    v-model="form.password_confirmation"
                    required
                    type="password"
                    autocomplete="new-password"
            /></label>
            <button class="btn-primary" :disabled="form.processing">
                {{ t("registerAndAccept") }}
            </button>
        </form>
    </GuestLayout>
</template>
