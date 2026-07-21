<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
defineProps<{ contact: any }>();
const { t } = useI18n();
</script>
<template>
    <AuthenticatedLayout :title="contact.display_name"
        ><template #actions
            ><Link :href="route('contacts.index')" class="btn-secondary"
                >← {{ t("back") }}</Link
            ></template
        >
        <div class="grid gap-5 lg:grid-cols-[320px_1fr]">
            <aside class="panel p-5">
                <div
                    class="grid size-16 place-items-center rounded-2xl bg-teal-50 text-xl font-bold text-teal-800"
                >
                    {{
                        contact.type === "company"
                            ? "C"
                            : contact.first_name?.[0] + contact.last_name?.[0]
                    }}
                </div>
                <dl class="mt-6 space-y-4 text-sm">
                    <div>
                        <dt class="text-slate-500">{{ t("phone") }}</dt>
                        <dd class="font-semibold">
                            {{ contact.primary_phone || "—" }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ t("email") }}</dt>
                        <dd class="font-semibold">
                            {{ contact.primary_email || "—" }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ t("city") }}</dt>
                        <dd class="font-semibold">{{ contact.city || "—" }}</dd>
                    </div>
                </dl>
            </aside>
            <section class="panel overflow-hidden">
                <div class="panel-head">
                    <h2 class="font-bold">{{ t("relatedLots") }}</h2>
                </div>
                <div class="divide-y divide-slate-100">
                    <div
                        v-for="o in contact.ownerships"
                        :key="'o' + o.id"
                        class="flex items-center justify-between p-4"
                    >
                        <div>
                            <p class="font-semibold">
                                {{ o.lot.reference }} ·
                                {{ o.lot.residence.name }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ t("owners") }} ·
                                {{ o.ownership_percentage }}%
                            </p>
                        </div>
                        <span class="badge border-slate-200">{{
                            o.ends_on ? t("history") : t("active")
                        }}</span>
                    </div>
                    <div
                        v-for="o in contact.occupancies"
                        :key="'x' + o.id"
                        class="flex items-center justify-between p-4"
                    >
                        <div>
                            <p class="font-semibold">
                                {{ o.lot.reference }} ·
                                {{ o.lot.residence.name }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ t("occupants") }} · {{ o.type }}
                            </p>
                        </div>
                        <span class="badge border-slate-200">{{
                            o.ends_on ? t("history") : t("active")
                        }}</span>
                    </div>
                    <p
                        v-if="
                            !contact.ownerships.length &&
                            !contact.occupancies.length
                        "
                        class="p-8 text-center text-sm text-slate-500"
                    >
                        {{ t("noResults") }}
                    </p>
                </div>
            </section>
        </div></AuthenticatedLayout
    >
</template>
