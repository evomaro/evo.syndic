<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
defineProps<{ supplier: any }>();
</script>
<template>
    <AuthenticatedLayout
        :title="supplier.legal_name"
        subtitle="Fiche fournisseur"
        ><ExpenseNavigation />
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="panel p-5">
                <div class="flex items-center justify-between gap-2">
                    <FinancialStatusBadge :status="supplier.status" /><a
                        :href="route('suppliers.edit', supplier.id)"
                        class="btn-secondary"
                        >Modifier</a
                    >
                </div>
                <dl class="mt-4 grid gap-2 text-sm">
                    <div>
                        <dt>E-mail</dt>
                        <dd class="font-semibold">
                            {{ supplier.email || "—" }}
                        </dd>
                    </div>
                    <div>
                        <dt>Téléphone</dt>
                        <dd class="font-semibold">
                            {{ supplier.phone || "—" }}
                        </dd>
                    </div>
                    <div v-if="supplier.iban">
                        <dt>IBAN privé</dt>
                        <dd class="font-semibold">{{ supplier.iban }}</dd>
                    </div>
                </dl>
            </section>
            <section class="panel p-5">
                <h2 class="font-bold">Contrats de la résidence</h2>
                <p v-for="c in supplier.contracts" :key="c.id" class="mt-3">
                    {{ c.title }} · {{ c.status }}
                </p>
                <p
                    v-if="!supplier.contracts?.length"
                    class="mt-3 text-slate-500"
                >
                    Aucun contrat.
                </p>
            </section>
        </div></AuthenticatedLayout
    >
</template>
