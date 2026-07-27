<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import SupplierPicker from "@/Components/Expenses/SupplierPicker.vue";
import InvoiceLineEditor from "@/Components/Expenses/InvoiceLineEditor.vue";
import InvoiceTotals from "@/Components/Expenses/InvoiceTotals.vue";
import { useI18n } from "@/i18n";
defineProps<{ exercises: any[]; categories: any[]; residences: any[] }>();
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const form = useForm<any>({
    supplier_id: "",
    supplier_invoice_number: "",
    invoice_date: new Date().toISOString().slice(0, 10),
    due_date: "",
    idempotency_key: crypto.randomUUID(),
    lines: [
        {
            residence_id: "",
            financial_exercise_id: "",
            expense_category_id: "",
            description: "",
            quantity: "1.000",
            unit_price_cents: 0,
            tax_rate: "0.000",
            visibility: "private",
        },
    ],
});
</script>
<template>
    <AuthenticatedLayout
        :title="text('Nouvelle facture', 'فاتورة جديدة')"
        :subtitle="
            text(
                'Le total final est recalculé côté serveur',
                'يُعاد احتساب المجموع النهائي على الخادم',
            )
        "
        ><ExpenseNavigation />
        <form
            class="grid max-w-5xl gap-5"
            @submit.prevent="form.post(route('supplier-invoices.store'))"
        >
            <section class="panel grid gap-4 p-5 md:grid-cols-2">
                <SupplierPicker v-model="form.supplier_id" /><input
                    v-model="form.supplier_invoice_number"
                    class="rounded-lg border-slate-300"
                    :placeholder="
                        text('Numéro fournisseur', 'رقم فاتورة المورد')
                    "
                /><input
                    v-model="form.invoice_date"
                    type="date"
                    class="rounded-lg border-slate-300"
                /><input
                    v-model="form.due_date"
                    type="date"
                    class="rounded-lg border-slate-300"
                />
            </section>
            <InvoiceLineEditor
                v-model="form.lines"
                :categories="categories"
                :exercises="exercises"
                :residences="residences"
            /><InvoiceTotals :lines="form.lines" />
            <p
                v-if="Object.keys(form.errors).length"
                class="text-sm text-rose-700"
            >
                {{
                    text(
                        "La facture n’a pas été envoyée; corrigez les champs, toutes les lignes sont conservées.",
                        "لم تُرسل الفاتورة؛ صحح الحقول، وتم الاحتفاظ بجميع السطور.",
                    )
                }}
            </p>
            <button class="btn-primary" :disabled="form.processing">
                {{ text("Enregistrer le brouillon", "حفظ المسودة") }}
            </button>
        </form></AuthenticatedLayout
    >
</template>
