<script setup lang="ts">
import { Link } from "@inertiajs/vue3";

withDefaults(
    defineProps<{
        title: string;
        message?: string;
        primaryLabel: string;
        primaryHref: string;
        secondaryLabel?: string;
        secondaryHref?: string;
    }>(),
    {
        message: "",
        secondaryLabel: "",
        secondaryHref: "",
    },
);
</script>

<template>
    <div
        class="panel flex flex-col items-center px-6 py-10 text-center"
        role="status"
    >
        <div
            v-if="$slots.icon"
            class="mb-4 grid size-12 place-items-center rounded-full bg-teal-50 text-xl text-teal-700"
            aria-hidden="true"
        >
            <slot name="icon" />
        </div>
        <h2 class="text-lg font-bold text-slate-950">{{ title }}</h2>
        <p v-if="message" class="mt-2 max-w-xl text-sm text-slate-500">
            {{ message }}
        </p>
        <div class="mt-5 flex flex-wrap justify-center gap-3">
            <Link :href="primaryHref" class="btn-primary">
                {{ primaryLabel }}
            </Link>
            <Link
                v-if="secondaryLabel && secondaryHref"
                :href="secondaryHref"
                class="btn-secondary"
            >
                {{ secondaryLabel }}
            </Link>
        </div>
    </div>
</template>
