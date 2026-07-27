<script setup lang="ts">
import { ref } from "vue";
withDefaults(
    defineProps<{
        title: string;
        message: string;
        busy?: boolean;
        destructive?: boolean;
        confirmationLabel?: string;
    }>(),
    { confirmationLabel: "J’ai compris les conséquences" },
);
const emit = defineEmits<{ confirm: [] }>();
const acknowledged = ref(false);
</script>
<template>
    <aside class="rounded-lg border border-amber-300 bg-amber-50 p-4">
        <b>{{ title }}</b>
        <p class="my-2 text-sm">{{ message }}</p>
        <label
            v-if="destructive"
            class="mb-3 flex min-h-11 items-center gap-2 text-sm"
            ><input v-model="acknowledged" type="checkbox" />
            {{ confirmationLabel }}</label
        >
        <button
            type="button"
            class="btn-primary"
            :disabled="busy || (destructive && !acknowledged)"
            @click="emit('confirm')"
        >
            {{ busy ? "Traitement…" : "Confirmer" }}
        </button>
    </aside>
</template>
