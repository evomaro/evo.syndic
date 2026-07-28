<script setup lang="ts">
import { useForm, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
import EmptyState from "@/Components/EmptyState.vue";
const props = defineProps<{
    notifications: any;
    unreadCount: number;
    preference: any;
}>();
const form = useForm({
    database_enabled: props.preference.database_enabled,
    email_enabled: props.preference.email_enabled,
    muted_events: props.preference.muted_events || [],
});
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const formatDate = (value: string) =>
    new Intl.DateTimeFormat(locale.value === "ar" ? "ar-MA" : "fr-MA", {
        dateStyle: "medium",
        timeStyle: "short",
    }).format(new Date(value));
</script>
<template>
    <AuthenticatedLayout
        :title="text('Notifications', 'الإشعارات')"
        :subtitle="
            text(`${unreadCount} non lue(s)`, `${unreadCount} غير مقروءة`)
        "
        ><template #actions
            ><button
                class="btn-secondary"
                @click="router.post(route('notifications.read-all'))"
            >
                {{ text("Tout marquer comme lu", "تحديد الكل كمقروء") }}
            </button></template
        >
        <div class="grid gap-5 xl:grid-cols-[1fr_340px]">
            <div class="grid gap-3">
                <button
                    v-for="n in notifications.data"
                    class="panel p-5 text-start"
                    :class="!n.read_at ? 'border-teal-400 bg-teal-50/40' : ''"
                    @click="router.post(route('notifications.read', n.id))"
                >
                    <b>{{ n.data.title }}</b>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ n.data.message }}
                    </p>
                    <small>{{ formatDate(n.created_at) }}</small>
                </button>
                <EmptyState
                    v-if="!notifications.data.length"
                    :title="text('Aucune notification', 'لا توجد إشعارات')"
                    :message="
                        text(
                            'Vous êtes à jour. Vous pouvez vérifier vos préférences de notification.',
                            'أنتم على اطلاع. يمكنكم التحقق من تفضيلات الإشعارات.',
                        )
                    "
                    :primary-label="
                        text('Configurer les préférences', 'إعداد التفضيلات')
                    "
                    primary-href="#notification-settings"
                >
                    <template #icon>♢</template>
                </EmptyState>
            </div>
            <form
                id="notification-settings"
                class="panel grid h-fit gap-4 p-5"
                @submit.prevent="form.put(route('notifications.preferences'))"
            >
                <h2 class="font-bold">
                    {{ text("Préférences", "التفضيلات") }}
                </h2>
                <label class="flex min-h-11 items-center gap-3"
                    ><input v-model="form.database_enabled" type="checkbox" />{{
                        text(
                            "Notifications dans l’application",
                            "إشعارات داخل التطبيق",
                        )
                    }}</label
                ><label class="flex min-h-11 items-center gap-3"
                    ><input v-model="form.email_enabled" type="checkbox" />{{
                        text(
                            "Notifications par e-mail",
                            "إشعارات عبر البريد الإلكتروني",
                        )
                    }}</label
                ><button class="btn-primary">
                    {{ text("Enregistrer", "حفظ") }}
                </button>
            </form>
        </div></AuthenticatedLayout
    >
</template>
