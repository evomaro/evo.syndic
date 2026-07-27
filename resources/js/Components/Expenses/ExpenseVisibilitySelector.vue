<script setup lang="ts">
const value = defineModel<string>({ required: true });
</script>
<template>
    <div class="grid gap-1">
        <select
            v-model="value"
            class="w-full rounded-lg border-slate-300"
            aria-label="Visibilité résident"
        >
            <option value="private">Privé</option>
            <option value="category_summary">Résumé par catégorie</option>
            <option value="invoice_summary">Résumé de facture</option>
        </select>
        <p class="text-xs text-slate-500">
            {{
                value === "private"
                    ? "Aucun détail n’est exposé aux résidents."
                    : value === "category_summary"
                      ? "Seuls la catégorie et le montant agrégé sont visibles."
                      : "Le numéro, la catégorie et le montant sont visibles, jamais les pièces privées."
            }}
        </p>
    </div>
</template>
