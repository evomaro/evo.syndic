<script setup lang="ts">
defineProps<{ status: string }>();
</script>
<template>
    <span
        class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700"
        :class="{
            '!bg-emerald-100 !text-emerald-800': [
                'active',
                'approved',
                'validated',
                'paid',
                'generated',
                'published',
            ].includes(status),
            '!bg-rose-100 !text-rose-800': [
                'failed',
                'cancelled',
                'terminated',
                'reversed',
            ].includes(status),
            '!bg-amber-100 !text-amber-800': [
                'partial',
                'draft',
                'pending',
                'scheduled',
            ].includes(status),
        }"
        >{{ status }}</span
    >
</template>
