<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import { useI18n } from "@/i18n";
defineProps<{ categories: any[]; allocationKeys: any[] }>();
const { t } = useI18n();
const form = useForm({
    name: "",
    code: "",
    description: "",
    type: "ordinary",
    default_distribution_method: "allocation_key",
    default_allocation_key_id: null as number | null,
    active: true,
    sort_order: 0,
});
</script>
<template>
    <AuthenticatedLayout :title="t('chargeCategories')"
        ><template #actions
            ><button
                class="btn-secondary"
                @click="useForm({}).post(route('charge-categories.seed'))"
            >
                {{ t("add") }}
            </button></template
        ><FinanceNav />
        <div class="grid gap-5 xl:grid-cols-[380px_1fr]">
            <form
                class="panel grid h-fit gap-3 p-5"
                @submit.prevent="
                    form.post(route('charge-categories.store'), {
                        onSuccess: () => form.reset(),
                    })
                "
            >
                <input
                    v-model="form.name"
                    :placeholder="t('name')"
                    required
                /><input
                    v-model="form.code"
                    :placeholder="t('code')"
                    required
                /><select v-model="form.type">
                    <option value="ordinary">{{ t("ordinary") }}</option>
                    <option value="exceptional">
                        {{ t("exceptional") }}
                    </option></select
                ><select v-model="form.default_distribution_method">
                    <option
                        v-for="m in [
                            'allocation_key',
                            'equal',
                            'fixed',
                            'manual',
                        ]"
                        :value="m"
                    >
                        {{ t(m) }}
                    </option></select
                ><select
                    v-if="form.default_distribution_method === 'allocation_key'"
                    v-model="form.default_allocation_key_id"
                >
                    <option :value="null">—</option>
                    <option v-for="k in allocationKeys" :value="k.id">
                        {{ k.name }}
                    </option></select
                ><button class="btn-primary">{{ t("create") }}</button>
            </form>
            <div class="grid gap-3">
                <article v-for="c in categories" class="panel p-5">
                    <div class="flex justify-between">
                        <div>
                            <h2 class="font-bold">{{ c.name }}</h2>
                            <p class="text-sm text-slate-500">
                                {{ c.code }} · {{ t(c.type) }} ·
                                {{ t(c.default_distribution_method) }}
                            </p>
                        </div>
                        <span class="badge">{{
                            t(c.active ? "activeStatus" : "inactiveStatus")
                        }}</span>
                    </div>
                </article>
            </div>
        </div></AuthenticatedLayout
    >
</template>
