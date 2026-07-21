<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import { useI18n } from "@/i18n";
const p = defineProps<{
    schedules: any[];
    categories: any[];
    allocationKeys: any[];
}>();
const { t } = useI18n();
const form = useForm({
    name: "",
    frequency: "monthly",
    custom_interval_months: 2,
    starts_on: new Date().toISOString().slice(0, 10),
    ends_on: "",
    generation_day: 1,
    due_offset_days: 15,
    auto_validate: false,
    template: {
        title: "",
        lines: [
            {
                charge_category_id: p.categories[0]?.id ?? "",
                label: "",
                distribution_method: "allocation_key",
                allocation_key_id: p.allocationKeys[0]?.id ?? null,
                target_type: "all",
                amount: "",
            },
        ],
    },
});
</script>
<template>
    <AuthenticatedLayout :title="t('schedules')"
        ><FinanceNav />
        <div class="grid gap-5 xl:grid-cols-[380px_1fr]">
            <form
                class="panel grid h-fit gap-3 p-5"
                @submit.prevent="
                    form.post(route('fund-call-schedules.store'), {
                        onSuccess: () => form.reset(),
                    })
                "
            >
                <input
                    v-model="form.name"
                    :placeholder="t('name')"
                    required
                /><select v-model="form.frequency">
                    <option
                        v-for="x in [
                            'monthly',
                            'quarterly',
                            'semiannual',
                            'annual',
                            'custom',
                        ]"
                        :value="x"
                    >
                        {{ t(x) }}
                    </option></select
                ><input
                    v-if="form.frequency === 'custom'"
                    v-model="form.custom_interval_months"
                    type="number"
                    min="1"
                    max="120"
                    required
                /><input v-model="form.starts_on" type="date" required /><input
                    v-model="form.ends_on"
                    type="date"
                /><input
                    v-model="form.generation_day"
                    type="number"
                    min="1"
                    max="31"
                    required
                /><input
                    v-model="form.due_offset_days"
                    type="number"
                    min="0"
                    max="365"
                    required
                /><label class="flex min-h-11 items-center gap-2"
                    ><input v-model="form.auto_validate" type="checkbox" />{{
                        t("validateNow")
                    }}</label
                ><input
                    v-model="form.template.title"
                    :placeholder="t('title')"
                    required
                /><input
                    v-model="form.template.lines[0].label"
                    :placeholder="t('title')"
                    required
                /><select
                    v-model="form.template.lines[0].charge_category_id"
                    required
                >
                    <option v-for="category in categories" :value="category.id">
                        {{ category.name }}
                    </option></select
                ><input
                    v-model="form.template.lines[0].amount"
                    inputmode="decimal"
                    :placeholder="t('amount')"
                    required
                /><button class="btn-primary">{{ t("create") }}</button>
            </form>
            <div class="grid gap-3">
                <article
                    v-for="s in schedules"
                    class="panel flex items-center justify-between p-5"
                >
                    <div>
                        <b>{{ s.name }}</b>
                        <p class="text-sm text-slate-500">
                            {{ t(s.frequency) }} · {{ s.next_generation_on }}
                        </p>
                    </div>
                    <span class="badge">{{
                        t(s.active ? "activeStatus" : "inactiveStatus")
                    }}</span>
                </article>
            </div>
        </div></AuthenticatedLayout
    >
</template>
