<script setup lang="ts">
import { ref } from "vue";
import { Link, router, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
import Pagination from "@/Components/Pagination.vue";
const p = defineProps<{ contacts: any; filters: any }>();
const { t } = useI18n();
const open = ref(false);
const search = ref(p.filters.search ?? "");
const form = useForm({
    type: "individual",
    first_name: "",
    last_name: "",
    company_name: "",
    cin: "",
    passport_number: "",
    ice: "",
    primary_email: "",
    primary_phone: "",
    whatsapp_phone: "",
    address: "",
    city: "",
    preferred_language: "fr",
    notification_channel: "none",
    notes: "",
    active: true,
    confirm_duplicate: false,
});
</script>
<template>
    <AuthenticatedLayout :title="t('contacts')"
        ><template #actions
            ><button class="btn-primary" @click="open = true">
                ＋ {{ t("newContact") }}
            </button></template
        >
        <div class="panel mb-5 flex gap-3 p-3">
            <input
                v-model="search"
                class="flex-1"
                :placeholder="t('search')"
                @keyup.enter="router.get(route('contacts.index'), { search })"
            /><button
                class="btn-secondary"
                @click="router.get(route('contacts.index'), { search })"
            >
                {{ t("search") }}
            </button>
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="c in contacts.data"
                :key="c.id"
                :href="route('contacts.show', c.id)"
                class="panel p-5 hover:border-teal-300"
                ><div class="flex items-center gap-4">
                    <div
                        class="grid size-12 place-items-center rounded-full bg-teal-50 font-bold text-teal-800"
                    >
                        {{
                            c.type === "company"
                                ? "C"
                                : (c.first_name?.[0] || "") +
                                  (c.last_name?.[0] || "")
                        }}
                    </div>
                    <div class="min-w-0">
                        <h2 class="truncate font-bold">{{ c.display_name }}</h2>
                        <p class="truncate text-sm text-slate-500">
                            {{ c.primary_phone || c.primary_email || "—" }}
                        </p>
                    </div>
                </div>
                <div class="mt-4 flex gap-2">
                    <span class="badge border-slate-200">{{ t(c.type) }}</span
                    ><span
                        v-if="!c.active"
                        class="badge border-rose-200 text-rose-700"
                        >{{ t("archived") }}</span
                    >
                </div></Link
            >
        </div>
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-end bg-slate-950/50 sm:items-center sm:justify-center"
            @click.self="open = false"
        >
            <form
                class="max-h-[95vh] w-full overflow-y-auto rounded-t-3xl bg-white p-6 sm:max-w-2xl sm:rounded-2xl"
                @submit.prevent="
                    form.post(route('contacts.store'), {
                        onSuccess: () => (open = false),
                    })
                "
            >
                <div class="mb-5 flex justify-between">
                    <h2 class="text-xl font-bold">{{ t("newContact") }}</h2>
                    <button type="button" class="size-11" @click="open = false">
                        ×
                    </button>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="field"
                        ><span class="field-label">{{ t("type") }}</span
                        ><select v-model="form.type">
                            <option value="individual">
                                {{ t("individual") }}
                            </option>
                            <option value="company">{{ t("company") }}</option>
                        </select></label
                    ><template v-if="form.type === 'individual'"
                        ><label class="field"
                            ><span class="field-label">{{
                                t("firstName")
                            }}</span
                            ><input v-model="form.first_name" required /></label
                        ><label class="field"
                            ><span class="field-label">{{ t("lastName") }}</span
                            ><input
                                v-model="form.last_name"
                                required /></label></template
                    ><label v-else class="field"
                        ><span class="field-label">{{ t("companyName") }}</span
                        ><input v-model="form.company_name" required /></label
                    ><label class="field"
                        ><span class="field-label">{{ t("phone") }}</span
                        ><input
                            v-model="form.primary_phone"
                            inputmode="tel" /></label
                    ><label class="field"
                        ><span class="field-label">{{ t("email") }}</span
                        ><input
                            v-model="form.primary_email"
                            type="email" /></label
                    ><label class="field"
                        ><span class="field-label">{{ t("cin") }}</span
                        ><input v-model="form.cin" /></label
                    ><button class="btn-primary self-end">
                        {{ t("save") }}
                    </button>
                </div>
                <p
                    v-if="(form.errors as any).duplicate"
                    class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800"
                >
                    ! {{ t("possibleDuplicate") }}
                </p>
            </form>
        </div>
        <Pagination :links="contacts.links"
    /></AuthenticatedLayout>
</template>
