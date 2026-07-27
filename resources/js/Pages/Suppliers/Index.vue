<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { ref } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import Pagination from "@/Components/Pagination.vue";
defineProps<{ suppliers: any; filters: any }>();
const q = ref("");
const search = () =>
    router.get(
        route("suppliers.index"),
        { q: q.value },
        { preserveState: true, replace: true },
    );
</script>
<template>
    <AuthenticatedLayout
        title="Fournisseurs"
        subtitle="Répertoire et données contractuelles"
        ><ExpenseNavigation />
        <div class="mb-4 flex gap-2">
            <input
                v-model="q"
                class="min-h-11 flex-1 rounded-lg border-slate-300"
                placeholder="Rechercher"
                @keyup.enter="search"
            /><button class="btn-secondary" @click="search">Rechercher</button
            ><Link :href="route('suppliers.create')" class="btn-primary"
                >Ajouter</Link
            >
        </div>
        <div class="panel divide-y">
            <Link
                v-for="s in suppliers.data"
                :key="s.id"
                :href="route('suppliers.show', s.id)"
                class="flex min-h-14 items-center justify-between p-4 hover:bg-slate-50"
                ><b>{{ s.legal_name }}</b
                ><span>{{ s.status }}</span></Link
            >
            <p
                v-if="!suppliers.data.length"
                class="p-8 text-center text-slate-500"
            >
                Aucun fournisseur.
            </p>
        </div>
        <Pagination :links="suppliers.links"
    /></AuthenticatedLayout>
</template>
