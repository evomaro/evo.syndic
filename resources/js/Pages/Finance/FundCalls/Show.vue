<script setup lang="ts">
import { Link, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FinanceNav from "@/Components/FinanceNav.vue";
import AccountingPostingStatus from "@/Components/AccountingPostingStatus.vue";
import { useI18n } from "@/i18n";
import { formatMADCents as money } from "@/Support/money";
const p = defineProps<{ call: any; preview: any[]; accountingPosting: any }>();
const { t, locale } = useI18n();
const reason = ref("");
const cancelForm = useForm({ reason: "" });
const validateCall = () =>
    useForm({}).post(route("fund-calls.validate", p.call.id));
const cancelCall = () => {
    if (window.confirm(t("confirm"))) {
        cancelForm.reason = reason.value;
        cancelForm.post(route("fund-calls.cancel", p.call.id));
    }
};
</script>
<template>
    <AuthenticatedLayout
        :title="call.number || t('draft')"
        :subtitle="call.title"
        ><template #actions
            ><Link
                v-if="call.status === 'draft'"
                :href="route('fund-calls.edit', call.id)"
                class="btn-secondary"
                >{{ t("edit") }}</Link
            ><Link
                v-else-if="call.status !== 'cancelled'"
                :href="route('fund-calls.pdf', call.id)"
                class="btn-secondary"
                >{{ t("download") }} PDF</Link
            ></template
        ><FinanceNav />
        <div class="grid gap-5 xl:grid-cols-[1fr_340px]">
            <section class="panel overflow-hidden">
                <div class="panel-head">
                    <div>
                        <b>{{ t("preview") }}</b>
                        <p class="text-sm text-slate-500">
                            {{ call.issue_date }} → {{ call.due_date }}
                        </p>
                    </div>
                    <span class="badge">{{ t(call.status) }}</span>
                </div>
                <div v-if="call.status === 'draft'" class="divide-y">
                    <div v-for="line in preview" class="p-5">
                        <div class="mb-3 flex justify-between">
                            <b>{{ line.label }}</b
                            ><b>{{ money(line.total_cents) }}</b>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            <div
                                v-for="a in line.allocations"
                                class="flex justify-between rounded-lg bg-slate-50 p-2 text-sm"
                            >
                                <span>{{ a.lot }}</span
                                ><b>{{ money(a.amount_cents) }}</b>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="divide-y">
                    <div
                        v-for="charge in call.charges"
                        class="flex min-h-14 items-center justify-between px-5"
                    >
                        <span
                            >{{ charge.lot_reference_snapshot }} ·
                            {{ charge.contact_name_snapshot || "—" }}</span
                        ><b>{{ money(charge.amount_cents) }}</b>
                    </div>
                </div>
            </section>
            <aside class="space-y-3">
                <AccountingPostingStatus :posting="accountingPosting" />
                <div class="panel p-5">
                    <p class="text-sm text-slate-500">
                        {{ t("amountCalled") }}
                    </p>
                    <p class="mt-1 text-2xl font-bold">
                        {{
                            money(
                                call.status === "draft"
                                    ? preview.reduce(
                                          (s, l) => s + l.total_cents,
                                          0,
                                      )
                                    : call.total_cents,
                            )
                        }}
                    </p>
                </div>
                <button
                    v-if="call.status === 'draft'"
                    class="btn-primary w-full"
                    @click="validateCall"
                >
                    {{ t("validate") }}
                </button>
                <form
                    v-if="['validated', 'closed'].includes(call.status)"
                    class="panel grid gap-3 p-4"
                    @submit.prevent="cancelCall"
                >
                    <textarea
                        v-model="reason"
                        :placeholder="t('reason')"
                        required
                    /><button class="btn-secondary">{{ t("cancel") }}</button>
                    <p
                        v-for="error in cancelForm.errors"
                        class="text-sm text-red-600"
                    >
                        {{ error }}
                    </p>
                </form>
            </aside>
        </div></AuthenticatedLayout
    >
</template>
