<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { reactive } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import Pagination from "@/Components/Pagination.vue";
import { useI18n } from "@/i18n";
const p = defineProps<{ charges: any; buildings: any[]; filters: any }>();
const { t, locale } = useI18n();
const f = reactive({ ...p.filters });
const money = (c: number) =>
    new Intl.NumberFormat(locale.value === "ar" ? "ar-MA" : "fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(c / 100);
</script>
<template>
    <AuthenticatedLayout :title="t('outstanding')"
        ><template #actions
            ><Link
                :href="route('finance.outstanding.export', filters)"
                class="btn-secondary"
                >{{ t("export") }} CSV</Link
            ></template
        ><FinanceNav />
        <form
            class="panel mb-4 grid gap-3 p-4 sm:grid-cols-4"
            @submit.prevent="
                router.get(route('finance.outstanding'), f, {
                    preserveState: true,
                })
            "
        >
            <select v-model="f.building">
                <option value="">{{ t("buildings") }}</option>
                <option v-for="b in buildings" :value="b.id">
                    {{ b.name }}
                </option></select
            ><input
                v-model="f.minimum"
                inputmode="decimal"
                :placeholder="t('amount')"
            /><label class="flex min-h-11 items-center gap-2"
                ><input v-model="f.overdue" type="checkbox" />{{
                    t("overdueAmount")
                }}</label
            ><button class="btn-secondary">{{ t("filters") }}</button>
        </form>
        <div class="grid gap-3">
            <article
                v-for="c in charges.data"
                class="panel grid gap-2 p-4 sm:grid-cols-[1fr_auto_auto]"
            >
                <div>
                    <b
                        >{{ c.lot.reference }} ·
                        {{ c.contact_name_snapshot || "—" }}</b
                    >
                    <p class="text-sm text-slate-500">
                        {{ c.fund_call.number }} · {{ t("dueDate") }}
                        {{ c.due_date }}
                    </p>
                </div>
                <span class="badge self-center"
                    >{{ t("aging") }} {{ c.aging }}</span
                ><b class="self-center text-lg">{{
                    money(c.outstanding_cents)
                }}</b>
            </article>
        </div>
        <Pagination :links="charges.links"
    /></AuthenticatedLayout>
</template>
