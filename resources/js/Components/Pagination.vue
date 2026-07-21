<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
const props = defineProps<{
    links?: Array<{ url: string | null; label: string; active: boolean }>;
}>();
const label = (value: string) =>
    value.includes("Previous") ? "‹" : value.includes("Next") ? "›" : value;
</script>
<template>
    <nav
        v-if="props.links && props.links.length > 3"
        class="flex flex-wrap justify-center gap-1 p-4"
        aria-label="Pagination"
    >
        <Link
            v-for="link in props.links"
            :key="link.label"
            :href="link.url || ''"
            :class="[
                link.active
                    ? 'bg-slate-950 text-white'
                    : 'bg-white text-slate-700',
                !link.url ? 'pointer-events-none opacity-40' : '',
            ]"
            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl border border-slate-200 px-3"
            preserve-scroll
            >{{ label(link.label) }}</Link
        >
    </nav>
</template>
