<script setup lang="ts">
import { computed } from "vue";
const props = withDefaults(
    defineProps<{
        id?: string;
        label?: string;
        error?: string;
        min?: number;
    }>(),
    { min: 0 },
);
const cents = defineModel<number>({ required: true });
const amount = computed({
    get: () => (Number(cents.value || 0) / 100).toFixed(2),
    set: (value: string) => {
        cents.value = Math.round(Number(value || 0) * 100);
    },
});
</script>
<template>
    <div>
        <label v-if="label" :for="id" class="mb-1 block text-sm font-medium">{{
            label
        }}</label>
        <div class="relative">
            <input
                :id="id"
                v-model="amount"
                type="number"
                :min="min"
                step="0.01"
                inputmode="decimal"
                :aria-invalid="Boolean(error)"
                :aria-describedby="error && id ? `${id}-error` : undefined"
                class="w-full rounded-lg border-slate-300 pe-14"
            /><span
                class="pointer-events-none absolute end-3 top-2.5 text-sm text-slate-500"
                >MAD</span
            >
        </div>
        <p
            v-if="error"
            :id="id ? `${id}-error` : undefined"
            class="mt-1 text-sm text-rose-700"
        >
            {{ error }}
        </p>
    </div>
</template>
