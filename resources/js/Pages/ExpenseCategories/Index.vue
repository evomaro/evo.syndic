<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import Pagination from "@/Components/Pagination.vue";
defineProps<{ categories: any }>();
const form = useForm({
    name: "",
    code: "",
    type: "ordinary",
    default_visibility: "private",
});
</script>
<template>
    <AuthenticatedLayout
        title="Catégories de dépenses"
        subtitle="Classement budgétaire par résidence"
        ><ExpenseNavigation />
        <div class="grid gap-5 lg:grid-cols-[1fr_360px]">
            <div class="panel divide-y">
                <div v-for="c in categories.data" :key="c.id" class="p-4">
                    <b>{{ c.name }}</b
                    ><small class="ms-2">{{ c.code }}</small>
                </div>
                <p
                    v-if="!categories.data.length"
                    class="p-8 text-center text-slate-500"
                >
                    Aucune catégorie.
                </p>
                <Pagination :links="categories.links" />
            </div>
            <form
                class="panel grid h-fit gap-3 p-5"
                @submit.prevent="
                    form.post(route('expense-categories.store'), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    })
                "
            >
                <h2 class="font-bold">Ajouter</h2>
                <input
                    v-model="form.name"
                    class="rounded-lg border-slate-300"
                    placeholder="Nom"
                /><input
                    v-model="form.code"
                    class="rounded-lg border-slate-300"
                    placeholder="Code"
                /><select
                    v-model="form.type"
                    class="rounded-lg border-slate-300"
                >
                    <option value="ordinary">Ordinaire</option>
                    <option value="exceptional">Exceptionnelle</option></select
                ><button class="btn-primary">Enregistrer</button>
            </form>
        </div></AuthenticatedLayout
    >
</template>
