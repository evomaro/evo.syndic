<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
import FinancialConfirmationPanel from "@/Components/Expenses/FinancialConfirmationPanel.vue";
import AccountingPostingStatus from "@/Components/AccountingPostingStatus.vue";
import { formatMADCents as money } from "@/Support/money";
const props = defineProps<{ invoice: any; accountingPosting: any }>();
const attachment = ref<File | null>(null);
const kind = ref("original");
const hasOriginal = computed(() =>
    props.invoice.attachments?.some((item: any) => item.kind === "original"),
);
const upload = () => {
    if (!attachment.value) return;
    const data = new FormData();
    data.append("file", attachment.value);
    data.append("kind", kind.value);
    router.post(
        route("supplier-invoices.attachments.store", props.invoice.id),
        data,
        {
            forceFormData: true,
        },
    );
};
</script>
<template>
    <AuthenticatedLayout
        :title="invoice.number || invoice.supplier_invoice_number || 'Facture'"
        subtitle="Détail par résidence"
        ><ExpenseNavigation />
        <div class="grid gap-5 lg:grid-cols-[1fr_340px]">
            <section class="panel p-5">
                <AccountingPostingStatus
                    :posting="accountingPosting"
                    class="mb-4"
                />
                <FinancialStatusBadge :status="invoice.status" />
                <div
                    v-for="line in invoice.lines"
                    :key="line.id"
                    class="mt-4 flex justify-between border-b pb-3"
                >
                    <span
                        >{{ line.description
                        }}<small class="block">{{
                            line.category?.name
                        }}</small></span
                    ><b>{{ money(line.total_cents) }}</b>
                </div>
                <div class="mt-5 border-t pt-4">
                    <h2 class="mb-3 font-bold">Pièces privées</h2>
                    <p
                        v-if="!invoice.attachments?.length"
                        class="mb-3 text-sm text-slate-500"
                    >
                        La facture originale est obligatoire avant validation.
                    </p>
                    <a
                        v-for="item in invoice.attachments"
                        :key="item.id"
                        :href="
                            route(
                                'supplier-invoices.attachments.download',
                                item.id,
                            )
                        "
                        class="mb-2 block rounded-lg bg-slate-50 p-3 text-teal-700"
                        ><b>{{ item.name }}</b
                        ><small class="block text-slate-500"
                            >{{ item.kind }} · v{{ item.version }}</small
                        ></a
                    >
                    <div
                        v-if="invoice.status === 'draft'"
                        class="grid gap-2 rounded-lg border border-dashed border-slate-300 p-3 md:grid-cols-[1fr_12rem_auto]"
                    >
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                            class="rounded-lg border-slate-300 text-sm"
                            aria-label="Pièce de facture"
                            @change="
                                (event: any) =>
                                    (attachment =
                                        event.target.files?.[0] || null)
                            "
                        />
                        <select
                            v-model="kind"
                            class="rounded-lg border-slate-300"
                            aria-label="Type de pièce"
                        >
                            <option value="original">Originale</option>
                            <option value="supporting">Justificatif</option>
                        </select>
                        <button
                            type="button"
                            class="btn-secondary"
                            :disabled="!attachment"
                            @click="upload"
                        >
                            Téléverser
                        </button>
                    </div>
                </div>
            </section>
            <FinancialConfirmationPanel
                v-if="invoice.status === 'draft'"
                title="Validation financière"
                :message="
                    hasOriginal
                        ? 'La validation verrouille les montants et crée la dette fournisseur.'
                        : 'Ajoutez d’abord la facture originale privée.'
                "
                :busy="!hasOriginal"
                @confirm="
                    router.post(route('supplier-invoices.validate', invoice.id))
                "
            /></div
    ></AuthenticatedLayout>
</template>
