<script setup lang="ts">
import { router, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
import SettlementAllocationPreview from "@/Components/Expenses/SettlementAllocationPreview.vue";
const props = defineProps<{ settlement: any }>();
const reversal = useForm({ reason: "" });
const money = (cents: number) =>
    new Intl.NumberFormat("fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(Number(cents || 0) / 100);
</script>
<template>
    <AuthenticatedLayout
        :title="settlement.number || 'Règlement'"
        subtitle="Affectation et justificatif"
        ><ExpenseNavigation />
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="panel p-5">
                <FinancialStatusBadge :status="settlement.status" />
                <p class="my-4 text-xl font-bold">
                    {{ money(settlement.amount_cents) }}
                </p>
                <SettlementAllocationPreview
                    :allocations="settlement.allocations || []"
                />
            </section>
            <section class="panel p-5">
                <h2 class="font-bold">Actions</h2>
                <button
                    v-if="settlement.status === 'draft'"
                    class="btn-primary mt-4"
                    @click="
                        router.post(
                            route(
                                'supplier-settlements.validate',
                                settlement.id,
                            ),
                            { mode: 'fifo' },
                        )
                    "
                >
                    Valider et affecter FIFO
                </button>
                <form
                    v-if="settlement.status === 'validated'"
                    class="mt-4 grid gap-2"
                    @submit.prevent="
                        reversal.post(
                            route(
                                'supplier-settlements.reverse',
                                settlement.id,
                            ),
                        )
                    "
                >
                    <label class="grid gap-1 text-sm"
                        >Motif de l’extourne<textarea
                            v-model="reversal.reason"
                            required
                            minlength="5"
                            class="rounded-lg border-slate-300"
                        ></textarea>
                    </label>
                    <p
                        v-if="reversal.errors.reason"
                        class="text-sm text-rose-700"
                    >
                        {{ reversal.errors.reason }}
                    </p>
                    <button
                        class="btn-secondary"
                        :disabled="reversal.processing"
                    >
                        {{ reversal.processing ? "Traitement…" : "Extourner" }}
                    </button>
                </form>
                <button
                    v-if="
                        settlement.status === 'validated' &&
                        !settlement.documents?.length
                    "
                    class="btn-secondary mt-4 ms-2"
                    @click="
                        router.post(
                            route(
                                'supplier-settlements.voucher.retry',
                                settlement.id,
                            ),
                        )
                    "
                >
                    Regénérer le justificatif
                </button>
            </section>
        </div></AuthenticatedLayout
    >
</template>
