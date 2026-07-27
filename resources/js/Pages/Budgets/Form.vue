<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import BudgetLineEditor from "@/Components/Expenses/BudgetLineEditor.vue";
defineProps<{ exercises: any[]; categories: any[] }>();
const form = useForm<any>({
    financial_exercise_id: "",
    title: "",
    notes: "",
    lines: [{ expense_category_id: "", planned_cents: 0, description: "" }],
});
</script>
<template>
    <AuthenticatedLayout title="Nouveau budget" subtitle="Version de travail"
        ><ExpenseNavigation />
        <form
            class="grid max-w-4xl gap-5"
            @submit.prevent="form.post(route('budgets.store'))"
        >
            <section class="panel grid gap-4 p-5 md:grid-cols-2">
                <select
                    v-model="form.financial_exercise_id"
                    class="rounded-lg border-slate-300"
                >
                    <option value="">Exercice</option>
                    <option v-for="e in exercises" :key="e.id" :value="e.id">
                        {{ e.name }}
                    </option></select
                ><input
                    v-model="form.title"
                    class="rounded-lg border-slate-300"
                    placeholder="Titre"
                />
            </section>
            <BudgetLineEditor
                v-model="form.lines"
                :categories="categories"
            /><button class="btn-primary">Enregistrer le brouillon</button>
        </form></AuthenticatedLayout
    >
</template>
