<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";

defineProps<{ posting: any }>();
const { locale } = useI18n();
const l = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const labels: Record<string, [string, string]> = {
    not_applicable: ["Non applicable", "غير مطبق"],
    not_activated: ["Comptabilité non activée", "المحاسبة غير مفعلة"],
    pending: ["En attente", "قيد الانتظار"],
    posted: ["Comptabilisé", "تم الترحيل"],
    reversed: ["Extourné", "تم العكس"],
    failed: ["Échec comptable", "فشل محاسبي"],
};
</script>

<template>
    <div
        v-if="posting"
        class="rounded-xl border border-teal-200 bg-teal-50 p-3 text-sm"
        role="status"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <b>{{ l("Statut comptable", "الحالة المحاسبية") }}</b>
            <span class="rounded-full bg-white px-2 py-1">
                {{
                    l(
                        labels[posting?.status]?.[0] || posting?.status,
                        labels[posting?.status]?.[1] || posting?.status,
                    )
                }}
            </span>
        </div>
        <Link
            v-if="posting?.entry_id"
            :href="route('accounting.entries.show', posting.entry_id)"
            class="mt-2 inline-block font-semibold text-teal-800 underline"
        >
            {{ posting.entry_number }} ·
            {{ l("Voir l’écriture", "عرض القيد") }}
        </Link>
        <p v-if="posting?.failure_classification" class="mt-2 text-rose-800">
            {{ posting.failure_classification }}
        </p>
    </div>
</template>
