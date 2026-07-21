<script setup lang="ts">
import GuestLayout from "@/Layouts/GuestLayout.vue";
import { useI18n } from "@/i18n";
defineProps<{ receipt: any | null }>();
const { t, locale } = useI18n();
const money = (c: number) =>
    new Intl.NumberFormat(locale.value === "ar" ? "ar-MA" : "fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(c / 100);
</script>
<template>
    <GuestLayout
        ><div class="mx-auto max-w-lg p-5">
            <div class="panel overflow-hidden">
                <div class="bg-slate-950 p-6 text-white">
                    <p class="text-sm font-bold text-teal-300">EVOSYNDIC</p>
                    <h1 class="mt-2 text-2xl font-bold">{{ t("receipt") }}</h1>
                </div>
                <dl v-if="receipt" class="grid gap-4 p-6">
                    <div>
                        <dt class="text-xs text-slate-500">
                            {{ t("reference") }}
                        </dt>
                        <dd class="font-bold">{{ receipt.number }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">
                            {{ t("residence") }}
                        </dt>
                        <dd>{{ receipt.issuer }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">
                            {{ t("amount") }}
                        </dt>
                        <dd class="text-xl font-bold">
                            {{ money(receipt.amount_cents) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">
                            {{ t("status") }}
                        </dt>
                        <dd class="badge mt-1">{{ t(receipt.status) }}</dd>
                    </div>
                    <div
                        :class="
                            receipt.integrity
                                ? 'bg-emerald-50 text-emerald-800'
                                : 'bg-red-50 text-red-800'
                        "
                        class="rounded-xl p-4 font-semibold"
                    >
                        {{
                            receipt.integrity
                                ? t("integrityValid")
                                : t("integrityInvalid")
                        }}
                    </div>
                </dl>
                <p v-else class="p-6 text-sm text-slate-600">
                    {{ t("noResults") }}
                </p>
            </div>
        </div></GuestLayout
    >
</template>
