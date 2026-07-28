<script setup lang="ts">
import { router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
import CreditAllocationEditor from "@/Components/Expenses/CreditAllocationEditor.vue";
import AccountingPostingStatus from "@/Components/AccountingPostingStatus.vue";
import { formatMADCents as money } from "@/Support/money";
const props = defineProps<{
    creditNote: any;
    openInvoices: any[];
    accountingPosting: any;
}>();
const allocations = ref<any[]>([
    { supplier_invoice_id: "", amount_cents: props.creditNote.amount_cents },
]);
const cancellation = useForm({ reason: "" });
</script>
<template>
    <AuthenticatedLayout
        :title="creditNote.number || 'Avoir'"
        subtitle="Affectations"
        ><ExpenseNavigation />
        <section class="panel max-w-3xl p-5">
            <AccountingPostingStatus
                class="mb-4"
                :posting="accountingPosting"
            />
            <div class="flex flex-wrap items-center justify-between gap-2">
                <FinancialStatusBadge :status="creditNote.status" />
                <b>{{ money(creditNote.amount_cents) }}</b>
            </div>
            <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                <div>
                    <dt class="text-slate-500">Fournisseur</dt>
                    <dd class="font-medium">
                        {{ creditNote.supplier.legal_name }}
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Date de l’avoir</dt>
                    <dd>
                        {{
                            new Date(creditNote.credit_date).toLocaleDateString(
                                "fr-MA",
                            )
                        }}
                    </dd>
                </div>
                <div class="md:col-span-2">
                    <dt class="text-slate-500">Motif</dt>
                    <dd>{{ creditNote.reason || "—" }}</dd>
                </div>
            </dl>
            <CreditAllocationEditor
                v-if="creditNote.status === 'draft'"
                v-model="allocations"
                :invoices="openInvoices"
                :credit-amount="creditNote.amount_cents"
                class="my-5"
            /><button
                v-if="creditNote.status === 'draft'"
                class="btn-primary"
                :disabled="!openInvoices.length"
                @click="
                    router.post(
                        route('supplier-credit-notes.validate', creditNote.id),
                        { allocations },
                    )
                "
            >
                Valider les affectations
            </button>
            <div
                v-if="creditNote.allocations?.length"
                class="mt-5 border-t pt-4"
            >
                <h2 class="mb-3 font-bold">Affectations verrouillées</h2>
                <div
                    v-for="row in creditNote.allocations"
                    :key="row.id"
                    class="flex justify-between gap-3 rounded-lg bg-slate-50 p-3 text-sm"
                >
                    <span
                        >{{
                            row.invoice?.number ||
                            row.invoice?.supplier_invoice_number ||
                            `Facture #${row.supplier_invoice_id}`
                        }}<small
                            v-if="row.line?.description"
                            class="block text-slate-500"
                            >{{ row.line.description }}</small
                        ></span
                    ><b>{{ money(row.amount_cents) }}</b>
                </div>
            </div>
            <form
                v-if="creditNote.status === 'validated'"
                class="mt-5 grid gap-3 border-t pt-4"
                @submit.prevent="
                    cancellation.post(
                        route('supplier-credit-notes.cancel', creditNote.id),
                    )
                "
            >
                <h2 class="font-bold">Annuler l’avoir</h2>
                <p class="text-sm text-slate-600">
                    L’annulation extourne les affectations; l’historique demeure
                    auditable.
                </p>
                <textarea
                    v-model="cancellation.reason"
                    required
                    minlength="5"
                    class="rounded-lg border-slate-300"
                    placeholder="Motif d’annulation"
                ></textarea>
                <p
                    v-if="cancellation.errors.reason"
                    class="text-sm text-rose-700"
                >
                    {{ cancellation.errors.reason }}
                </p>
                <button
                    class="btn-secondary"
                    :disabled="cancellation.processing"
                >
                    {{
                        cancellation.processing
                            ? "Traitement…"
                            : "Annuler avec motif"
                    }}
                </button>
            </form>
        </section></AuthenticatedLayout
    >
</template>
