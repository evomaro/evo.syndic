<script setup lang="ts">
import { useForm, Link, router } from "@inertiajs/vue3";
import { ref } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
const p = defineProps<{ residence?: any }>();
const { t } = useI18n();
const form = useForm<any>({
    name: p.residence?.name ?? "",
    code: p.residence?.code ?? "",
    description: p.residence?.description ?? "",
    address_line_1: p.residence?.address_line_1 ?? "",
    address_line_2: p.residence?.address_line_2 ?? "",
    city: p.residence?.city ?? "",
    postal_code: p.residence?.postal_code ?? "",
    default_language: p.residence?.default_language ?? "fr",
    fiscal_year_start_month: p.residence?.fiscal_year_start_month ?? 1,
    fiscal_year_start_day: p.residence?.fiscal_year_start_day ?? 1,
    logo: null as File | null,
});
const preview = ref<string | null>(p.residence?.logo_url ?? null);
const chooseLogo = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.logo = file;
    if (file) preview.value = URL.createObjectURL(file);
};
const submit = () => {
    if (p.residence) {
        form.transform((data: any) => ({ ...data, _method: "put" })).post(
            route("residences.update", p.residence.id),
            { forceFormData: true },
        );
    } else form.post(route("residences.store"), { forceFormData: true });
};
const removeLogo = () =>
    router.delete(route("residences.logo.destroy", p.residence.id), {
        onSuccess: () => (preview.value = null),
    });
</script>
<template>
    <AuthenticatedLayout :title="residence ? t('edit') : t('newResidence')"
        ><form
            class="panel mx-auto grid max-w-3xl gap-5 p-5 sm:grid-cols-2 sm:p-7"
            @submit.prevent="submit"
        >
            <label class="field"
                ><span class="field-label">{{ t("residenceName") }}</span
                ><input v-model="form.name" required /></label
            ><label class="field"
                ><span class="field-label">{{ t("code") }}</span
                ><input v-model="form.code" required /></label
            ><label class="field sm:col-span-2"
                ><span class="field-label">{{ t("description") }}</span
                ><textarea v-model="form.description" rows="3" /></label
            ><label class="field sm:col-span-2"
                ><span class="field-label">{{ t("address") }}</span
                ><input v-model="form.address_line_1" required /></label
            ><label class="field"
                ><span class="field-label">{{ t("city") }}</span
                ><input v-model="form.city" required /></label
            ><label class="field"
                ><span class="field-label">{{ t("language") }}</span
                ><select v-model="form.default_language">
                    <option value="fr">Français</option>
                    <option value="ar">العربية</option>
                </select></label
            >
            <div class="field sm:col-span-2">
                <span class="field-label">{{ t("logo") }}</span>
                <div class="flex flex-wrap items-center gap-4">
                    <img
                        v-if="preview"
                        :src="preview"
                        class="size-20 rounded-2xl border object-cover"
                        alt=""
                    />
                    <div
                        v-else
                        class="grid size-20 place-items-center rounded-2xl bg-teal-100 text-xl font-black text-teal-800"
                    >
                        {{ residence?.initials ?? "ES" }}
                    </div>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        @change="chooseLogo"
                    />
                    <button
                        v-if="residence?.logo_url"
                        type="button"
                        class="btn-secondary"
                        @click="removeLogo"
                    >
                        {{ t("removeLogo") }}
                    </button>
                </div>
                <p v-if="form.errors.logo" class="text-sm text-red-600">
                    {{ form.errors.logo }}
                </p>
            </div>
            <div class="flex gap-3 sm:col-span-2">
                <button class="btn-primary" :disabled="form.processing">
                    {{ t("save") }}</button
                ><Link
                    :href="route('residences.index')"
                    class="btn-secondary"
                    >{{ t("cancel") }}</Link
                >
            </div>
        </form></AuthenticatedLayout
    >
</template>
