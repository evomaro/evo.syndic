<script setup lang="ts">
import { computed, ref } from "vue";
const value = defineModel<number | string | null>();
const search = ref("");
const props = defineProps<{ commitments: any[] }>();
const filtered = computed(() =>
    props.commitments.filter((item) =>
        `${item.title} ${item.reference || ""}`
            .toLowerCase()
            .includes(search.value.toLowerCase()),
    ),
);
const money = (cents: number) =>
    new Intl.NumberFormat("fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(Number(cents || 0) / 100);
</script>
<template>
    <div class="grid gap-2">
        <input
            v-if="commitments.length > 8"
            v-model="search"
            type="search"
            class="w-full rounded-lg border-slate-300"
            placeholder="Rechercher un engagement"
        /><select v-model="value" class="w-full rounded-lg border-slate-300">
            <option value="">Sans engagement</option>
            <option v-for="item in filtered" :key="item.id" :value="item.id">
                {{ item.reference ? `${item.reference} · ` : ""
                }}{{ item.title }} · {{ money(item.amount_cents) }} ·
                {{ item.status }}
            </option>
        </select>
        <p v-if="value" class="text-xs text-slate-500">
            La cohérence résidence, exercice et catégorie sera vérifiée à
            l’enregistrement.
        </p>
    </div>
</template>
