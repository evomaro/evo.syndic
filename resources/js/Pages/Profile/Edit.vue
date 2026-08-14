<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import DeleteUserForm from "./Partials/DeleteUserForm.vue";
import UpdatePasswordForm from "./Partials/UpdatePasswordForm.vue";
import UpdateProfileInformationForm from "./Partials/UpdateProfileInformationForm.vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";
const { t } = useI18n();
const page = usePage<any>();
const experience = useForm({
    experience_mode: page.props.tenant?.organization?.experience_mode ?? "pro",
});

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();
</script>

<template>
    <Head :title="t('profile')" />

    <AuthenticatedLayout :title="t('profile')">
        <template #actions>
            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="btn-secondary"
                >{{ t("logout") }}</Link
            >
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div
                    v-if="
                        page.props.auth?.permissions?.includes(
                            'manage_organization',
                        )
                    "
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <form
                        class="max-w-xl"
                        @submit.prevent="
                            experience.patch(
                                route('essential.experience.update'),
                            )
                        "
                    >
                        <h2 class="text-lg font-medium text-gray-900">
                            Expérience EvoSyndic
                        </h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Essential simplifie les tâches quotidiennes. Pro
                            conserve tous les modules avancés.
                        </p>
                        <div class="mt-4 flex flex-wrap items-end gap-3">
                            <label class="field flex-1">
                                <span class="field-label"
                                    >Mode de l’organisation</span
                                >
                                <select v-model="experience.experience_mode">
                                    <option value="essential">Essential</option>
                                    <option value="pro">Pro</option>
                                </select>
                            </label>
                            <button
                                class="btn-primary"
                                :disabled="experience.processing"
                            >
                                Appliquer
                            </button>
                        </div>
                    </form>
                </div>

                <div class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
